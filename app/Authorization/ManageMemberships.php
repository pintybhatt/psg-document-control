<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\MembershipAudit;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ManageMemberships
{
    public function __construct(private AccessControl $access, private MembershipAccess $memberships, private RbacMutation $mutation) {}

    /** @param array{user_id: int, unit_type: string, unit_company_id: int, unit_site_id?: ?int, unit_department_id?: ?int, confirm_regrant?: bool} $data */
    public function assign(User $actor, AuthorizationScope $scope, array $data): OrganizationMembership
    {
        return $this->mutation->run(function () use ($actor, $scope, $data): OrganizationMembership {
            $this->access->authorize($actor, 'memberships.manage', $scope);
            $this->access->authorize($actor, 'users.view', $scope);
            abort_if($actor->id === (int) $data['user_id'], 403, 'membershipSelfChange');
            abort_unless($this->memberships->eligibleUsers($actor, $scope)->whereKey($data['user_id'])->exists(), 403, 'rbacForbidden');
            $membership = new OrganizationMembership([
                'user_id' => $data['user_id'], 'membership_type' => $data['unit_type'], 'company_id' => $data['unit_company_id'],
                'site_id' => $data['unit_site_id'] ?? null, 'department_id' => $data['unit_department_id'] ?? null, 'active' => true,
            ]);
            $this->memberships->validateUnit($membership);
            abort_unless($scope->covers($membership->authorizationScope()), 403, 'rbacForbidden');
            $this->requireActiveUnit($membership);
            $existing = OrganizationMembership::query()->where($membership->only(['user_id', 'membership_type', 'company_id', 'site_id', 'department_id']))->get();
            if ($existing->contains(fn (OrganizationMembership $record): bool => $record->revoked_at === null)) {
                throw ValidationException::withMessages(['membership' => 'membershipDuplicate']);
            }
            if ($existing->isNotEmpty() && ! ($data['confirm_regrant'] ?? false)) {
                throw ValidationException::withMessages(['confirm_regrant' => 'membershipConfirmRegrant']);
            }
            $membership->created_by = $actor->id;
            $membership->updated_by = $actor->id;
            $membership->save();
            $this->audit($actor, $membership->refresh(), 'assigned', null);

            return $membership->load(['user', 'company', 'site', 'department']);
        });
    }

    public function update(User $actor, AuthorizationScope $scope, OrganizationMembership $membership, bool $active): OrganizationMembership
    {
        return $this->mutation->run(function () use ($actor, $scope, $membership, $active): OrganizationMembership {
            $this->authorizeChange($actor, $scope, $membership);
            if ($active) {
                $this->requireActiveUnit($membership);
                abort_unless(User::query()->whereKey($membership->user_id)->where('active', true)->exists(), 403, 'rbacInactiveTarget');
            }
            if ($membership->active !== $active) {
                $before = $membership->getAttributes();
                $membership->active = $active;
                $membership->updated_by = $actor->id;
                $membership->save();
                $this->audit($actor, $membership->refresh(), 'updated', $before);
            }

            return $membership->load(['user', 'company', 'site', 'department']);
        });
    }

    public function revoke(User $actor, AuthorizationScope $scope, OrganizationMembership $membership): void
    {
        $this->mutation->run(function () use ($actor, $scope, $membership): void {
            $this->authorizeChange($actor, $scope, $membership);
            $before = $membership->getAttributes();
            $membership->active = false;
            $membership->updated_by = $actor->id;
            $membership->revoked_by = $actor->id;
            $membership->revoked_at = now();
            $membership->save();
            $this->audit($actor, $membership->refresh(), 'revoked', $before);
        });
    }

    private function authorizeChange(User $actor, AuthorizationScope $scope, OrganizationMembership $membership): void
    {
        $this->access->authorize($actor, 'memberships.manage', $scope);
        $membership->refresh();
        abort_unless($this->memberships->visible($scope)->whereKey($membership->id)->exists(), 403, 'rbacForbidden');
        abort_if($actor->id === $membership->user_id, 403, 'membershipSelfChange');
        if ($membership->revoked_at !== null) {
            throw ValidationException::withMessages(['membership' => 'membershipImmutable']);
        }
    }

    private function requireActiveUnit(OrganizationMembership $membership): void
    {
        abort_unless($membership->authorizationScope()->isActive()
            && ($membership->department_id === null || $membership->department()->where('active', true)->exists()), 403, 'rbacInactiveTarget');
    }

    /** @param array<string, mixed>|null $before */
    private function audit(User $actor, OrganizationMembership $membership, string $action, ?array $before): void
    {
        MembershipAudit::query()->create(['membership_id' => $membership->id, 'actor_id' => $actor->id, 'target_user_id' => $membership->user_id,
            'action' => $action, 'before' => $before, 'after' => $membership->getAttributes()]);
    }
}
