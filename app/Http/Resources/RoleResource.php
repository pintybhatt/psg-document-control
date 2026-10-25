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
        return [
            'id' => $this->id, 'code' => $this->code, 'name_en' => $this->name_en, 'name_lo' => $this->name_lo,
            'description' => $this->description, 'active' => $this->active, 'scope_key' => $this->scope->scope_key,
            'permission_keys' => $this->permissions->pluck('key')->all(),
            'security_editable' => ! $this->assignments()->where('user_id', $request->user()->id)->exists(),
            'editable' => $this->scope->scope_key === $this->administrationScope?->scope_key
                && app(AccessControl::class)->allows($request->user(), 'roles.manage', $this->scope)
                && array_diff($this->permissions->pluck('key')->all(), app(AccessControl::class)->grantedPermissions($request->user(), $this->scope)) === [],
        ];
    }
}
