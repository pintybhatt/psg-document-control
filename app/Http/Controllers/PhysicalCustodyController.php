<?php

namespace App\Http\Controllers;

use App\Authorization\DocumentStorageTarget;
use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManagePhysicalCustody;
use App\Http\Requests\DocumentStorageRequest;
use App\Models\PhysicalCopy;
use App\Models\StorageEvent;
use App\Models\StorageLocation;
use Illuminate\Http\JsonResponse;

class PhysicalCustodyController extends Controller
{
    public function copies(DocumentStorageRequest $request, DocumentStorageTarget $targets, ManageControlledDocuments $people): JsonResponse
    {
        $record = $targets->find($request->user(), $request->documentScope(), $request->route('type'), $request->route('record'));
        $revisionId = $request->filled('revision_id') ? $request->integer('revision_id') : null;
        $targets->revision($record, $revisionId);
        $copies = PhysicalCopy::query()->where('profile_id', $record->profile_id)->where('revision_id', $revisionId)->with(['location', 'custodian'])->orderBy('identifier')->orderBy('id')->get();
        $history = StorageEvent::query()->where('profile_id', $record->profile_id)->whereIn('subject_id', $copies->pluck('id'))->orderBy('id')->get();
        $scope = $request->documentScope();

        return response()->json(['data' => $copies->map(fn (PhysicalCopy $copy): array => $this->serialize($copy)),
            'history' => $history->map(fn (StorageEvent $event): array => $event->only(['id', 'action', 'subject_id', 'reason', 'actor_id', 'before', 'after']) + ['created_at' => $event->created_at->toISOString()]),
            'locations' => StorageLocation::query()->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)->orderBy('room')->orderBy('id')->get()->map(fn (StorageLocation $location): array => ['id' => $location->id, 'label' => $location->label()]),
            'people' => $people->people($scope)->orderBy('name')->orderBy('id')->get(['id', 'name'])]);
    }

    public function createLocation(DocumentStorageRequest $request, ManagePhysicalCustody $custody): JsonResponse
    {
        $location = $custody->location($request->user(), $request->documentScope(), $request->route('type'), $request->route('record'), $request->only(['room', 'cabinet', 'shelf', 'box']));

        return response()->json(['data' => ['id' => $location->id, 'label' => $location->label()]], 201);
    }

    public function createCopy(DocumentStorageRequest $request, ManagePhysicalCustody $custody): JsonResponse
    {
        $copy = $custody->create($request->user(), $request->documentScope(), $request->route('type'), $request->route('record'),
            $request->filled('revision_id') ? $request->integer('revision_id') : null, $request->only(['identifier', 'kind', 'location_id', 'reason']));

        return response()->json(['data' => $this->serialize($copy)], 201);
    }

    public function change(DocumentStorageRequest $request, ManagePhysicalCustody $custody): JsonResponse
    {
        $copy = $custody->change($request->user(), $request->documentScope(), $request->route('type'), $request->route('record'),
            $request->filled('revision_id') ? $request->integer('revision_id') : null, $request->route('copy'),
            $request->only(['action', 'lock_version', 'reason', 'location_id', 'custodian_id', 'checkout_date', 'expected_return_date', 'status', 'correction_event_id', 'observation']));

        return response()->json(['data' => $this->serialize($copy)]);
    }

    private function serialize(PhysicalCopy $copy): array
    {
        $copy->loadMissing(['location', 'custodian']);

        return $copy->only(['id', 'revision_id', 'identifier', 'kind', 'status', 'location_id', 'custodian_id', 'lock_version']) + [
            'location' => $copy->location->label(), 'custodian' => $copy->custodian?->name,
            'checkout_date' => $copy->checkout_date?->format('Y-m-d'), 'expected_return_date' => $copy->expected_return_date?->format('Y-m-d'), 'overdue' => $copy->overdue(),
        ];
    }
}
