<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Department;
use App\Models\RbacAudit;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ManageOrganization
{
    public function __construct(private AccessControl $access, private RbacMutation $mutation) {}

    public function authorize(User $actor): void
    {
        abort_unless($this->access->isSystemAdministrator($actor), 403, 'rbacForbidden');
    }

    /** @return Builder<Company>|Builder<Site>|Builder<Department> */
    public function query(string $kind): Builder
    {
        return match ($kind) {
            'companies' => Company::query(),
            'sites' => Site::query(),
            'departments' => Department::query(),
        };
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, string $kind, array $data, ?string $id = null): Model
    {
        $this->authorize($actor);
        try {
            return $this->mutation->run(function () use ($actor, $kind, $data, $id): Model {
                $this->authorize($actor);
                $record = $id === null ? $this->query($kind)->getModel()->newInstance() : $this->query($kind)->whereKey($id)->lockForUpdate()->firstOrFail();
                $creating = ! $record->exists;
                $before = $creating ? null : $this->snapshot($record);
                $required = $creating ? 'required' : 'sometimes';
                $rules = [
                    'code' => $creating ? ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'] : ['missing'],
                    'name_en' => [$required, 'required', 'string', 'max:255'],
                    'name_lo' => [$required, 'required', 'string', 'max:255'],
                    'active' => [$required, 'boolean'],
                    'company_id' => $creating && $kind !== 'companies' ? ['required', 'integer'] : ['missing'],
                    'site_id' => $creating && $kind === 'departments' ? ['required', 'integer'] : ['missing'],
                ];
                $values = Validator::make($data, $rules, array_fill_keys(['required', 'string', 'max', 'regex', 'integer', 'boolean', 'missing'], 'orgInvalid'))->validate();
                $record->fill($values);
                if ($kind !== 'companies') {
                    $company = Company::query()->whereKey($record->company_id)->lockForUpdate()->first();
                    $site = $kind === 'departments' ? Site::query()->whereKey($record->site_id)->where('company_id', $record->company_id)->lockForUpdate()->first() : null;
                    if (! $company || ($kind === 'departments' && ! $site)) {
                        throw ValidationException::withMessages(['company_id' => 'orgInvalidParent']);
                    }
                    if ($record->active && (! $company->active || ($site && ! $site->active))) {
                        throw ValidationException::withMessages(['active' => 'orgInactiveParent']);
                    }
                }
                if ($creating) {
                    $unique = Rule::unique($record->getTable(), 'code');
                    if ($kind === 'sites') {
                        $unique->where('company_id', $record->company_id);
                    } elseif ($kind === 'departments') {
                        $unique->where('site_id', $record->site_id);
                    }
                    Validator::make($values, ['code' => [$unique]], ['unique' => 'orgDuplicate'])->validate();
                } elseif ($record->isDirty('active') && ! $record->active && $this->hasDependencies($kind, $record)) {
                    throw ValidationException::withMessages(['active' => 'orgInUse']);
                }
                if (! $creating && ! $record->isDirty()) {
                    return $record;
                }
                if (! $record->save()) {
                    throw new LogicException('Organization persistence was cancelled.');
                }
                $audit = RbacAudit::query()->create([
                    'actor_id' => $actor->id, 'source' => 'web', 'action' => $creating ? 'organization.created' : 'organization.updated',
                    'subject_type' => match ($kind) {
                        'companies' => 'company', 'sites' => 'site', 'departments' => 'department'
                    },
                    'subject_id' => $record->id, 'scope_id' => AuthorizationScope::context('global')->id,
                    'before' => $before, 'after' => $this->snapshot($record),
                ]);
                if (! $audit->exists) {
                    throw new LogicException('Organization audit persistence was cancelled.');
                }

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'orgDuplicate']);
        }
    }

    /**
     * Permanently deletes a department that nothing references. Departments used by
     * memberships, security profiles, clearances, incoming operations or numbering
     * mappings must be deactivated instead, so their history stays intact.
     */
    public function deleteDepartment(User $actor, string $id): void
    {
        $this->authorize($actor);
        try {
            $this->mutation->run(function () use ($actor, $id): void {
                $this->authorize($actor);
                $record = Department::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($this->departmentIsReferenced($record)) {
                    throw ValidationException::withMessages(['department' => 'orgDeleteInUse']);
                }
                $before = $this->snapshot($record);
                if (! $record->delete()) {
                    throw new LogicException('Organization deletion was cancelled.');
                }
                $audit = RbacAudit::query()->create([
                    'actor_id' => $actor->id, 'source' => 'web', 'action' => 'organization.deleted',
                    'subject_type' => 'department', 'subject_id' => $before['id'],
                    'scope_id' => AuthorizationScope::context('global')->id,
                    'before' => $before, 'after' => null,
                ]);
                if (! $audit->exists) {
                    throw new LogicException('Organization audit persistence was cancelled.');
                }
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1451 && ! str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed')) {
                throw $exception;
            }

            /** A foreign key outside the explicit checks still protects a reference. */
            throw ValidationException::withMessages(['department' => 'orgDeleteInUse']);
        }
    }

    private function departmentIsReferenced(Department $record): bool
    {
        foreach (['organization_memberships', 'document_security_profiles', 'confidentiality_clearances', 'xppl_numbering_mappings'] as $table) {
            if (DB::table($table)->where('department_id', $record->id)->exists()) {
                return true;
            }
        }

        return DB::table('incoming_correspondence_operations')->where('responsible_department_id', $record->id)->exists();
    }

    /**
     * Deactivation never deletes or cascades, so historical references (inactive
     * memberships, authorization scopes, documents, storage) remain intact and do
     * not block it. Only active children and active memberships block it.
     */
    private function hasDependencies(string $kind, Model $record): bool
    {
        $column = match ($kind) {
            'companies' => 'company_id', 'sites' => 'site_id', 'departments' => 'department_id'
        };
        if (DB::table('organization_memberships')->where($column, $record->id)->where('active', true)->exists()) {
            return true;
        }

        return match ($kind) {
            'companies' => Site::query()->where('company_id', $record->id)->where('active', true)->exists(),
            'sites' => Department::query()->where('site_id', $record->id)->where('active', true)->exists(),
            'departments' => false,
        };
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $record): array
    {
        return $record->only(['id', 'code', 'name_en', 'name_lo', 'active', 'company_id', 'site_id']);
    }
}
