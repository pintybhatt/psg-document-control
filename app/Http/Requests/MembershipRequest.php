<?php

namespace App\Http\Requests;

use App\Authorization\AccessControl;
use App\Models\AuthorizationScope;
use Illuminate\Validation\Rule;

class MembershipRequest extends RbacScopeRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        return ! in_array($this->route()->getName(), ['api.v1.memberships.store', 'api.v1.membership-users'], true)
            || app(AccessControl::class)->allows($this->user(), 'users.view', $this->scopeContextFromInput());
    }

    private function scopeContextFromInput(): AuthorizationScope
    {
        return AuthorizationScope::context($this->input('scope_type'), $this->filled('company_id') ? (int) $this->input('company_id') : null,
            $this->filled('site_id') ? (int) $this->input('site_id') : null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        $route = $this->route()->getName();
        if ($route === 'api.v1.memberships.store' || $route === 'api.v1.membership-units') {
            $rules += ['unit_type' => ['required', Rule::in(['company', 'site', 'department'])],
                'unit_company_id' => [$route === 'api.v1.memberships.store' ? 'required' : 'nullable', 'integer', 'min:1', 'exists:companies,id'],
                'unit_site_id' => ['nullable', 'integer', 'min:1', 'exists:sites,id'],
                'unit_department_id' => ['nullable', 'integer', 'min:1', 'exists:departments,id']];
        }
        if ($route === 'api.v1.memberships.store') {
            $rules += ['user_id' => ['required', 'integer', 'exists:users,id'], 'confirm_regrant' => ['sometimes', 'boolean']];
        }
        if ($route === 'api.v1.memberships.update') {
            $rules += ['active' => ['required', 'boolean']];
        }
        foreach (['actor_id', 'created_by', 'updated_by', 'revoked_by', 'revoked_at', 'current_key', 'unit_key', 'membership_type'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        if ($route === 'api.v1.memberships.update') {
            foreach (['user_id', 'unit_type', 'unit_company_id', 'unit_site_id', 'unit_department_id', 'confirm_regrant'] as $field) {
                $rules[$field] = ['prohibited'];
            }
        }

        return $rules;
    }
}
