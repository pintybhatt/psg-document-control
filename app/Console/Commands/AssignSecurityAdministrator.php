<?php

namespace App\Console\Commands;

use App\Authorization\AccessControl;
use App\Authorization\ManageRoles;
use App\Authorization\PermissionCatalog;
use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Signature('rbac:bootstrap-admin {--email=} {--scope=} {--company=} {--site=} {--operator=} {--confirm-global}')]
#[Description('Assign the initial security administrator to an existing active account in an explicitly selected scope')]
class AssignSecurityAdministrator extends Command
{
    public function handle(RbacMutation $mutation, AccessControl $access, ManageRoles $manager): int
    {
        $data = [
            'email' => Str::lower(trim((string) $this->option('email'))), 'scope' => $this->option('scope'),
            'company' => $this->option('company'), 'site' => $this->option('site'), 'operator' => $this->option('operator'),
        ];
        if (Validator::make($data, [
            'email' => ['required', 'email'], 'scope' => ['required', Rule::in(['global', 'company', 'site'])],
            'company' => ['nullable', 'integer', 'min:1'], 'site' => ['nullable', 'integer', 'min:1'],
            'operator' => ['required', 'string', 'max:255'],
        ])->fails()) {
            $this->error('Supply a verified email, explicit scope, matching organization IDs and an operator label.');

            return self::FAILURE;
        }
        if ($data['scope'] === 'global' && ! $this->option('confirm-global')) {
            $this->error('Group-wide access requires --scope=global --confirm-global.');

            return self::FAILURE;
        }
        try {
            $scope = AuthorizationScope::context($data['scope'], $data['company'] ? (int) $data['company'] : null, $data['site'] ? (int) $data['site'] : null);
            $mutation->run(function () use ($data, $scope, $access, $manager): void {
                $user = User::query()->where('email', $data['email'])->where('active', true)->first();
                $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->where('active', true)->first();
                if (! $user || ! $role || ! $scope->isActive() || ! $role->scope->covers($scope)
                    || array_diff(PermissionCatalog::ADMINISTRATOR_KEYS, $role->permissions()->where('active', true)->pluck('key')->all()) !== []
                    || array_diff($role->permissions()->pluck('key')->all(), PermissionCatalog::ADMINISTRATOR_KEYS) !== []) {
                    throw ValidationException::withMessages(['bootstrap' => 'Initialize definitions and verify the active account, role permissions and scope first.']);
                }
                $scope->persist();
                $assignment = UserRoleAssignment::query()->firstOrNew(['user_id' => $user->id, 'role_id' => $role->id, 'scope_id' => $scope->id]);
                if ($assignment->exists) {
                    if (! $assignment->active) {
                        throw ValidationException::withMessages(['bootstrap' => 'A revoked assignment cannot be restored by bootstrap. Use authorized administration.']);
                    }
                    $this->info('The assignment already exists. Nothing was changed.');

                    return;
                }
                if ($access->administrators($scope) !== []) {
                    throw ValidationException::withMessages(['bootstrap' => 'Bootstrap is closed for this scope. Use authorized administration.']);
                }
                $assignment->active = true;
                $assignment->save();
                $manager->audit(null, 'administrator.bootstrapped', $assignment, $scope, null, $assignment->getAttributes() + ['operator' => $data['operator']]);
            });
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first());

            return self::FAILURE;
        }
        $this->info('Security administrator scope verified. No document or confidential-access capability was granted.');

        return self::SUCCESS;
    }
}
