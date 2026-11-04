<?php

namespace App\Authorization;

use App\Models\ConfidentialityClearance;
use App\Models\Department;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ConfidentialityAdministration
{
    public function __construct(private AccessControl $access, private MembershipAccess $memberships) {}

    /** @return list<string> */
    public function permissions(User $actor, DocumentScope $scope): array
    {
        return array_values(array_intersect(['clearances.confidential.manage', 'clearances.highly-confidential.manage', 'document-access.manage'],
            $this->access->permissions($actor, $scope->authorizationScope(), $scope->departmentId)));
    }

    /** @return Collection<int, DocumentScope> */
    public function contexts(User $actor): Collection
    {
        if ($this->access->isSystemAdministrator($actor)) {
            $sites = Site::query()->where('active', true)->whereHas('company', fn (Builder $query): Builder => $query->where('active', true))->get();
            $contexts = $sites->map(fn (Site $site): DocumentScope => new DocumentScope($site->company_id, $site->id));
            foreach (Department::query()->whereIn('site_id', $sites->modelKeys())->where('active', true)->get() as $department) {
                $contexts->push(new DocumentScope($department->company_id, $department->site_id, $department->id));
            }
        } else {
            $contexts = $this->memberships->effectiveQuery()->where('user_id', $actor->id)->whereNotNull('site_id')->get()
                ->map(fn (OrganizationMembership $membership): DocumentScope => new DocumentScope($membership->company_id, $membership->site_id, $membership->department_id));
        }

        return $contexts->filter(fn (DocumentScope $scope): bool => array_intersect(['clearances.confidential.manage', 'clearances.highly-confidential.manage'], $this->permissions($actor, $scope)) !== []
            || $this->canManageDocuments($actor, $scope))->values();
    }

    public function canManageDocuments(User $actor, DocumentScope $scope): bool
    {
        return $this->access->allows($actor, 'document-access.manage', $scope->authorizationScope(), $scope->departmentId)
            && in_array('documents.metadata.view', $this->access->grantedPermissions($actor, $scope->authorizationScope()), true);
    }

    public function authorize(User $actor, DocumentScope $scope, ?string $permission = null): void
    {
        $permissions = $this->permissions($actor, $scope);
        abort_unless($scope->isActive() && ($permission === null ? $permissions !== [] : in_array($permission, $permissions, true)), 403, 'documentSecurityForbidden');
    }

    /** @return Builder<User> */
    public function users(User $actor, DocumentScope $scope): Builder
    {
        $this->authorize($actor, $scope);
        $members = $this->memberships->effectiveQuery()->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)
            ->where('department_id', $scope->departmentId)->select('user_id');

        return User::query()->where('active', true)->where('id', '!=', $actor->id)->whereIn('id', $members);
    }

    /** @return Builder<ConfidentialityClearance> */
    public function clearances(User $actor, DocumentScope $scope): Builder
    {
        $this->authorize($actor, $scope);
        $levels = array_filter([Confidentiality::Confidential, Confidentiality::HighlyConfidential],
            fn (Confidentiality $level): bool => in_array($level->managementPermission(), $this->permissions($actor, $scope), true));

        return ConfidentialityClearance::query()->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)
            ->where('department_id', $scope->departmentId)->whereIn('classification', array_map(fn (Confidentiality $level): string => $level->value, $levels));
    }
}
