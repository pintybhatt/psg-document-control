<?php

namespace App\Authorization;

use App\Models\ConfidentialityClearance;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentSecurityProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ManageDocumentSecurity
{
    public function __construct(private AccessControl $roles, private MembershipAccess $memberships, private RbacMutation $mutation) {}

    /**
     * Trusted module integration only: derive the reference and scope from its persisted resource.
     * Future modules must atomically bind this profile to that resource; this service validates organization ownership.
     */
    public function registerProfile(User $actor, DocumentResourceType $type, string $resourceKey, DocumentScope $scope, Confidentiality $classification, bool $restricted = false): DocumentSecurityDescriptor
    {
        return $this->mutation->run(function () use ($actor, $type, $resourceKey, $scope, $classification, $restricted): DocumentSecurityDescriptor {
            $this->authorizeManagement($actor, 'document-access.manage', $scope);
            $profile = new DocumentSecurityProfile(['resource_type' => $type, 'resource_key' => $resourceKey,
                'company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'department_id' => $scope->departmentId,
                'classification' => $classification, 'restricted' => $restricted || $classification === Confidentiality::HighlyConfidential, 'active' => true]);
            $profile->created_by = $actor->id;
            $profile->updated_by = $actor->id;
            $profile->save();
            $this->audit($actor, $profile->refresh(), 'profile.registered', null);

            return DocumentSecurityDescriptor::fromProfile($profile);
        });
    }

    public function grantClearance(User $actor, User $target, DocumentScope $scope, Confidentiality $classification, ?CarbonImmutable $expiresAt = null): ConfidentialityClearance
    {
        return $this->mutation->run(function () use ($actor, $target, $scope, $classification, $expiresAt): ConfidentialityClearance {
            if ($classification === Confidentiality::Normal) {
                $this->invalid();
            }
            $this->authorizeManagement($actor, $classification->managementPermission(), $scope, $target->id);
            $this->requireMembership($target, $scope);
            $this->validateExpiry($expiresAt);
            $query = ConfidentialityClearance::query()->where('user_id', $target->id)->where('company_id', $scope->companyId)
                ->where('site_id', $scope->siteId)->where('department_id', $scope->departmentId)->where('classification', $classification->value)->whereNull('revoked_at');
            if ($query->exists()) {
                $this->invalid();
            }
            $clearance = new ConfidentialityClearance(['user_id' => $target->id, 'company_id' => $scope->companyId,
                'site_id' => $scope->siteId, 'department_id' => $scope->departmentId, 'classification' => $classification, 'active' => true, 'expires_at' => $expiresAt]);
            $clearance->created_by = $actor->id;
            $clearance->updated_by = $actor->id;
            $clearance->save();
            $this->audit($actor, $clearance->refresh(), 'clearance.granted', null);

            return $clearance;
        });
    }

    public function allowDocument(User $actor, User $target, DocumentSecurityDescriptor $resource, DocumentOperation $operation, ?CarbonImmutable $expiresAt = null): DocumentAccessGrant
    {
        return $this->mutation->run(function () use ($actor, $target, $resource, $operation, $expiresAt): DocumentAccessGrant {
            $profile = $resource->resolve();
            if (! $profile || ! $profile->active || ! $profile->restricted) {
                $this->invalid();
            }
            $this->authorizeManagement($actor, 'document-access.manage', $profile->documentScope(), $target->id);
            $this->requireMembership($target, $profile->documentScope());
            $this->validateExpiry($expiresAt);
            if ($profile->accessGrants()->where('user_id', $target->id)->where('operation', $operation->value)->whereNull('revoked_at')->exists()) {
                $this->invalid();
            }
            $grant = new DocumentAccessGrant(['profile_id' => $profile->id, 'user_id' => $target->id, 'operation' => $operation, 'active' => true, 'expires_at' => $expiresAt]);
            $grant->created_by = $actor->id;
            $grant->updated_by = $actor->id;
            $grant->save();
            $this->audit($actor, $grant->refresh(), 'grant.granted', null);

            return $grant;
        });
    }

    public function updateGrant(User $actor, ConfidentialityClearance|DocumentAccessGrant $grant, bool $active, ?CarbonImmutable $expiresAt = null): void
    {
        $this->changeGrant($actor, $grant, $active, $expiresAt, false);
    }

    public function revoke(User $actor, ConfidentialityClearance|DocumentAccessGrant $grant): void
    {
        $this->changeGrant($actor, $grant, false, null, true);
    }

    private function changeGrant(User $actor, ConfidentialityClearance|DocumentAccessGrant $grant, bool $active, ?CarbonImmutable $expiresAt, bool $revoke): void
    {
        $this->mutation->run(function () use ($actor, $grant, $active, $expiresAt, $revoke): void {
            $grant->refresh();
            $scope = $grant instanceof ConfidentialityClearance ? $grant->documentScope() : $grant->profile->documentScope();
            $permission = $grant instanceof ConfidentialityClearance ? $grant->classification->managementPermission() : 'document-access.manage';
            $this->authorizeManagement($actor, $permission, $scope, $grant->user_id);
            if ($grant->revoked_at !== null) {
                $this->invalid();
            }
            if ($active) {
                $this->requireMembership($grant->user, $scope);
                $this->validateExpiry($expiresAt);
            }
            $before = $grant->getAttributes();
            $grant->active = $active;
            $grant->updated_by = $actor->id;
            if ($revoke) {
                $grant->revoked_at = now();
                $grant->revoked_by = $actor->id;
            } else {
                $grant->expires_at = $expiresAt;
            }
            $grant->save();
            $event = ($grant instanceof ConfidentialityClearance ? 'clearance.' : 'grant.').($revoke ? 'revoked' : 'updated');
            $this->audit($actor, $grant->refresh(), $event, $before);
        });
    }

    public function requireAllowlist(User $actor, DocumentSecurityDescriptor $resource): void
    {
        $this->mutation->run(function () use ($actor, $resource): void {
            $profile = $resource->resolve();
            if (! $profile) {
                $this->invalid();
            }
            $this->authorizeManagement($actor, 'document-access.manage', $profile->documentScope());
            if (! $profile->restricted) {
                $before = $profile->getAttributes();
                $profile->restricted = true;
                $profile->updated_by = $actor->id;
                $profile->save();
                $this->audit($actor, $profile->refresh(), 'profile.restricted', $before);
            }
        });
    }

    private function authorizeManagement(User $actor, string $permission, DocumentScope $scope, ?int $targetId = null): void
    {
        abort_unless($scope->isActive() && $this->roles->allows($actor, $permission, $scope->authorizationScope(), $scope->departmentId), 403, 'documentSecurityForbidden');
        abort_if($targetId === $actor->id, 403, 'documentSecurityForbidden');
    }

    private function requireMembership(User $target, DocumentScope $scope): void
    {
        abort_unless($this->memberships->matches($target, $scope->authorizationScope(), $scope->departmentId), 403, 'documentSecurityForbidden');
    }

    private function validateExpiry(?CarbonImmutable $expiresAt): void
    {
        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo(now())) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['security' => 'documentSecurityInvalid']);
    }

    /** @param array<string, mixed>|null $before */
    private function audit(User $actor, DocumentSecurityProfile|ConfidentialityClearance|DocumentAccessGrant $subject, string $event, ?array $before): void
    {
        DocumentSecurityAudit::query()->create(['actor_id' => $actor->id, 'target_user_id' => $subject instanceof DocumentSecurityProfile ? null : $subject->user_id,
            'profile_id' => $subject instanceof DocumentSecurityProfile ? $subject->id : ($subject instanceof DocumentAccessGrant ? $subject->profile_id : null),
            'clearance_id' => $subject instanceof ConfidentialityClearance ? $subject->id : null, 'grant_id' => $subject instanceof DocumentAccessGrant ? $subject->id : null,
            'event' => $event, 'before' => $before, 'after' => $subject->getAttributes()]);
    }
}
