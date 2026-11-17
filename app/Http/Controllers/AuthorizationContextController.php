<?php

namespace App\Http\Controllers;

use App\Authorization\AccessControl;
use App\Authorization\ConfidentialityAdministration;
use App\Authorization\DocumentScope;
use App\Authorization\MembershipAccess;
use App\Http\Requests\RbacScopeRequest;
use App\Models\AuthorizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorizationContextController extends Controller
{
    public function index(Request $request, AccessControl $access): JsonResponse
    {
        $contexts = $request->boolean('administration') ? $access->administrationContexts($request->user()) : $access->contexts($request->user());

        $securitySites = $request->boolean('administration') ? app(ConfidentialityAdministration::class)->contexts($request->user())
            ->map(fn (DocumentScope $scope): int => $scope->siteId)->all() : [];

        return response()->json(['data' => $contexts->map(function (AuthorizationScope $scope) use ($request, $access, $securitySites): array {
            return [
                'scope_key' => $scope->scope_key, 'scope_type' => $scope->scope_type, 'company_id' => $scope->company_id, 'site_id' => $scope->site_id,
                'company' => $scope->company?->only(['name_en', 'name_lo']), 'site' => $scope->site?->only(['name_en', 'name_lo']),
                'permissions' => $access->permissions($request->user(), $scope),
                'can_access_templates' => $scope->scope_type === 'site' && $access->allows($request->user(), 'templates.view', $scope)
                    && app(MembershipAccess::class)->matches($request->user(), $scope),
                'can_manage_document_security' => $scope->scope_type === 'site' && in_array($scope->site_id, $securitySites, true),
            ];
        })] + ($access->isSystemAdministrator($request->user()) ? ['can_manage_organization' => true] : []));
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
