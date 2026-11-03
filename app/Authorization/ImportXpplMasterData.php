<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\MasterDataAudit;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ImportXpplMasterData
{
    public function __construct(private ManageOrganization $organization, private ManageDocumentMasterData $master) {}

    /** @return array{data: array<string, mixed>, hash: string, issues: list<array{string, string}>} */
    public function source(): array
    {
        $raw = file_get_contents(database_path('seeders/xppl-master-data.json'));
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $named = ['required', 'array:code,name_en,name_lo,active'];
        $rules = [
            'status' => ['sometimes', 'string', 'max:500'], 'source' => ['sometimes', 'string', 'max:2000'],
            'proposals_requiring_approval' => ['sometimes', 'array', 'list'], 'proposals_requiring_approval.*' => ['string', 'max:2000'],
            'company_id' => ['sometimes', 'integer', 'min:1'], 'site_id' => ['sometimes', 'integer', 'min:1'],
            'company_code' => ['required', 'in:XPPL'], 'site_code' => ['required', 'in:XPPL'],
            'company' => $named, 'site' => $named,
            'departments' => ['required', 'array', 'list', 'min:1'], 'departments.*' => $named,
            'categories' => ['required', 'array', 'list', 'min:1'], 'categories.*' => $named,
            'types' => ['required', 'array', 'list', 'min:1'], 'types.*' => ['required', 'array:code,name_en,name_lo,active,category_code,register_keys'],
            'types.*.category_code' => ['required', 'string'], 'types.*.register_keys' => ['required', 'array', 'list', 'min:1', 'max:3'],
            'types.*.register_keys.*' => ['required', 'in:incoming,outgoing,controlled'],
            'registers' => ['required', 'array', 'list', 'min:1', 'max:3'], 'registers.*' => ['required', 'array:register_key,active'],
            'registers.*.register_key' => ['required', 'in:incoming,outgoing,controlled'], 'registers.*.active' => ['required', 'boolean'],
        ];
        foreach (['company', 'site', 'departments.*', 'categories.*', 'types.*'] as $prefix) {
            $rules += [$prefix.'.code' => ['required', 'string', in_array($prefix, ['categories.*', 'types.*'], true) ? 'max:64' : 'max:32', in_array($prefix, ['categories.*', 'types.*'], true) ? 'regex:/^[A-Z][A-Z0-9_-]{1,63}$/' : 'regex:/^[A-Z][A-Z0-9_-]*$/'],
                $prefix.'.name_en' => ['required', 'string', 'max:255'], $prefix.'.name_lo' => ['required', 'string', 'max:255'],
                $prefix.'.active' => ['required', 'boolean']];
        }
        Validator::make($data, $rules)->validate();
        if (array_diff(array_keys($data), ['status', 'source', 'proposals_requiring_approval', 'company_id', 'site_id', 'company_code', 'site_code', 'company', 'site', 'departments', 'categories', 'registers', 'types']) !== []
            || $data['company']['code'] !== 'XPPL' || $data['site']['code'] !== 'XPPL') {
            throw new RuntimeException('Only the XPPL master-data source schema is permitted. DEV IDs are informational only.');
        }
        foreach (['departments', 'categories', 'registers', 'types'] as $kind) {
            $seen = [];
            foreach ($data[$kind] as $row) {
                foreach ($kind === 'registers' ? ['register_key'] : ['code', 'name_en', 'name_lo'] as $field) {
                    $key = $field.':'.mb_strtolower(trim($row[$field]));
                    if (isset($seen[$key])) {
                        throw new RuntimeException('Duplicate source '.$kind.' '.$field.': '.$row[$field]);
                    }
                    $seen[$key] = true;
                }
            }
        }
        if (! $data['company']['active'] || ! $data['site']['active']) {
            throw new RuntimeException('The source XPPL company and site must be active parents.');
        }
        $categories = array_column($data['categories'], null, 'code');
        $registers = array_column($data['registers'], null, 'register_key');
        foreach ($data['types'] as $row) {
            if (count(array_unique($row['register_keys'])) !== count($row['register_keys'])) {
                throw new RuntimeException('Duplicate source register mapping for '.$row['code']);
            }
            if (! isset($categories[$row['category_code']]) || array_diff($row['register_keys'], array_keys($registers)) !== []) {
                throw new RuntimeException('Missing source category/register relationship for '.$row['code']);
            }
            if ($row['active'] && (! $categories[$row['category_code']]['active'] || collect($row['register_keys'])->contains(fn (string $key): bool => ! $registers[$key]['active']))) {
                throw new RuntimeException('Inactive source parent for '.$row['code']);
            }
        }
        $issues = [];
        if (($data['status'] ?? '') !== 'REVIEWED') {
            $issues[] = ['status', (string) ($data['status'] ?? 'Source has no reviewed status')];
        }
        foreach ($data['proposals_requiring_approval'] ?? [] as $proposal) {
            $issues[] = ['proposal', (string) $proposal];
        }
        foreach (['company' => [$data['company']], 'site' => [$data['site']], 'departments' => $data['departments'], 'categories' => $data['categories'], 'types' => $data['types']] as $kind => $rows) {
            foreach ($rows as $row) {
                if (str_contains($row['name_lo'], '[DEV-UNTRANSLATED]') || ! preg_match('/[\x{0E80}-\x{0EFF}]/u', $row['name_lo'])) {
                    $issues[] = [$kind.'.'.$row['code'].'.name_lo', $row['name_lo']];
                }
                if ($kind === 'categories' && preg_match('/^CAT\d+$/', $row['code'])) {
                    $issues[] = [$kind.'.'.$row['code'].'.code', 'Temporary application code; not an approved official code.'];
                }
            }
        }

        return ['data' => $data, 'hash' => hash('sha256', $raw), 'issues' => $issues];
    }

    /** @param array<string, mixed> $data
     * @return list<array{kind: string, code: string, action: string, reason: string, existing: array<string, mixed>|null}>
     */
    public function plan(array $data, bool $lock = false): array
    {
        $plan = [];
        $companies = Company::query()->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->get();
        $company = $this->inspect($plan, 'companies', $data['company'], $companies);
        $sites = $company ? Site::query()->where('company_id', $company->id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->get() : collect();
        $site = $this->inspect($plan, 'sites', $data['site'], $sites);
        $scope = $site ? AuthorizationScope::query()->where('scope_key', 'site:'.$site->id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first() : null;
        $scopeConflict = $scope && ($scope->scope_type !== 'site' || $scope->company_id !== $company->id || $scope->site_id !== $site->id);
        $plan[] = ['kind' => 'scopes', 'code' => 'XPPL/XPPL', 'action' => $scopeConflict ? 'conflict' : ($scope ? 'skip' : 'create'), 'reason' => $scopeConflict ? 'Scope parent mismatch' : '', 'existing' => $scope?->only(['id', 'scope_type', 'company_id', 'site_id', 'scope_key'])];
        $models = ['departments' => Department::class, 'categories' => DocumentCategory::class, 'registers' => RegisterConfiguration::class, 'types' => DocumentType::class];
        foreach ($models as $kind => $class) {
            $parent = $kind === 'departments' ? $site?->id : $scope?->id;
            $existing = $parent ? $class::query()->where($kind === 'departments' ? 'site_id' : 'scope_id', $parent)
                ->when($kind === 'types', fn (Builder $query): Builder => $query->with(['category', 'registers']))->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->get() : collect();
            foreach ($data[$kind] as $row) {
                $record = $this->inspect($plan, $kind, $row, $existing);
                if ($record && $kind === 'departments' && $record->company_id !== $company->id) {
                    $plan[array_key_last($plan)]['action'] = 'conflict';
                    $plan[array_key_last($plan)]['reason'] = 'Department parent mismatch';
                }
                if ($kind !== 'types') {
                    continue;
                }
                $keys = $record?->registers->pluck('register_key')->map(fn ($key): string => $key->value)->all() ?? [];
                sort($keys);
                if ($record && ($record->category?->code !== $row['category_code'] || array_diff($keys, $row['register_keys']) !== [])) {
                    $plan[array_key_last($plan)]['action'] = 'conflict';
                    $plan[array_key_last($plan)]['reason'] = 'Category or extra register mappings differ; no mappings will be removed';
                }
                if ($record) {
                    $plan[array_key_last($plan)]['existing']['category_id'] = $record->category_id;
                    $plan[array_key_last($plan)]['existing']['register_keys'] = $keys;
                }
                foreach ($row['register_keys'] as $key) {
                    $plan[] = ['kind' => 'mappings', 'code' => $row['code'].':'.$key, 'action' => in_array($key, $keys, true) ? 'skip' : 'create', 'reason' => '', 'existing' => null];
                }
            }
        }

        return $plan;
    }

    /** @param list<array<string, mixed>> $plan
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Model>  $existing
     */
    private function inspect(array &$plan, string $kind, array $row, Collection $existing): ?Model
    {
        $field = $kind === 'registers' ? 'register_key' : 'code';
        $code = $row[$field];
        $matches = $existing->filter(fn (Model $model): bool => mb_strtolower($this->code($model, $field)) === mb_strtolower($code));
        $record = $matches->first();
        $reason = $matches->count() > 1 ? 'Duplicate target business code' : '';
        if ($record && ($this->code($record, $field) !== $code || $record->active !== (bool) $row['active'])) {
            $reason = 'Code case or active status differs';
        }
        foreach (isset($row['name_en']) ? ['name_en', 'name_lo'] : [] as $name) {
            if ($record && $record->{$name} !== $row[$name]) {
                $reason = $name.' differs';
            }
            if ($existing->contains(fn (Model $model): bool => $model->id !== $record?->id && mb_strtolower(trim($model->{$name})) === mb_strtolower(trim($row[$name])))) {
                $reason = 'Name already belongs to a different business code: '.$name;
            }
        }
        $plan[] = ['kind' => $kind, 'code' => $code, 'action' => $reason !== '' ? 'conflict' : ($record ? 'skip' : 'create'), 'reason' => $reason,
            'existing' => $record?->only(['id', $field, ...($kind === 'registers' ? [] : ['name_en', 'name_lo']), 'active'])];

        return $record;
    }

    private function code(Model $record, string $field): string
    {
        $value = $record->{$field};

        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    /** Only called within the guarded command's locked transaction after plan approval.
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data, User $actor): void
    {
        $company = Company::query()->where('code', $data['company']['code'])->first()
            ?? $this->organization->save($actor, 'companies', $data['company']);
        $site = $company->sites()->where('code', $data['site']['code'])->first()
            ?? $this->organization->save($actor, 'sites', $data['site'] + ['company_id' => $company->id]);
        $scope = AuthorizationScope::context('site', $company->id, $site->id)->persist();
        foreach ($data['departments'] as $row) {
            if (! Department::query()->where('site_id', $site->id)->where('code', $row['code'])->exists()) {
                $this->organization->save($actor, 'departments', $row + ['company_id' => $company->id, 'site_id' => $site->id]);
            }
        }
        foreach (['registers', 'categories', 'types'] as $kind) {
            foreach ($data[$kind] as $row) {
                $key = $kind === 'registers' ? 'register_key' : 'code';
                if ($this->master->query($kind, $scope)->where($key, $row[$key])->exists()) {
                    continue;
                }
                if ($kind === 'types') {
                    $row['category_id'] = DocumentCategory::query()->where('scope_id', $scope->id)->where('code', $row['category_code'])->sole()->id;
                    $row['register_ids'] = RegisterConfiguration::query()->where('scope_id', $scope->id)->whereIn('register_key', $row['register_keys'])->pluck('id')->all();
                    unset($row['category_code'], $row['register_keys']);
                }
                $this->master->save($actor, $scope, $kind, $row);
            }
        }
        foreach ($data['types'] as $row) {
            $type = DocumentType::query()->where('scope_id', $scope->id)->where('code', $row['code'])->sole();
            foreach ($row['register_keys'] as $key) {
                $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', $key)->sole();
                if (! $type->registers()->where('register_configurations.id', $register->id)->exists()) {
                    $before = $type->getAttributes() + ['register_ids' => $type->registers()->orderBy('register_configurations.id')->pluck('register_configurations.id')->all()];
                    DB::table('document_type_register')->insert(['scope_id' => $scope->id, 'type_id' => $type->id, 'register_id' => $register->id]);
                    $audit = MasterDataAudit::query()->create(['actor_id' => $actor->id, 'scope_id' => $scope->id, 'subject_type' => 'types', 'subject_id' => $type->id, 'action' => 'updated',
                        'before' => $before, 'after' => $type->getAttributes() + ['register_ids' => $type->registers()->orderBy('register_configurations.id')->pluck('register_configurations.id')->all()]]);
                    if (! $audit->exists) {
                        throw new RuntimeException('Mapping audit failed; import rolled back.');
                    }
                }
            }
        }
    }
}
