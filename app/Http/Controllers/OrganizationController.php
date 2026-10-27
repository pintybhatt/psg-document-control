<?php

namespace App\Http\Controllers;

use App\Authorization\ManageOrganization;
use App\Http\Requests\OrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Site;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    public function index(OrganizationRequest $request, ManageOrganization $organization): AnonymousResourceCollection
    {
        $kind = $request->route('kind');
        $query = $organization->query($kind);
        if ($kind !== 'companies') {
            $query->where('company_id', $request->integer('company_id'));
        }
        if ($kind === 'departments') {
            abort_unless(Site::query()->whereKey($request->integer('site_id'))->where('company_id', $request->integer('company_id'))->exists(), 404);
            $query->where('site_id', $request->integer('site_id'));
        }

        return OrganizationResource::collection($query->orderBy('code')->orderBy('id')->paginate($request->integer('per_page', 10)));
    }

    public function store(OrganizationRequest $request, ManageOrganization $organization): OrganizationResource
    {
        return new OrganizationResource($organization->save($request->user(), $request->route('kind'), $request->validated()));
    }

    public function update(OrganizationRequest $request, string $kind, string $record, ManageOrganization $organization): OrganizationResource
    {
        return new OrganizationResource($organization->save($request->user(), $kind, $request->validated(), $record));
    }
}
