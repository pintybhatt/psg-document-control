<?php

namespace App\Http\Controllers;

use App\Authorization\AccessControl;
use App\Authorization\ManageMemberships;
use App\Authorization\MembershipAccess;
use App\Http\Requests\MembershipRequest;
use App\Http\Resources\MembershipResource;
use App\Models\Company;
use App\Models\Department;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MembershipController extends Controller
{
    public function index(MembershipRequest $request, MembershipAccess $memberships): AnonymousResourceCollection
    {
        $scope = $request->scopeContext();
        $resources = MembershipResource::collection($memberships->visible($scope)->with(['user', 'company', 'site', 'department'])
            ->orderByDesc('id')->paginate($request->integer('per_page', 10)));
        $resources->collection->each(fn (MembershipResource $resource): MembershipResource => $resource->forScope($scope));

        return $resources;
    }

    public function store(MembershipRequest $request, ManageMemberships $manager): MembershipResource
    {
        $scope = $request->scopeContext();

        return (new MembershipResource($manager->assign($request->user(), $scope, $request->validated())))->forScope($scope);
    }

    public function update(MembershipRequest $request, string $membership, ManageMemberships $manager, MembershipAccess $memberships): MembershipResource
    {
        $scope = $request->scopeContext();
        $membership = $memberships->visible($scope)->whereKey($membership)->first();
        abort_if($membership === null, 404);

        return (new MembershipResource($manager->update($request->user(), $scope, $membership, $request->boolean('active'))))->forScope($scope);
    }

    public function destroy(MembershipRequest $request, string $membership, ManageMemberships $manager, MembershipAccess $memberships): Response
    {
        $scope = $request->scopeContext();
        $membership = $memberships->visible($scope)->whereKey($membership)->first();
        abort_if($membership === null, 404);
        $manager->revoke($request->user(), $scope, $membership);

        return response()->noContent();
    }

    public function users(MembershipRequest $request, MembershipAccess $memberships): JsonResponse
    {
        $query = $memberships->eligibleUsers($request->user(), $request->scopeContext())->where('id', '!=', $request->user()->id);
        if ($request->filled('q')) {
            $term = '%'.$request->validated('q').'%';
            $query->where(fn (Builder $query): Builder => $query->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        return response()->json($query->orderBy('name')->orderBy('id')->paginate($request->integer('per_page', 10), ['id', 'name', 'email']));
    }

    public function units(MembershipRequest $request): JsonResponse
    {
        $scope = $request->scopeContext();
        $type = $request->validated('unit_type');
        $query = match ($type) {
            'company' => Company::query()->when($scope->scope_type === 'site', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
                ->when($scope->company_id !== null, fn (Builder $query): Builder => $query->whereKey($scope->company_id)),
            'site' => Site::query()->whereHas('company', fn (Builder $query): Builder => $query->where('active', true))
                ->when($scope->company_id !== null, fn (Builder $query): Builder => $query->where('company_id', $scope->company_id))
                ->when($scope->site_id !== null, fn (Builder $query): Builder => $query->whereKey($scope->site_id)),
            'department' => Department::query()->whereHas('company', fn (Builder $query): Builder => $query->where('active', true))
                ->whereHas('site', fn (Builder $query): Builder => $query->where('active', true))
                ->when($scope->company_id !== null, fn (Builder $query): Builder => $query->where('company_id', $scope->company_id))
                ->when($scope->site_id !== null, fn (Builder $query): Builder => $query->where('site_id', $scope->site_id)),
        };
        $query->where('active', true);
        if ($request->filled('unit_company_id')) {
            $type === 'company' ? $query->whereKey($request->validated('unit_company_id')) : $query->where('company_id', $request->validated('unit_company_id'));
        }
        if ($request->filled('unit_site_id') && $type !== 'company') {
            $type === 'site' ? $query->whereKey($request->validated('unit_site_id')) : $query->where('site_id', $request->validated('unit_site_id'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->validated('q').'%';
            $query->where(fn (Builder $query): Builder => $query->where('name_en', 'like', $term)->orWhere('name_lo', 'like', $term)->orWhere('code', 'like', $term));
        }
        $units = $query->with(match ($type) {
            'company' => [], 'site' => ['company'], 'department' => ['company', 'site']
        })
            ->orderBy('name_en')->orderBy('id')->paginate($request->integer('per_page', 10));
        $units->through(fn (Model $unit): array => [
            'id' => $unit->id, 'unit_type' => $type, 'name_en' => $unit->name_en, 'name_lo' => $unit->name_lo,
            'unit_company_id' => $type === 'company' ? $unit->id : $unit->company_id,
            'unit_site_id' => $type === 'site' ? $unit->id : ($type === 'department' ? $unit->site_id : null),
            'unit_department_id' => $type === 'department' ? $unit->id : null,
            'company' => $type === 'company' ? $unit->only(['name_en', 'name_lo']) : $unit->company->only(['name_en', 'name_lo']),
            'site' => $type === 'department' ? $unit->site->only(['name_en', 'name_lo']) : null,
        ]);

        return response()->json($units);
    }

    public function operationalContexts(Request $request, AccessControl $access): JsonResponse
    {
        return response()->json(['data' => $access->operationalContexts($request->user())]);
    }
}
