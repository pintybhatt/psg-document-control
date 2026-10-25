<?php

namespace App\Http\Requests;

use App\Authorization\AccessControl;
use App\Models\AuthorizationScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RbacScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = match ($this->route()?->getName()) {
            'api.v1.master-data.index', 'api.v1.master-data.classifications' => 'master-data.view',
            'api.v1.master-data.store', 'api.v1.master-data.update' => 'master-data.manage',
            'api.v1.roles.index', 'api.v1.roles.show', 'api.v1.permissions' => 'roles.view',
            'api.v1.roles.store', 'api.v1.roles.update' => 'roles.manage',
            'api.v1.assignments.index' => 'assignments.view',
            'api.v1.assignments.store', 'api.v1.assignments.destroy' => 'assignments.manage',
            'api.v1.assignment-users' => 'users.view',
            'api.v1.memberships.index', 'api.v1.membership-units' => 'memberships.view',
            'api.v1.memberships.store', 'api.v1.memberships.update', 'api.v1.memberships.destroy', 'api.v1.membership-users' => 'memberships.manage',
            default => null,
        };
        if (! $permission || ! $this->user()) {
            return false;
        }
        if (Validator::make($this->only(['scope_type', 'company_id', 'site_id']), [
            'scope_type' => ['required', Rule::in(['global', 'company', 'site'])],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'site_id' => ['nullable', 'integer', 'min:1'],
        ])->fails()) {
            throw ValidationException::withMessages(['scope_type' => 'rbacInvalidScope']);
        }
        $scope = new AuthorizationScope([
            'scope_type' => $this->input('scope_type'),
            'company_id' => $this->filled('company_id') ? $this->integer('company_id') : null,
            'site_id' => $this->filled('site_id') ? $this->integer('site_id') : null,
        ]);
        $validShape = match ($scope->scope_type) {
            'global' => $scope->company_id === null && $scope->site_id === null,
            'company' => $scope->company_id !== null && $scope->site_id === null,
            'site' => $scope->company_id !== null && $scope->site_id !== null,
        };
        if (! $validShape) {
            throw ValidationException::withMessages(['scope_type' => 'rbacInvalidScope']);
        }

        $access = app(AccessControl::class);

        return $access->allows($this->user(), $permission, $scope)
            && ($this->route()->getName() !== 'api.v1.assignments.store' || $access->allows($this->user(), 'users.view', $scope));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope_type' => ['required', Rule::in(['global', 'company', 'site'])],
            'company_id' => ['nullable', 'integer', 'min:1', 'exists:companies,id'],
            'site_id' => ['nullable', 'integer', 'min:1', 'exists:sites,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function scopeContext(): AuthorizationScope
    {
        return AuthorizationScope::context($this->validated('scope_type'), $this->filled('company_id') ? (int) $this->validated('company_id') : null, $this->filled('site_id') ? (int) $this->validated('site_id') : null);
    }

    protected function scopeForValidation(): AuthorizationScope
    {
        return AuthorizationScope::context($this->input('scope_type'), $this->filled('company_id') ? $this->integer('company_id') : null,
            $this->filled('site_id') ? $this->integer('site_id') : null);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_fill_keys(['required', 'in', 'integer', 'min', 'max', 'exists', 'string', 'boolean', 'unique', 'regex', 'array', 'distinct', 'present', 'prohibited', 'accepted'], 'rbacValidation');
    }
}
