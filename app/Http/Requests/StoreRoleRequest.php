<?php

namespace App\Http\Requests;

class StoreRoleRequest extends RbacScopeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'code' => $this->route('role') ? ['prohibited'] : ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Z0-9_-]{1,63}$/', 'unique:roles,code'],
            'name_en' => ['required', 'string', 'max:255'], 'name_lo' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'], 'active' => ['required', 'boolean'],
            'permission_keys' => ['present', 'array', 'max:50'], 'permission_keys.*' => ['string', 'distinct', 'max:100'],
        ];
    }
}
