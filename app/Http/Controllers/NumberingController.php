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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        if ($request->has('series_id')) {
            $series = NumberingSeries::query()->where('scope_id', $request->scopeContext()->id)
                ->whereKey($request->integer('series_id'))->with('currentConfiguration')->first();
            abort_if(! $series || ! $series->currentConfiguration, 404);
            $year = (int) now()->format('Y');
            $period = $series->reset_policy === NumberingResetPolicy::Annual ? $year : 0;
            $value = (int) DB::table('numbering_counters')->where('series_id', $series->id)->where('period', $period)->value('value');
            if ($value >= NumberingFormat::MAX_SEQUENCE) {
                throw ValidationException::withMessages(['series_id' => 'numberingExhausted']);
            }
            $configuration = $series->currentConfiguration;

            return response()->json(['number' => NumberingFormat::render($configuration->prefix, $configuration->padding, $year, $value + 1)]);
        }

        return response()->json(['number' => NumberingFormat::render($request->validated('prefix'), $request->integer('padding'), $request->integer('year'), 1)]);
    }

    public function history(NumberingRequest $request, string $series): JsonResponse
    {
        $record = NumberingSeries::query()->where('scope_id', $request->scopeContext()->id)->whereKey($series)->first();
        abort_if(! $record, 404);

        return response()->json($record->configurations()->orderByDesc('version')->paginate($request->integer('per_page', 10), ['id', 'version', 'prefix', 'padding', 'active', 'change_reason', 'effective_at', 'created_by']));
    }
}
