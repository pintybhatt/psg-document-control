<?php

namespace App\Http\Requests;

class AssignRoleRequest extends RbacScopeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'user_id' => ['required', 'integer', 'exists:users,id'], 'role_id' => ['required', 'integer', 'exists:roles,id'],
            'confirm_global' => ['sometimes', 'boolean'],
        ];
    }
}
