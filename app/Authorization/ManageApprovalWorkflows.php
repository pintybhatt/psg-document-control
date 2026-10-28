<?php

namespace App\Authorization;

use App\Models\ApprovalWorkflowConfiguration;
use App\Models\AuthorizationScope;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ManageApprovalWorkflows
{
    public const REGISTERS = ['controlled', 'incoming', 'outgoing'];

    public function __construct(private AccessControl $access) {}

    public function authorize(User $actor, AuthorizationScope $scope, bool $write = false): void
    {
        abort_unless(in_array($scope->scope_type, ['company', 'site'], true), 403, 'rbacForbidden');
        $this->access->authorize($actor, 'master-data.view', $scope);
        if ($write) {
            $this->access->authorize($actor, 'master-data.manage', $scope);
            $this->access->authorize($actor, 'roles.view', $scope);
        }
    }

    /** @return list<array{id: int, code: string, scope_key: string, name_en: string, name_lo: string}> */
    public function roles(User $actor, AuthorizationScope $scope): array
    {
        $this->authorize($actor, $scope, true);

        return $this->access->visibleRoles($scope)->where('active', true)->with(['scope', 'permissions'])->orderBy('code')->get()
            ->filter(fn (Role $role): bool => $this->eligibleRole($actor, $scope, $role))
            ->map(fn (Role $role): array => $this->roleSnapshot($role))->values()->all();
    }

    private function eligibleRole(User $actor, AuthorizationScope $scope, Role $role): bool
    {
        return $role->active && $role->scope->isActive() && $role->scope->covers($scope)
            && $this->access->canManageRole($actor, $role->code, $scope)
            && $role->permissions->every(fn (Permission $permission): bool => $permission->active && PermissionCatalog::available($permission->key))
            && array_diff($role->permissions->pluck('key')->all(), $this->access->grantedPermissions($actor, $scope)) === [];
    }

    /** @return array{id: int, code: string, scope_key: string, name_en: string, name_lo: string} */
    private function roleSnapshot(Role $role): array
    {
        return ['id' => $role->id, 'code' => $role->code, 'scope_key' => $role->scope->scope_key,
            'name_en' => $role->displayName('en'), 'name_lo' => $role->displayName('lo')];
    }

    /**
     * Configuration only. Future submissions must pin an immutable configuration ID;
     * existing approval evidence has no workflow binding and is never migrated here.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, AuthorizationScope $scope, string $register, array $data): ApprovalWorkflowConfiguration
    {
        try {
            return DB::transaction(function () use ($actor, $scope, $register, $data): ApprovalWorkflowConfiguration {
                $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);
                $this->authorize($actor, $scope, true);
                $scope->persist();
                if (DB::getDriverName() === 'sqlite') {
                    DB::table('authorization_scopes')->where('id', $scope->id)->update(['id' => DB::raw('id')]);
                }
                DB::table('authorization_scopes')->where('id', $scope->id)->lockForUpdate()->first();
                $this->authorize($actor, $scope, true);
                abort_unless(in_array($register, self::REGISTERS, true), 404);
                if (array_diff(array_keys($data), ['expected_version', 'enabled', 'sequence', 'role_ids', 'change_reason']) !== []) {
                    throw ValidationException::withMessages(['workflow' => 'workflowValidation']);
                }
                $values = Validator::make($data, [
                    'expected_version' => ['required', 'integer', 'min:0'], 'enabled' => ['required', 'boolean'],
                    'sequence' => ['required', Rule::in(['sequential'])],
                    'role_ids' => ['present', 'array', 'list', 'max:3', Rule::when((bool) ($data['enabled'] ?? false), ['min:1'])],
                    'role_ids.*' => ['required', 'integer', 'min:1', 'distinct'], 'change_reason' => ['required', 'string', 'max:500'],
                ], array_fill_keys(['required', 'integer', 'min', 'boolean', 'in', 'present', 'array', 'list', 'max', 'distinct', 'string'], 'workflowValidation'))->validate();
                $current = ApprovalWorkflowConfiguration::query()->where('scope_id', $scope->id)->where('register_key', $register)->latest('version')->lockForUpdate()->first();
                if ((int) $values['expected_version'] !== ($current?->version ?? 0)) {
                    throw ValidationException::withMessages(['expected_version' => 'workflowStale']);
                }
                $levels = [];
                foreach ($values['role_ids'] as $index => $roleId) {
                    $role = $this->access->visibleRoles($scope)->with(['scope', 'permissions'])->lockForUpdate()->find($roleId);
                    if (! $role || ! $this->eligibleRole($actor, $scope, $role)) {
                        throw ValidationException::withMessages(['role_ids.'.$index => 'workflowInvalidRole']);
                    }
                    $levels[] = ['level' => $index + 1, 'role' => $this->roleSnapshot($role)];
                }
                $configuration = new ApprovalWorkflowConfiguration(['scope_id' => $scope->id, 'register_key' => $register,
                    'version' => ($current?->version ?? 0) + 1, 'enabled' => $values['enabled'], 'sequence' => 'sequential',
                    'levels' => $levels, 'change_reason' => $values['change_reason'], 'created_by' => $actor->id]);
                if (! $configuration->save()) {
                    throw new LogicException('Approval workflow history persistence cancelled.');
                }

                return $configuration;
            }, 1);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['expected_version' => 'workflowStale']);
        }
    }
}
