<?php

namespace App\Console\Commands;

use App\Authorization\AccessControl;
use App\Authorization\PermissionCatalog;
use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\MembershipAudit;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\RbacAudit;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;

#[Signature('dev:setup-phase3-demo {--operator= : Operator responsible for this local fixture}')]
#[Description('Local SQLite only: give admin@dcms.test Phase 3 Administration access in synthetic DEMO scopes')]
class SetupPhaseThreeDemo extends Command
{
    private const PERMISSIONS = ['master-data.view', 'master-data.manage', 'numbering.view', 'numbering.manage'];

    private const ROLE_CODE = 'DEMO_PHASE3_ADMINISTRATOR';

    private const ROLE_DESCRIPTION = 'Local Phase 3 demo fixture; configuration only, no issuance or document access.';

    /** Trusted local operator bootstrap; web delegation and self-change policies remain unchanged. */
    public function handle(RbacMutation $mutation, AccessControl $access): int
    {
        if (! app()->runningInConsole() || ! app()->environment('local') || DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('This fixture requires the local environment and SQLite.');

            return self::FAILURE;
        }
        $operator = trim((string) $this->option('operator'));
        if ($operator === '' || mb_strlen($operator) > 255) {
            $this->error('Supply --operator with a name of at most 255 characters.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('document_categories') || ! Schema::hasTable('numbering_series')) {
            $this->error('Apply the existing Phase 3 migrations before running this fixture.');

            return self::FAILURE;
        }

        try {
            $mutation->run(function () use ($access, $operator): void {
                $user = User::query()->where('email', 'admin@dcms.test')->where('active', true)->first();
                if (! $user || array_diff(PermissionCatalog::ADMINISTRATOR_KEYS, $access->grantedPermissions($user, AuthorizationScope::context('global'))) !== []) {
                    $this->invalid('An existing active admin@dcms.test with global Security Administrator capabilities is required.');
                }
                $permissionIds = Permission::query()->whereIn('key', self::PERMISSIONS)->where('active', true)->pluck('id')->all();
                if (count($permissionIds) !== count(self::PERMISSIONS)) {
                    $this->invalid('Initialize and review the four active Phase 3 catalog permissions first.');
                }

                $company = Company::query()->firstOrCreate(['code' => 'DEMO'], [
                    'name_en' => 'DEMO synthetic development company', 'name_lo' => 'DEMO ບໍລິສັດທົດລອງ', 'active' => true,
                ]);
                if (! $company->exists || ! $company->active || $company->name_en !== 'DEMO synthetic development company') {
                    $this->invalid('The DEMO company conflicts with this fixture; nothing will be overwritten.');
                }
                $site = $company->sites()->firstOrCreate(['code' => 'DEMO-SITE'], [
                    'name_en' => 'DEMO-SITE synthetic development site', 'name_lo' => 'DEMO-SITE ສະຖານທີ່ທົດລອງ', 'active' => true,
                ]);
                if (! $site->exists || ! $site->active || $site->name_en !== 'DEMO-SITE synthetic development site') {
                    $this->invalid('The DEMO-SITE site conflicts with this fixture; nothing will be overwritten.');
                }
                $companyScope = AuthorizationScope::context('company', $company->id)->persist();
                $siteScope = AuthorizationScope::context('site', $company->id, $site->id)->persist();
                if ($company->wasRecentlyCreated) {
                    $this->audit('company', $company, $companyScope, $operator);
                }
                if ($site->wasRecentlyCreated) {
                    $this->audit('site', $site, $siteScope, $operator);
                }

                $role = Role::query()->firstOrNew(['code' => self::ROLE_CODE]);
                if ($role->exists) {
                    $keys = $role->permissions()->pluck('key')->all();
                    if ($role->scope_id !== $companyScope->id || ! $role->active || $role->description !== self::ROLE_DESCRIPTION
                        || count($keys) !== count(self::PERMISSIONS) || array_diff(self::PERMISSIONS, $keys) !== []) {
                        $this->invalid('The DEMO role conflicts with this fixture; nothing will be overwritten.');
                    }
                } else {
                    $role->fill(['scope_id' => $companyScope->id, 'name_en' => 'DEMO Phase 3 Administrator',
                        'name_lo' => 'DEMO ຜູ້ບໍລິຫານໄລຍະ 3', 'description' => self::ROLE_DESCRIPTION, 'active' => true]);
                    if (! $role->save()) {
                        throw new LogicException('Demo role persistence was cancelled.');
                    }
                    $role->permissions()->sync($permissionIds);
                    $this->audit('role', $role, $companyScope, $operator, ['permission_keys' => self::PERMISSIONS]);
                }
                foreach ([$companyScope, $siteScope] as $scope) {
                    $this->membership($user, $scope, $operator);
                }
                $assignment = UserRoleAssignment::query()->firstOrNew(['user_id' => $user->id, 'role_id' => $role->id, 'scope_id' => $companyScope->id]);
                if ($assignment->exists && ! $assignment->active) {
                    $this->invalid('A disabled DEMO assignment requires manual review; this fixture will not restore it.');
                }
                if (! $assignment->exists) {
                    $assignment->active = true;
                    if (! $assignment->save()) {
                        throw new LogicException('Demo assignment persistence was cancelled.');
                    }
                    $this->audit('assignment', $assignment, $companyScope, $operator);
                }
                foreach ([$companyScope, $siteScope] as $scope) {
                    foreach (self::PERMISSIONS as $permission) {
                        $access->authorize($user, $permission, $scope);
                    }
                }
            });
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first());

            return self::FAILURE;
        }

        $this->info('DEMO / DEMO-SITE Administration access is ready for admin@dcms.test. No numbering series or document privileges were created.');

        return self::SUCCESS;
    }

    private function membership(User $user, AuthorizationScope $scope, string $operator): void
    {
        $membership = OrganizationMembership::query()->firstOrNew(['user_id' => $user->id, 'membership_type' => $scope->scope_type,
            'company_id' => $scope->company_id, 'site_id' => $scope->site_id, 'department_id' => null]);
        if ($membership->exists) {
            if (! $membership->active || $membership->revoked_at !== null) {
                $this->invalid('An inactive or revoked DEMO membership requires manual review; this fixture will not restore it.');
            }

            return;
        }
        $membership->active = true;
        $membership->created_by = $user->id;
        $membership->updated_by = $user->id;
        if (! $membership->save()) {
            throw new LogicException('Demo membership persistence was cancelled.');
        }
        $audit = MembershipAudit::query()->create(['membership_id' => $membership->id, 'actor_id' => $user->id, 'target_user_id' => $user->id,
            'action' => 'assigned', 'before' => null, 'after' => $membership->getAttributes() + ['source' => 'cli', 'operator' => $operator, 'fixture' => 'phase3-demo']]);
        if (! $audit->exists) {
            throw new LogicException('Demo membership audit persistence was cancelled.');
        }
    }

    /** @param array<string, mixed> $extra */
    private function audit(string $type, Model $record, AuthorizationScope $scope, string $operator, array $extra = []): void
    {
        $audit = RbacAudit::query()->create(['actor_id' => null, 'source' => 'cli', 'action' => 'demo.'.$type.'.created',
            'subject_type' => $type, 'subject_id' => $record->getKey(), 'scope_id' => $scope->id, 'before' => null,
            'after' => $record->getAttributes() + $extra + ['operator' => $operator, 'fixture' => 'phase3-demo']]);
        if (! $audit->exists) {
            throw new LogicException('Demo audit persistence was cancelled.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['demo' => $message]);
    }
}
