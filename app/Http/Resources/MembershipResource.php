<?php

namespace App\Http\Resources;

use App\Authorization\AccessControl;
use App\Authorization\MembershipAccess;
use App\Models\AuthorizationScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipResource extends JsonResource
{
    private ?AuthorizationScope $administrationScope = null;

    public function forScope(AuthorizationScope $scope): static
    {
        $this->administrationScope = $scope;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $effective = app(MembershipAccess::class)->effective($this->resource);
        $scope = $this->administrationScope;
        $activatable = $this->user->active && $this->authorizationScope()->isActive()
            && ($this->department === null || $this->department->active);

        return [
            'id' => $this->id, 'user' => $this->user->only(['id', 'name', 'email']), 'membership_type' => $this->membership_type,
            'company' => $this->company->only(['id', 'name_en', 'name_lo']), 'site' => $this->site?->only(['id', 'name_en', 'name_lo']),
            'department' => $this->department?->only(['id', 'name_en', 'name_lo']), 'unit_key' => $this->unit_key,
            'active' => $this->active, 'effective' => $effective, 'revoked_at' => $this->revoked_at?->toISOString(),
            'status' => $this->revoked_at !== null ? 'revoked' : ($effective ? 'effective' : 'inactive'),
            'activatable' => $this->revoked_at === null && $activatable,
            'manageable' => $this->revoked_at === null && $this->user_id !== $request->user()->id
                && $scope instanceof AuthorizationScope && app(AccessControl::class)->allows($request->user(), 'memberships.manage', $scope),
        ];
    }
}
