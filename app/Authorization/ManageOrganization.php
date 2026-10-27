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

    private function hasDependencies(string $kind, Model $record): bool
    {
        $column = match ($kind) {
            'companies' => 'company_id', 'sites' => 'site_id', 'departments' => 'department_id'
        };
        foreach (['organization_memberships', 'document_security_profiles', 'confidentiality_clearances'] as $table) {
            if (DB::table($table)->where($column, $record->id)->exists()) {
                return true;
            }
        }
        if ($kind === 'departments') {
            return AuthorizationScope::query()->where('site_id', $record->site_id)->exists()
                || DB::table('incoming_correspondence_operations')->where('responsible_department_id', $record->id)->exists();
        }

        return AuthorizationScope::query()->where($column, $record->id)->exists()
            || DB::table('storage_locations')->where($column, $record->id)->exists()
            || ($kind === 'companies' ? Site::query()->where('company_id', $record->id)->exists()
                : Department::query()->where('site_id', $record->id)->exists() || DB::table('physical_copies')->where('site_id', $record->id)->exists());
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $record): array
    {
        return $record->only(['id', 'code', 'name_en', 'name_lo', 'active', 'company_id', 'site_id']);
    }
}
