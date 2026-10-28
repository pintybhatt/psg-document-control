<?php

namespace App\Http\Controllers;

use App\Authorization\ManageNumbering;
use App\Authorization\NumberingFormat;
use App\Authorization\NumberingResetPolicy;
use App\Http\Requests\NumberingRequest;
use App\Http\Resources\NumberingSeriesResource;
use App\Models\NumberingSeries;
use App\Models\RegisterConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NumberingController extends Controller
{
    public function index(NumberingRequest $request): AnonymousResourceCollection
    {
        return NumberingSeriesResource::collection(NumberingSeries::query()->where('scope_id', $request->scopeContext()->id)
            ->with(['scope', 'register', 'currentConfiguration'])->orderBy('id')->paginate($request->integer('per_page', 10)));
    }

    public function registers(NumberingRequest $request): JsonResponse
    {
        return response()->json(['data' => RegisterConfiguration::query()->where('scope_id', $request->scopeContext()->id)->orderBy('id')->get(['id', 'register_key', 'active'])]);
    }

    public function store(NumberingRequest $request, ManageNumbering $manager): NumberingSeriesResource
    {
        return new NumberingSeriesResource($manager->save($request->user(), $request->scopeContext(), $request->validated()));
    }

    public function update(NumberingRequest $request, string $series, ManageNumbering $manager): NumberingSeriesResource
    {
        return new NumberingSeriesResource($manager->save($request->user(), $request->scopeContext(), $request->validated(), $series));
    }

    public function preview(NumberingRequest $request): JsonResponse
    {
        if ($request->has('reset_policy')) {
            NumberingFormat::validateResetPolicy($request->validated('format'), NumberingResetPolicy::from($request->validated('reset_policy')));
        }

        return response()->json(['number' => NumberingFormat::render($request->validated('prefix'), $request->integer('padding'), $request->integer('year'), 1, $request->validated('format'))]);
    }

    public function history(NumberingRequest $request, string $series): JsonResponse
    {
        $record = NumberingSeries::query()->where('scope_id', $request->scopeContext()->id)->whereKey($series)->first();
        abort_if(! $record, 404);

        return response()->json($record->configurations()->orderByDesc('version')->paginate($request->integer('per_page', 10), ['id', 'version', 'prefix', 'padding', 'format', 'active', 'change_reason', 'effective_at', 'created_by']));
    }
}
