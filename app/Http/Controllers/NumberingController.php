<?php

namespace App\Http\Controllers;

use App\Authorization\ManageNumbering;
use App\Authorization\NumberingFormat;
use App\Authorization\NumberingResetPolicy;
use App\Authorization\XpplNumbering;
use App\Http\Requests\NumberingRequest;
use App\Http\Resources\NumberingSeriesResource;
use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\NumberingSeries;
use App\Models\RegisterConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NumberingController extends Controller
{
    public function xpplOptions(NumberingRequest $request): JsonResponse
    {
        $scope = $request->scopeContext();
        abort_unless($scope->scope_type === 'company', 422, 'numberingXpplCompany');
        $scopes = AuthorizationScope::query()->where('company_id', $scope->company_id)->where('scope_type', 'site')->select('id');

        return response()->json([
            'departments' => Department::query()->where('company_id', $scope->company_id)->where('active', true)->orderBy('id')->get(['id', 'site_id', 'code', 'name_en', 'name_lo']),
            'types' => DocumentType::query()->with('scope')->whereIn('scope_id', $scopes)->where('active', true)->orderBy('id')->get()
                ->map(fn (DocumentType $type): array => $type->only(['id', 'scope_id', 'code', 'name_en', 'name_lo']) + ['site_id' => $type->scope->site_id]),
            'series' => NumberingSeries::query()->whereIn('scope_id', $scopes)->with('scope.site', 'register')->orderBy('id')->get()->map(fn (NumberingSeries $series): array => [
                'id' => $series->id, 'scope_id' => $series->scope_id, 'site_id' => $series->scope->site_id, 'site_name_en' => $series->scope->site?->name_en, 'site_name_lo' => $series->scope->site?->name_lo, 'register' => $series->register->register_key, 'purpose' => $series->purpose]),
            'mappings' => DB::table('xppl_numbering_mappings as m')->join('xppl_numbering_namespaces as n', 'n.id', '=', 'm.namespace_id')->where('n.company_id', $scope->company_id)
                ->get(['n.prefix', 'm.series_id', 'm.department_id', 'm.type_id', 'm.confirmed_by', 'm.created_at']),
            'history' => DB::table('xppl_numbering_counters as c')->join('xppl_numbering_namespaces as n', 'n.id', '=', 'c.namespace_id')->where('n.company_id', $scope->company_id)
                ->get(['n.prefix', 'n.format', 'c.period', 'c.baseline', 'c.value', 'c.confirmed_by', 'c.confirmed_at']),
        ]);
    }

    public function xpplReview(NumberingRequest $request, XpplNumbering $manager): JsonResponse
    {
        return response()->json($manager->review($request->user(), $request->scopeContext(), $request->validated()));
    }

    public function xpplConfirm(NumberingRequest $request, XpplNumbering $manager): JsonResponse
    {
        return response()->json($manager->review($request->user(), $request->scopeContext(), $request->validated(), true));
    }

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
