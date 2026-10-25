<?php

namespace App\Http\Resources;

use App\Authorization\AccessControl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'user' => ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email],
            'role' => ['id' => $this->role->id, 'code' => $this->role->code, 'name_en' => $this->role->name_en, 'name_lo' => $this->role->name_lo],
            'scope_key' => $this->scope->scope_key, 'active' => $this->active,
            'revocable' => $this->user_id !== $request->user()->id
                && app(AccessControl::class)->allows($request->user(), 'assignments.manage', $this->scope)
                && array_diff($this->role->permissions()->pluck('key')->all(), app(AccessControl::class)->permissions($request->user(), $this->scope)) === [],
        ];
    }
}
