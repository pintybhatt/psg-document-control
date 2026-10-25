<?php

namespace App\Http\Controllers;

use App\Authorization\AccessControl;
use App\Authorization\ManageRoles;
use App\Authorization\PermissionCatalog;
use App\Http\Requests\RbacScopeRequest;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController extends Controller
{
    public function index(RbacScopeRequest $request, AccessControl $access): AnonymousResourceCollection
    {
        $scope = $request->scopeContext();
        $access->authorize($request->user(), 'roles.view', $scope);
        $request->attributes->set('rbac_scope_key', $scope->scope_key);

        $query = $access->visibleRoles($scope)->with(['permissions', 'scope']);
        if ($request->filled('q')) {
            $term = '%'.$request->validated('q').'%';
            $query->where(fn ($query) => $query->where('code', 'like', $term)->orWhere('name_en', 'like', $term)->orWhere('name_lo', 'like', $term));
        }

        return RoleResource::collection($query->orderBy('code')->paginate($request->integer('per_page', 10)));
    }

    public function show(RbacScopeRequest $request, Role $role, AccessControl $access): RoleResource
    {
        $scope = $request->scopeContext();
        $access->authorize($request->user(), 'roles.view', $scope);
        abort_unless($role->scope->covers($scope), 403, 'rbacForbidden');
        $request->attributes->set('rbac_scope_key', $scope->scope_key);

        return new RoleResource($role->load(['permissions', 'scope']));
    }

    public function store(StoreRoleRequest $request, ManageRoles $manager): RoleResource
    {
        $scope = $request->scopeContext();
        $role = $manager->saveRole($request->user(), $scope, $request->validated());
        $request->attributes->set('rbac_scope_key', $scope->scope_key);

        return new RoleResource($role);
    }

    public function update(StoreRoleRequest $request, Role $role, ManageRoles $manager): RoleResource
    {
        $scope = $request->scopeContext();
        $role = $manager->saveRole($request->user(), $scope, $request->validated(), $role);
        $request->attributes->set('rbac_scope_key', $scope->scope_key);

        return new RoleResource($role);
    }

    public function permissions(RbacScopeRequest $request, AccessControl $access): JsonResponse
    {
        $scope = $request->scopeContext();
        $access->authorize($request->user(), 'roles.view', $scope);
        $held = $access->permissions($request->user(), $scope);
        $permissions = Permission::query()->whereIn('key', array_keys(PermissionCatalog::definitions()))->orderBy('area')->orderBy('key')->get()
            ->map(fn (Permission $permission): array => [
                'key' => $permission->key, 'area' => $permission->area, 'name_en' => $permission->name_en, 'name_lo' => $permission->name_lo,
                'available' => $permission->active && PermissionCatalog::available($permission->key), 'delegatable' => in_array($permission->key, $held, true),
            ]);

        return response()->json(['data' => $permissions]);
    }
}
