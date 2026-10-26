<?php

namespace App\Http\Controllers;

use App\Authorization\AccessControl;
use App\Authorization\DocumentScope;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\MembershipAccess;
use App\Http\Requests\IncomingCorrespondenceRequest;
use App\Http\Resources\IncomingCorrespondenceResource;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\IncomingCorrespondenceAudit;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomingCorrespondenceController extends Controller
{
    public function contexts(Request $request, AccessControl $roles): JsonResponse
    {
        $contexts = [];
        foreach (Site::query()->where('active', true)->with('company')->get() as $site) {
            $departments = Department::query()->where('site_id', $site->id)->where('active', true)->get();
            foreach ([null, ...$departments->all()] as $department) {
                $scope = new DocumentScope($site->company_id, $site->id, $department?->id);
                $permissions = $roles->permissions($request->user(), $scope->authorizationScope(), $department?->id);
                if (! in_array('documents.metadata.view', $roles->grantedPermissions($request->user(), $scope->authorizationScope()), true)
                    || (! $roles->isSystemAdministrator($request->user()) && ! app(MembershipAccess::class)->matches($request->user(), $scope->authorizationScope(), $department?->id))) {
                    continue;
                }
                if (! $roles->allows($request->user(), 'numbering.issue', $scope->authorizationScope())) {
                    $permissions = array_values(array_diff($permissions, ['numbering.issue']));
                }
                $contexts[] = ['key' => $site->id.':'.($department?->id ?? 'site'), 'company_id' => $site->company_id, 'site_id' => $site->id,
                    'department_id' => $department?->id, 'company' => $site->company->only(['id', 'name_en', 'name_lo']),
                    'site' => $site->only(['id', 'name_en', 'name_lo']), 'department' => $department?->only(['id', 'name_en', 'name_lo']),
                    'permissions' => $permissions];
            }
        }

        return response()->json(['data' => $contexts]);
    }

    public function index(IncomingCorrespondenceRequest $request, ManageIncomingCorrespondence $incoming): JsonResponse
    {
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 10);
        $rows = [];
        $total = 0;
        // Evaluate the canonical document policy before counting or serializing metadata.
        foreach ($incoming->query($request->documentScope())->with(['profile', 'type.category'])->orderByDesc('id')->lazy() as $record) {
            if ($incoming->canRead($request->user(), $record)) {
                if ($total >= ($page - 1) * $perPage && count($rows) < $perPage) {
                    $rows[] = $record;
                }
                $total++;
            }
        }

        return response()->json(['data' => IncomingCorrespondenceResource::collection($rows), 'meta' => ['total' => $total, 'current_page' => $page, 'per_page' => $perPage]]);
    }

    public function lookups(IncomingCorrespondenceRequest $request): JsonResponse
    {
        $scope = $request->documentScope()->authorizationScope();
        $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', 'incoming')->first();
        $types = DocumentType::query()->where('scope_id', $scope->id)->with('category')->get()
            ->filter(fn (DocumentType $type): bool => $register && $type->effectiveFor($register))
            ->map(fn (DocumentType $type): array => $type->only(['id', 'name_en', 'name_lo', 'category_id']) + ['category' => $type->category->only(['id', 'name_en', 'name_lo'])])->values();

        return response()->json(['data' => $types]);
    }

    public function show(IncomingCorrespondenceRequest $request, string $record, ManageIncomingCorrespondence $incoming): JsonResponse
    {
        $model = $incoming->find($request->user(), $request->documentScope(), $record);

        return response()->json(['data' => new IncomingCorrespondenceResource($model), 'audits' => IncomingCorrespondenceAudit::query()->where('correspondence_id', $model->id)->orderBy('id')->get(['id', 'actor_id', 'action', 'changed_fields', 'created_at'])]);
    }

    public function store(IncomingCorrespondenceRequest $request, ManageIncomingCorrespondence $incoming): IncomingCorrespondenceResource
    {
        return new IncomingCorrespondenceResource($incoming->save($request->user(), $request->documentScope(), $request->except(['company_id', 'site_id', 'department_id'])));
    }

    public function update(IncomingCorrespondenceRequest $request, string $record, ManageIncomingCorrespondence $incoming): IncomingCorrespondenceResource
    {
        return new IncomingCorrespondenceResource($incoming->save($request->user(), $request->documentScope(), $request->except(['company_id', 'site_id', 'department_id']), $record));
    }

    public function register(IncomingCorrespondenceRequest $request, string $record, ManageIncomingCorrespondence $incoming): IncomingCorrespondenceResource
    {
        return new IncomingCorrespondenceResource($incoming->register($request->user(), $request->documentScope(), $record));
    }
}
