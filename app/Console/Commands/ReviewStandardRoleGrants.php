<?php

namespace App\Console\Commands;

use App\Authorization\MembershipAccess;
use App\Authorization\PermissionCatalog;
use App\Authorization\RbacMutation;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\RbacAudit;
use App\Models\Role;
use App\Models\UserRoleAssignment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('rbac:standard-grants {role : Standard role code} {--apply= : Fingerprint from the reviewed report} {--operator= : Approved operator label}')]
#[Description('Report current standard role grants and assignments, or add the reviewed missing defaults')]
class ReviewStandardRoleGrants extends Command
{
    public function handle(RbacMutation $mutation): int
    {
        $code = (string) $this->argument('role');
        if (! in_array($code, Role::STANDARD_CODES, true)) {
            $this->error('Choose one of: '.implode(', ', Role::STANDARD_CODES));

            return self::FAILURE;
        }

        $fingerprint = $this->option('apply');
        if ($fingerprint === null) {
            $role = $this->findRole($code);
            if (! $role) {
                return self::FAILURE;
            }

            $report = $this->report($role);
            $this->output->writeln(json_encode($report + ['fingerprint' => $this->fingerprint($report)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $operator = trim((string) $this->option('operator'));
        if ($code === Role::SYSTEM_ADMINISTRATOR_CODE || $operator === '' || mb_strlen($operator) > 255 || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            $this->error('Apply requires an operational standard role, its reviewed fingerprint and an operator label.');

            return self::FAILURE;
        }

        if (! $this->findRole($code)) {
            return self::FAILURE;
        }

        return $mutation->run(function () use ($code, $operator, $fingerprint): int {
            $role = $this->findRole($code);
            if (! $role) {
                return self::FAILURE;
            }

            $report = $this->report($role);
            if (! $role->active || $report['unavailable_defaults'] !== []) {
                $this->error('The role and all default catalog permissions must be active before applying grants.');

                return self::FAILURE;
            }
            if ($report['add'] === []) {
                $this->info('All default grants are already present.');

                return self::SUCCESS;
            }
            if (! hash_equals($this->fingerprint($report), $fingerprint)) {
                $this->error('The report has changed. Review a new report before applying grants.');

                return self::FAILURE;
            }

            $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', $report['add'])->pluck('id')->all());
            RbacAudit::query()->create([
                'actor_id' => null, 'source' => 'cli', 'action' => 'role.standard-grants-applied',
                'subject_type' => 'role', 'subject_id' => $role->id, 'scope_id' => $role->scope_id,
                'before' => ['permission_keys' => array_column($report['current_grants'], 'key')],
                'after' => ['permission_keys' => $role->permissions()->orderBy('key')->pluck('key')->all(), 'operator' => $operator, 'reviewed_fingerprint' => $fingerprint],
            ]);
            $this->info('Added reviewed grants: '.implode(', ', $report['add']));

            return self::SUCCESS;
        });
    }

    private function findRole(string $code): ?Role
    {
        $role = Role::query()->where('code', $code)->with('scope')->first();
        if ($role?->scope->scope_key !== 'global') {
            $this->error('The standard global role is missing. Review the role definition before continuing.');

            return null;
        }

        return $role;
    }

    /**
     * @return array{environment: string, role: array<string, mixed>, current_grants: list<array<string, mixed>>, defaults: list<string>, add: list<string>, unavailable_defaults: list<string>, assignments: list<array<string, mixed>>}
     */
    private function report(Role $role): array
    {
        $defaults = PermissionCatalog::standardRoleDefaults($role->code);
        $grants = $role->permissions()->orderBy('key')->get()->map(fn (Permission $permission): array => [
            'key' => $permission->key, 'active' => $permission->active, 'available' => PermissionCatalog::available($permission->key),
        ])->all();
        $activeDefaults = Permission::query()->whereIn('key', $defaults)->where('active', true)->pluck('key')->all();
        $roleAssignments = $role->assignments()->with(['scope', 'user.memberships'])->orderBy('id')->get();
        $effectiveMemberships = app(MembershipAccess::class)->effectiveQuery()->whereIn('user_id', $roleAssignments->pluck('user_id')->unique())
            ->pluck('id')->flip();
        $assignments = $roleAssignments->map(fn (UserRoleAssignment $assignment): array => [
            'id' => $assignment->id, 'active' => $assignment->active, 'scope' => $assignment->scope->scope_key,
            'company_id' => $assignment->scope->company_id, 'site_id' => $assignment->scope->site_id,
            'scope_active' => $assignment->scope->isActive(),
            'user' => $assignment->user->only(['id', 'name', 'email', 'active']),
            'memberships' => $assignment->user->memberships->sortBy('id')->values()->map(fn (OrganizationMembership $membership): array => [
                'type' => $membership->membership_type, 'company_id' => $membership->company_id,
                'site_id' => $membership->site_id, 'department_id' => $membership->department_id,
                'effective' => $effectiveMemberships->has($membership->id),
            ])->all(),
        ])->all();

        return [
            'environment' => app()->environment(),
            'role' => $role->only(['id', 'code', 'name_en', 'name_lo', 'description', 'active']) + ['scope' => $role->scope->scope_key],
            'current_grants' => $grants, 'defaults' => $defaults,
            'add' => $role->code === Role::SYSTEM_ADMINISTRATOR_CODE ? [] : array_values(array_diff($defaults, array_column($grants, 'key'))),
            'unavailable_defaults' => array_values(array_diff($defaults, $activeDefaults)),
            'assignments' => $assignments,
        ];
    }

    /** @param array<string, mixed> $report */
    private function fingerprint(array $report): string
    {
        return hash('sha256', json_encode($report, JSON_THROW_ON_ERROR));
    }
}
