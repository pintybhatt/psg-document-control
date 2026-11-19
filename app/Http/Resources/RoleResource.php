<?php

namespace App\Http\Resources;

use App\Authorization\AccessControl;
use App\Models\AuthorizationScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
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
        $canManageRole = app(AccessControl::class)->canManageRole($request->user(), $this->code, $this->administrationScope ?? $this->scope);
        $standard = $this->resource->isStandard();

        return [
            'id' => $this->id, 'code' => $this->code, 'name_en' => $this->resource->displayName('en'), 'name_lo' => $this->resource->displayName('lo'),
            'description' => $this->resource->displayDescription(), 'active' => $this->active, 'scope_key' => $this->scope->scope_key,
            'permission_keys' => $this->permissions->pluck('key')->all(),
            'standard' => $standard,
            'security_editable' => ! $standard && ! $this->assignments()->where('user_id', $request->user()->id)->exists(),
            'assignable' => $canManageRole,
            'editable' => ! $standard && $canManageRole && $this->scope->scope_key === $this->administrationScope?->scope_key
                && app(AccessControl::class)->allows($request->user(), 'roles.manage', $this->scope)
                && array_diff($this->permissions->pluck('key')->all(), app(AccessControl::class)->grantedPermissions($request->user(), $this->scope)) === [],
        ];
    }
}
