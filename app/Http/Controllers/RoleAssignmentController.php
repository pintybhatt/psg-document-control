<?php

namespace App\Http\Controllers;

use App\Authorization\AccessControl;
use App\Authorization\ManageRoles;
use App\Http\Requests\AssignRoleRequest;
use App\Http\Requests\RbacScopeRequest;
use App\Http\Resources\RoleAssignmentResource;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RoleAssignmentController extends Controller
{
    public function index(RbacScopeRequest $request, AccessControl $access): AnonymousResourceCollection
    {
        $scope = $request->scopeContext();
        $access->authorize($request->user(), 'assignments.view', $scope);

        return RoleAssignmentResource::collection(UserRoleAssignment::query()->whereHas('scope', fn ($query) => $query->where('scope_key', $scope->scope_key))
            ->with(['user', 'role', 'scope'])->orderByDesc('id')->paginate($request->integer('per_page', 10)));
    }

    public function store(AssignRoleRequest $request, ManageRoles $manager): RoleAssignmentResource
    {
        $assignment = $manager->assign($request->user(), $request->scopeContext(), User::query()->findOrFail($request->validated('user_id')), Role::query()->findOrFail($request->validated('role_id')), $request->boolean('confirm_global'));

        return new RoleAssignmentResource($assignment);
    }

    public function destroy(RbacScopeRequest $request, UserRoleAssignment $assignment, ManageRoles $manager): Response
    {
        $manager->revoke($request->user(), $request->scopeContext(), $assignment);

        return response()->noContent();
    }
}
