<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\MasterDataAudit;
use App\Models\RegisterConfiguration;
use App\Models\ScopedDocumentMaster;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ManageDocumentMasterData
{
    public function __construct(private AccessControl $access) {}

    /** @return Builder<ScopedDocumentMaster> */
    public function query(string $kind, AuthorizationScope $scope): Builder
    {
        $class = match ($kind) {
            'categories' => DocumentCategory::class,
            'types' => DocumentType::class,
            'registers' => RegisterConfiguration::class,
        };

        return $class::query()->where('scope_id', $scope->id);
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, AuthorizationScope $scope, string $kind, array $data, ?string $id = null): ScopedDocumentMaster
    {
        return DB::transaction(function () use ($actor, $scope, $kind, $data, $id): ScopedDocumentMaster {
            $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);
            $this->access->authorize($actor, 'master-data.manage', $scope);
            abort_unless(in_array($scope->scope_type, ['company', 'site'], true), 403, 'rbacForbidden');
            $scope->persist();
            $query = $this->query($kind, $scope);
            $record = $id === null ? $query->getModel()->newInstance(['scope_id' => $scope->id]) : $query->whereKey($id)->first();
            abort_if($record === null, 404);
            $before = $id === null ? null : $this->snapshot($record);
            $record->fill(collect($data)->only(['code', 'register_key', 'category_id', 'name_en', 'name_lo', 'description_en', 'description_lo', 'active'])->all());
            if ($record instanceof DocumentType) {
                $category = DocumentCategory::query()->where('scope_id', $scope->id)->find($record->category_id);
                $registerIds = $data['register_ids'] ?? ($record->exists ? $record->registers()->pluck('register_configurations.id')->all() : []);
                $registers = RegisterConfiguration::query()->where('scope_id', $scope->id)->whereIn('id', $registerIds)->get();
                if (! $category || count($registerIds) === 0 || count($registerIds) !== $registers->count()) {
                    throw ValidationException::withMessages(['register_ids' => 'masterInvalidRelationship']);
                }
                if ($record->active && (! $record->exists || $record->isDirty('active') || array_key_exists('register_ids', $data))
                    && (! $category->active || $registers->contains(fn (RegisterConfiguration $register): bool => ! $register->active))) {
                    throw ValidationException::withMessages(['register_ids' => 'masterInactiveParent']);
                }
            }
            if (! $record->exists) {
                $record->created_by = $actor->id;
            }
            $record->updated_by = $actor->id;
            if (! $record->save()) {
                throw new LogicException('Master data persistence was cancelled.');
            }
            if ($record instanceof DocumentType && array_key_exists('register_ids', $data)) {
                $record->registers()->syncWithPivotValues($data['register_ids'], ['scope_id' => $scope->id]);
            }
            $audit = MasterDataAudit::query()->create([
                'actor_id' => $actor->id, 'scope_id' => $scope->id, 'subject_type' => $kind, 'subject_id' => $record->id,
                'action' => $before === null ? 'created' : 'updated', 'before' => $before, 'after' => $this->snapshot($record),
            ]);
            if (! $audit->exists) {
                throw new LogicException('Master data audit persistence was cancelled.');
            }

            return $record->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(ScopedDocumentMaster $record): array
    {
        return $record->getAttributes() + ($record instanceof DocumentType ? ['register_ids' => $record->registers()->orderBy('id')->pluck('register_configurations.id')->all()] : []);
    }
}
