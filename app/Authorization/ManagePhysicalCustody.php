<?php

namespace App\Authorization;

use App\Models\PhysicalCopy;
use App\Models\StorageEvent;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ManagePhysicalCustody
{
    public function __construct(private DocumentStorageTarget $targets, private ManageControlledDocuments $people) {}

    /** @param array<string, mixed> $data */
    public function location(User $actor, DocumentScope $scope, string $type, string $id, array $data): StorageLocation
    {
        $this->targets->find($actor, $scope, $type, $id, 'storage-locations.manage');
        $validated = Validator::make($data, ['room' => ['required', 'string', 'max:100'], 'cabinet' => ['nullable', 'string', 'max:100', 'required_with:shelf'], 'shelf' => ['nullable', 'string', 'max:100', 'required_with:box'], 'box' => ['nullable', 'string', 'max:100']], $this->messages())->validate();
        $path = array_map(fn (string $field): ?string => isset($validated[$field]) && trim($validated[$field]) !== '' ? trim($validated[$field]) : null, ['room', 'cabinet', 'shelf', 'box']);
        $hash = hash('sha256', mb_strtolower(json_encode($path, JSON_THROW_ON_ERROR)));

        return DB::transaction(function () use ($actor, $scope, $type, $id, $path, $hash): StorageLocation {
            $record = $this->targets->find($actor, $scope, $type, $id, 'storage-locations.manage', true);
            $existing = StorageLocation::query()->where('site_id', $scope->siteId)->where('path_hash', $hash)->first();
            if ($existing) {
                return $existing;
            }
            $location = new StorageLocation(array_combine(['room', 'cabinet', 'shelf', 'box'], $path) + ['company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'path_hash' => $hash, 'created_by' => $actor->id]);
            $this->persist($location);
            $this->audit($actor, $record, 'location.created', (string) $location->id, 'Storage location created', null, $location->only(['room', 'cabinet', 'shelf', 'box']));

            return $location;
        });
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, DocumentScope $scope, string $type, string $id, ?int $revisionId, array $data): PhysicalCopy
    {
        $this->targets->find($actor, $scope, $type, $id, 'documents.custody.manage');
        $validated = Validator::make($data, ['identifier' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN][\pL\pN ._\/-]*$/u'], 'kind' => ['required', Rule::in(['original', 'copy'])], 'location_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000']], $this->messages())->validate();

        return DB::transaction(function () use ($actor, $scope, $type, $id, $revisionId, $validated): PhysicalCopy {
            $record = $this->targets->find($actor, $scope, $type, $id, 'documents.custody.manage', true);
            $this->targets->revision($record, $revisionId);
            $this->validLocation($scope, (int) $validated['location_id']);
            if (PhysicalCopy::query()->where('site_id', $scope->siteId)->where('identifier', $validated['identifier'])->exists()) {
                throw ValidationException::withMessages(['identifier' => 'storageDuplicateCopy']);
            }
            $copy = new PhysicalCopy(array_diff_key($validated, ['reason' => true]) + ['id' => (string) Str::uuid(), 'profile_id' => $record->profile_id, 'revision_id' => $revisionId, 'site_id' => $scope->siteId, 'status' => 'stored', 'lock_version' => 1]);
            $this->persist($copy);
            $this->audit($actor, $record, 'copy.created', $copy->id, $validated['reason'], null, $copy->getAttributes());

            return $copy;
        });
    }

    /** @param array<string, mixed> $data */
    public function change(User $actor, DocumentScope $scope, string $type, string $id, ?int $revisionId, string $copyId, array $data): PhysicalCopy
    {
        $this->targets->find($actor, $scope, $type, $id, 'documents.custody.manage');
        $validated = Validator::make($data, [
            'action' => ['required', Rule::in(['checkout', 'return', 'transfer', 'correct', 'inventory'])],
            'lock_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'],
            'custodian_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'],
            'checkout_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'expected_return_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(['stored', 'checked_out', 'missing'])], 'correction_event_id' => ['nullable', 'integer', 'required_if:action,correct'],
            'observation' => ['nullable', Rule::in(['verified', 'discrepancy']), 'required_if:action,inventory'],
        ], $this->messages())->validate();

        return DB::transaction(function () use ($actor, $scope, $type, $id, $revisionId, $copyId, $validated): PhysicalCopy {
            $record = $this->targets->find($actor, $scope, $type, $id, 'documents.custody.manage', true);
            $this->targets->revision($record, $revisionId);
            $copy = PhysicalCopy::query()->where('profile_id', $record->profile_id)->where('revision_id', $revisionId)->lockForUpdate()->findOrFail($copyId);
            if ($copy->lock_version !== (int) $validated['lock_version']) {
                throw ValidationException::withMessages(['storage' => 'storageStale']);
            }
            $before = $copy->getAttributes();
            $action = $validated['action'];
            if ($action === 'inventory') {
                $this->audit($actor, $record, 'custody.inventory', $copy->id, $validated['reason'], $before, $before + ['observation' => $validated['observation']]);

                return $copy;
            }
            if ($action === 'correct') {
                abort_unless(StorageEvent::query()->where('profile_id', $record->profile_id)->where('subject_id', $copyId)->whereIn('action', ['copy.created', 'custody.checkout', 'custody.return', 'custody.transfer', 'custody.correct', 'custody.inventory'])->whereKey($validated['correction_event_id'])->exists(), 404);
                if (! isset($validated['status'])) {
                    throw ValidationException::withMessages(['status' => 'storageValidation']);
                }
                $copy->status = $validated['status'];
            } elseif ($action === 'checkout') {
                $this->requireStatus($copy, 'stored');
                $copy->status = 'checked_out';
            } elseif ($action === 'return') {
                $this->requireStatus($copy, 'checked_out');
                $copy->status = 'stored';
            } elseif ($copy->status === 'missing') {
                throw ValidationException::withMessages(['storage' => 'storageCustodyConflict']);
            }
            if ($copy->status === 'checked_out') {
                $custodian = (int) ($validated['custodian_id'] ?? 0);
                if (! $this->people->people($scope)->whereKey($custodian)->exists()) {
                    throw ValidationException::withMessages(['custodian_id' => 'storageInvalidCustodian']);
                }
                $checkout = $action === 'transfer' ? $copy->checkout_date->format('Y-m-d') : ($validated['checkout_date'] ?? null);
                Validator::make(['checkout_date' => $checkout, 'expected_return_date' => $validated['expected_return_date'] ?? null], ['checkout_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'expected_return_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:checkout_date']], $this->messages())->validate();
                $copy->fill(['custodian_id' => $custodian, 'checkout_date' => $checkout, 'expected_return_date' => $validated['expected_return_date']]);
            } else {
                $copy->fill(['custodian_id' => null, 'checkout_date' => null, 'expected_return_date' => null]);
            }
            if (isset($validated['location_id'])) {
                $this->validLocation($scope, (int) $validated['location_id']);
                $copy->location_id = $validated['location_id'];
            } elseif ($action === 'transfer' && $copy->status === 'stored') {
                throw ValidationException::withMessages(['location_id' => 'storageValidation']);
            }
            $copy->lock_version++;
            $this->persist($copy);
            $after = $copy->getAttributes();
            if ($action === 'correct') {
                $after['correction_event_id'] = $validated['correction_event_id'];
            }
            $this->audit($actor, $record, 'custody.'.$action, $copy->id, $validated['reason'], $before, $after);

            return $copy;
        }, 1);
    }

    private function validLocation(DocumentScope $scope, int $id): void
    {
        if (! StorageLocation::query()->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'storageInvalidLocation']);
        }
    }

    private function requireStatus(PhysicalCopy $copy, string $status): void
    {
        if ($copy->status !== $status) {
            throw ValidationException::withMessages(['storage' => 'storageCustodyConflict']);
        }
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return array_fill_keys(['required', 'required_with', 'required_if', 'integer', 'string', 'max', 'min', 'regex', 'in', 'date_format', 'before_or_equal', 'after_or_equal'], 'storageValidation');
    }

    private function persist(Model $model): void
    {
        if (! $model->save()) {
            throw new LogicException('Physical custody persistence cancelled.');
        }
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(User $actor, Model $record, string $action, string $subject, string $reason, ?array $before, ?array $after): void
    {
        $this->persist(new StorageEvent(['actor_id' => $actor->id, 'profile_id' => $record->profile_id, 'subject_id' => $subject, 'action' => $action, 'reason' => $reason, 'before' => $before, 'after' => $after]));
    }
}
