<?php

namespace App\Http\Controllers;

use App\Authorization\AccessControl;
use App\Http\Requests\RbacScopeRequest;
use App\Models\AuthorizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorizationContextController extends Controller
{
    public function index(Request $request, AccessControl $access): JsonResponse
    {
        return response()->json(['data' => $access->contexts($request->user())->map(function (AuthorizationScope $scope) use ($request, $access): array {
            return [
                'scope_key' => $scope->scope_key, 'scope_type' => $scope->scope_type, 'company_id' => $scope->company_id, 'site_id' => $scope->site_id,
                'company' => $scope->company?->only(['name_en', 'name_lo']), 'site' => $scope->site?->only(['name_en', 'name_lo']),
                'permissions' => $access->permissions($request->user(), $scope),
            ];
        })]);
    }

    public function users(RbacScopeRequest $request, AccessControl $access): JsonResponse
    {
        $scope = $request->scopeContext();
        $access->authorize($request->user(), 'users.view', $scope);
        $query = $access->eligibleUsers($request->user(), $scope)->where('id', '!=', $request->user()->id);
        if ($request->filled('q')) {
            $term = '%'.$request->validated('q').'%';
            $query->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }
        $users = $query->orderBy('name')->orderBy('id')->paginate($request->integer('per_page', 10), ['id', 'name', 'email']);

        return response()->json($users);
    }
}
