<?php

namespace App\Http\Requests;

use App\Authorization\AccessControl;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class AssignRoleRequest extends RbacScopeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $access = app(AccessControl::class);
        $scope = $this->scopeForValidation();

        return parent::rules() + [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query->whereIn('id', $access->eligibleUsers($this->user(), $scope)->select('users.id')))],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn (Builder $query): Builder => $query->whereIn('id', $access->visibleRoles($scope)->select('roles.id')))],
            'confirm_global' => ['sometimes', 'boolean'],
        ];
    }
}
