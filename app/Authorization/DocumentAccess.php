<?php

namespace App\Authorization;

use App\Models\ConfidentialityClearance;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentSecurityProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use ValueError;

class DocumentAccess
{
    public function __construct(private AccessControl $roles, private MembershipAccess $memberships) {}

    public function allows(?User $user, mixed $resource, DocumentOperation|string $operation): bool
    {
        $operation = is_string($operation) ? DocumentOperation::tryFrom($operation) : $operation;
        $profile = $resource instanceof DocumentSecurityDescriptor ? $resource->resolve() : null;
        $actor = $user?->exists ? User::query()->find($user->getKey()) : null;
        $reason = $this->denialReason($actor, $profile, $operation);
        DocumentSecurityAudit::query()->create([
            'actor_id' => $actor?->id, 'profile_id' => $profile?->id, 'event' => $reason === null ? 'access.allowed' : 'access.denied',
            'operation' => $operation?->value, 'reason' => $reason,
        ]);

        return $reason === null;
    }

    public function authorize(?User $user, mixed $resource, DocumentOperation|string $operation): void
    {
        if (! $this->allows($user, $resource, $operation)) {
            throw new AuthorizationException('Document access denied.');
        }
    }

    private function denialReason(?User $user, ?DocumentSecurityProfile $profile, ?DocumentOperation $operation): ?string
    {
        if (! $operation || ! $profile) {
            return 'unsupported_resource';
        }
        if (! $user || ! $user->active) {
            return 'inactive_account';
        }
        try {
            $scope = $profile->documentScope();
            $classification = $profile->classification;
            if (! $profile->active || ! $scope->isActive() || ! $profile->resource_type instanceof DocumentResourceType) {
                return 'inactive_resource';
            }
        } catch (ValidationException|ValueError) {
            return 'invalid_resource';
        }
        if (! in_array($operation->permission(), $this->roles->grantedPermissions($user, $scope->authorizationScope()), true)) {
            return 'permission_denied';
        }
        if (! $this->memberships->matches($user, $scope->authorizationScope(), $scope->departmentId)) {
            return 'membership_denied';
        }
        if ($classification !== Confidentiality::Normal && ! ConfidentialityClearance::query()->where('user_id', $user->id)
            ->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)->where('department_id', $scope->departmentId)
            ->where('classification', $classification->value)->where('active', true)->whereNull('revoked_at')
            ->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) {
            return 'clearance_denied';
        }
        if (($profile->restricted || $classification === Confidentiality::HighlyConfidential) && ! $profile->accessGrants()
            ->where('user_id', $user->id)->where('operation', $operation->value)->where('active', true)->whereNull('revoked_at')
            ->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) {
            return 'restriction_denied';
        }

        return null;
    }
}
