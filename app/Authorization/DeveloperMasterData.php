<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\MasterDataAudit;
use App\Models\NumberingConfiguration;
use App\Models\NumberingSeries;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DeveloperMasterData
{
    private const NAMED = ['code', 'name_en', 'name_lo', 'active'];

    private const DESCRIPTIONS = ['description_en', 'description_lo'];

    /** @return array<string, mixed> */
    public function export(string $companyCode): array
    {
        $company = Company::query()->where('code', $companyCode)->sole();
        $sites = $company->sites()->orderBy('code')->get();
        $scopes = AuthorizationScope::query()->where('company_id', $company->id)->orderBy('scope_type')->orderBy('site_id')->get();
        $scopeKeys = $scopes->mapWithKeys(fn (AuthorizationScope $scope): array => [$scope->id => $scope->scope_type === 'company' ? 'company' : 'site:'.$sites->find($scope->site_id)?->code]);
        $data = ['schema_version' => 1, 'company' => $company->only(self::NAMED),
            'sites' => $sites->map(fn (Site $site): array => $site->only(self::NAMED))->all(),
            'departments' => Department::query()->where('company_id', $company->id)->with('site')->orderBy('site_id')->orderBy('code')->get()
                ->map(fn (Department $department): array => ['site_code' => $department->site->code] + $department->only(self::NAMED))->all(),
            'scopes' => $scopeKeys->values()->all()];
        foreach (['categories' => DocumentCategory::class, 'registers' => RegisterConfiguration::class, 'types' => DocumentType::class] as $kind => $class) {
            $data[$kind] = $class::query()->whereIn('scope_id', $scopes->modelKeys())->orderBy('scope_id')->orderBy($kind === 'registers' ? 'register_key' : 'code')->get()
                ->map(function (Model $record) use ($kind, $scopeKeys): array {
                    $row = ['scope' => $scopeKeys[$record->scope_id]] + $record->only([...($kind === 'registers' ? [] : ['code', 'name_en', 'name_lo']), ...self::DESCRIPTIONS, 'active']);
                    if ($kind === 'registers') {
                        $row['register_key'] = $record->register_key->value;
                    }
                    if ($kind === 'types') {
                        $row += $record->only(['review_enabled', 'review_interval_months', 'review_baseline']);
                        $row['category_code'] = $record->category->code;
                        $row['register_keys'] = $record->registers->map(fn (RegisterConfiguration $register): string => $register->register_key->value)->sort()->values()->all();
                    }

                    return $row;
                })->all();
        }
        $data['numbering'] = NumberingSeries::query()->whereIn('scope_id', $scopes->modelKeys())->with(['register', 'currentConfiguration'])->orderBy('register_id')->orderBy('purpose')->get()
            ->map(function (NumberingSeries $series) use ($scopeKeys): array {
                $config = $series->currentConfiguration;
                if (! $config || ! DB::table('numbering_namespaces')->where('series_id', $series->id)->where('prefix', $config->prefix)->exists()) {
                    throw new RuntimeException('Numbering series has no valid current configuration/namespace.');
                }

                return ['scope' => $scopeKeys[$series->scope_id], 'register_key' => $series->register->register_key->value,
                    'purpose' => $series->purpose->value, 'reset_policy' => $series->reset_policy->value]
                    + $config->only(['prefix', 'padding', 'format', 'active']);
            })->all();
        $data['policies'] = DB::table('xppl_numbering_policies')->where('company_id', $company->id)->exists() ? ['controlled'] : [];
        $data['policies'] = [...$data['policies'], ...DB::table('xppl_correspondence_policies')->where('company_id', $company->id)->orderBy('register_kind')->pluck('register_kind')->all()];
        $this->validate($data);

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function validate(array $data): void
    {
        $named = ['required', 'array:code,name_en,name_lo,active'];
        $rules = ['schema_version' => ['required', 'integer', 'in:1'], 'company' => $named,
            'company.code' => ['required', 'in:XPPL'], 'sites' => ['present', 'array', 'list'],
            'sites.*' => $named, 'departments' => ['present', 'array', 'list'],
            'departments.*' => ['required', 'array:site_code,code,name_en,name_lo,active'],
            'departments.*.site_code' => ['required', 'string'], 'scopes' => ['present', 'array', 'list'],
            'scopes.*' => ['required', 'string', 'distinct:strict'],
            'policies' => ['present', 'array', 'list'], 'policies.*' => ['required', 'in:controlled,incoming,outgoing', 'distinct:strict']];
        foreach (['company', 'sites.*', 'departments.*', 'categories.*', 'types.*'] as $prefix) {
            $rules += [$prefix.'.code' => ['required', 'string', 'max:'.(in_array($prefix, ['categories.*', 'types.*'], true) ? 64 : 32), 'regex:/^[A-Z][A-Z0-9_-]*$/D'],
                $prefix.'.name_en' => ['required', 'string', 'max:255'], $prefix.'.name_lo' => ['required', 'string', 'max:255'], $prefix.'.active' => ['required', 'boolean']];
        }
        foreach (['categories', 'registers', 'types', 'numbering'] as $kind) {
            $fields = match ($kind) {
                'categories' => 'scope,code,name_en,name_lo,description_en,description_lo,active',
                'registers' => 'scope,register_key,description_en,description_lo,active',
                'types' => 'scope,code,name_en,name_lo,description_en,description_lo,active,category_code,register_keys,review_enabled,review_interval_months,review_baseline',
                'numbering' => 'scope,register_key,purpose,reset_policy,prefix,padding,format,active',
            };
            $rules += [$kind => ['present', 'array', 'list'], $kind.'.*' => ['required', 'array:'.$fields],
                $kind.'.*.scope' => ['required', 'string'], $kind.'.*.active' => ['required', 'boolean']];
            if ($kind !== 'numbering') {
                foreach (self::DESCRIPTIONS as $field) {
                    $rules[$kind.'.*.'.$field] = ['present', 'nullable', 'string', 'max:2000'];
                }
            }
        }
        $rules += ['registers.*.register_key' => ['required', 'in:incoming,outgoing,controlled'],
            'types.*.category_code' => ['required', 'string'], 'types.*.register_keys' => ['present', 'array', 'list'],
            'types.*.register_keys.*' => ['required', 'in:incoming,outgoing,controlled'],
            'types.*.review_enabled' => ['required', 'boolean'], 'types.*.review_interval_months' => ['present', 'nullable', 'integer', 'between:1,1200'],
            'types.*.review_baseline' => ['present', 'nullable', 'in:completion_date'],
            'numbering.*.register_key' => ['required', 'in:incoming,outgoing,controlled'],
            'numbering.*.purpose' => ['required', 'in:internal_register,official_outgoing'],
            'numbering.*.format' => ['present', 'nullable', 'array'],
            'numbering.*.reset_policy' => ['required', 'in:annual,continuous']];
        Validator::make($data, $rules)->validate();
        if (array_diff(array_keys($data), ['schema_version', 'company', 'sites', 'departments', 'scopes', 'categories', 'registers', 'types', 'numbering', 'policies'])) {
            throw new RuntimeException('Unknown package fields; operational data is not accepted.');
        }
        $seen = [];
        $errors = [];
        foreach (['sites', 'departments', 'categories', 'registers', 'types', 'numbering'] as $kind) {
            foreach ($data[$kind] as $index => $row) {
                $key = $this->key($kind, $row);
                if (isset($seen[$kind][$key])) {
                    $errors[$kind.'.'.$index] = 'Duplicate business key: '.$key;
                }
                $seen[$kind][$key] = true;
                if (isset($row['name_en'])) {
                    $context = $row['scope'] ?? $row['site_code'] ?? '';
                    $name = $context.'/'.mb_strtolower($row['name_en']);
                    if (isset($seen[$kind.'_names'][$name])) {
                        $errors[$kind.'.'.$index.'.name_en'] = 'Duplicate source name in the same parent.';
                    }
                    $seen[$kind.'_names'][$name] = true;
                }
            }
        }
        foreach ($data['scopes'] as $key) {
            if ($key !== 'company' && (! str_starts_with($key, 'site:') || ! isset($seen['sites'][substr($key, 5)]))) {
                $errors['scopes.'.$key] = 'Invalid reference: scope site is missing.';
            }
        }
        foreach ($data['departments'] as $index => $row) {
            if (! isset($seen['sites'][$row['site_code']])) {
                $errors['departments.'.$index] = 'Invalid reference: department site is missing.';
            }
        }
        foreach (['categories', 'registers', 'types', 'numbering'] as $kind) {
            foreach ($data[$kind] as $index => $row) {
                if (! in_array($row['scope'], $data['scopes'], true)) {
                    $errors[$kind.'.'.$index] = 'Invalid reference: authorization scope is missing.';
                }
                if ($kind === 'types') {
                    if ($row['review_enabled'] && (! $row['review_interval_months'] || $row['review_baseline'] !== 'completion_date')) {
                        $errors['types.'.$index.'.review_enabled'] = 'Enabled review requires an interval and completion_date baseline.';
                    }
                    if (! isset($seen['categories'][$row['scope'].'/'.$row['category_code']])) {
                        $errors['types.'.$index] = 'Invalid reference: category is missing in the same scope.';
                    }
                    if (count($row['register_keys']) !== count(array_unique($row['register_keys']))) {
                        $errors['types.'.$index.'.register_keys'] = 'Duplicate register reference.';
                    }
                    foreach ($row['register_keys'] as $register) {
                        if (! isset($seen['registers'][$row['scope'].'/'.$register])) {
                            $errors['types.'.$index.'.register_keys'] = 'Invalid reference: register is missing in the same scope.';
                        }
                    }
                }
                if ($kind === 'numbering') {
                    Validator::make($row, NumberingFormat::rules() + ['active' => ['required', 'boolean']])->validate();
                    NumberingFormat::validateResetPolicy($row['format'], NumberingResetPolicy::from($row['reset_policy']));
                    NumberingFormat::render($row['prefix'], (int) $row['padding'], 9999, NumberingFormat::MAX_SEQUENCE, $row['format']);
                    if (! isset($seen['registers'][$row['scope'].'/'.$row['register_key']]) || ($row['purpose'] === 'official_outgoing' && $row['register_key'] !== 'outgoing')) {
                        $errors['numbering.'.$index] = 'Invalid reference: numbering register/purpose.';
                    }
                    if (isset($seen['prefixes'][$row['prefix']])) {
                        $errors['numbering.'.$index.'.prefix'] = 'Duplicate numbering prefix.';
                    }
                    $seen['prefixes'][$row['prefix']] = true;
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{kind: string, key: string, action: string, reason: string}>
     */
    public function import(array $data, ?User $actor, bool $dryRun): array
    {
        $this->validate($data);
        if ($actor && ! $actor->active) {
            throw new RuntimeException('The local creator account must be active.');
        }
        if ($dryRun) {
            return $this->process($data, $actor, false);
        }

        return DB::transaction(function () use ($data, $actor): array {
            Company::query()->where('code', $data['company']['code'])->lockForUpdate()->first();
            $plan = $this->process($data, $actor, false);
            if (in_array('conflict', array_column($plan, 'action'), true)) {
                throw new RuntimeException('Conflicts found; run --dry-run. Nothing imported.');
            }
            $result = $this->process($data, $actor, true);
            if (in_array('conflict', array_column($result, 'action'), true)) {
                throw new RuntimeException('Target changed during import; entire transaction rolled back.');
            }

            return $result;
        }, 1);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{kind: string, key: string, action: string, reason: string}>
     */
    private function process(array $data, ?User $actor, bool $apply): array
    {
        $plan = [];
        $company = $this->merge($plan, 'company', Company::class, ['code' => $data['company']['code']], $data['company'], true, $apply);
        $sites = [];
        foreach ($data['sites'] as $row) {
            $sites[$row['code']] = $this->merge($plan, 'sites', Site::class, ['company_id' => $company?->id, 'code' => $row['code']], $row, $company !== null, $apply, businessKey: $data['company']['code'].'/'.$row['code']);
        }
        foreach ($data['departments'] as $row) {
            $site = $sites[$row['site_code']];
            $attributes = $row;
            unset($attributes['site_code']);
            $this->merge($plan, 'departments', Department::class, ['site_id' => $site?->id, 'code' => $row['code']], $attributes + ['company_id' => $company?->id], $site !== null, $apply, businessKey: $row['site_code'].'/'.$row['code']);
        }
        $scopes = [];
        foreach ($data['scopes'] as $key) {
            $site = $key === 'company' ? null : $sites[substr($key, 5)];
            $scope = $company && ($key === 'company' || $site) ? AuthorizationScope::context($key === 'company' ? 'company' : 'site', $company->id, $site?->id) : null;
            $valid = ! $scope?->exists || ($scope->company_id === $company->id && $scope->site_id === $site?->id && $scope->scope_type === ($key === 'company' ? 'company' : 'site'));
            $plan[] = ['kind' => 'scopes', 'key' => $key, 'action' => ! $valid ? 'conflict' : ($scope?->exists ? 'skip' : 'create'), 'reason' => $valid ? '' : 'Scope ownership mismatch'];
            if ($apply && $valid) {
                $scope?->persist();
                if (! $scope?->exists) {
                    throw new RuntimeException('Scope creation was cancelled; nothing imported.');
                }
            }
            $scopes[$key] = $scope?->exists ? $scope : null;
        }
        $records = [];
        foreach (['categories' => DocumentCategory::class, 'registers' => RegisterConfiguration::class, 'types' => DocumentType::class] as $kind => $class) {
            foreach ($data[$kind] as $row) {
                $key = $this->key($kind, $row);
                $scope = $scopes[$row['scope']];
                $attributes = $row;
                unset($attributes['scope'], $attributes['category_code'], $attributes['register_keys']);
                $field = $kind === 'registers' ? 'register_key' : 'code';
                if ($kind === 'types') {
                    $attributes['category_id'] = $records['categories'][$row['scope'].'/'.$row['category_code']]?->id;
                }
                $record = $this->merge($plan, $kind, $class, ['scope_id' => $scope?->id, $field => $row[$field]], $attributes, $scope !== null, $apply, $actor, $key);
                $records[$kind][$key] = $record;
                if ($kind === 'types' && $record) {
                    $actual = $record->registers->map(fn (RegisterConfiguration $register): string => $register->register_key->value)->sort()->values()->all();
                    $expected = $row['register_keys'];
                    sort($expected);
                    if ($plan[array_key_last($plan)]['action'] === 'create' && $apply) {
                        foreach ($expected as $register) {
                            $record->registers()->attach($records['registers'][$row['scope'].'/'.$register]->id, ['scope_id' => $scope->id]);
                        }
                    } elseif ($actual !== $expected) {
                        $plan[array_key_last($plan)]['action'] = 'conflict';
                        $plan[array_key_last($plan)]['reason'] = 'Register mappings differ';
                    }
                }
            }
        }
        foreach ($data['numbering'] as $row) {
            $register = $records['registers'][$row['scope'].'/'.$row['register_key']];
            $series = $register ? NumberingSeries::query()->where('register_id', $register->id)->where('purpose', $row['purpose'])->first() : null;
            $config = $series?->currentConfiguration;
            $attributes = array_intersect_key($row, array_flip(['prefix', 'padding', 'format', 'active']));
            $reserved = DB::table('numbering_namespaces')->where('prefix', $row['prefix'])->first();
            $conflict = ($series && (! $config || $series->reset_policy->value !== $row['reset_policy'] || $series->scope_id !== $register->scope_id || ! $reserved || ! $this->matches($config, $attributes)))
                || ($reserved && $reserved->series_id !== $series?->id);
            $reason = $conflict ? 'Numbering configuration or reserved prefix differs' : '';
            if (! $series && ! $actor) {
                $conflict = true;
                $reason = 'Missing local --actor for numbering creator';
            }
            $plan[] = ['kind' => 'numbering', 'key' => $this->key('numbering', $row), 'action' => $conflict ? 'conflict' : ($series ? 'skip' : 'create'), 'reason' => $reason];
            if ($apply && ! $series && ! $conflict) {
                $series = new NumberingSeries(['scope_id' => $register->scope_id, 'register_id' => $register->id, 'purpose' => $row['purpose'], 'reset_policy' => $row['reset_policy']]);
                $series->forceFill(['created_by' => $actor->id]);
                $this->save($series);
                DB::table('numbering_namespaces')->insert(['prefix' => $row['prefix'], 'series_id' => $series->id]);
                $configuration = new NumberingConfiguration($attributes + ['series_id' => $series->id, 'version' => 1, 'change_reason' => 'Local developer master-data import']);
                $configuration->forceFill(['created_by' => $actor->id, 'effective_at' => now()]);
                $this->save($configuration);
            }
        }
        foreach ($data['policies'] as $kind) {
            $table = $kind === 'controlled' ? 'xppl_numbering_policies' : 'xppl_correspondence_policies';
            $identity = ['company_id' => $company?->id] + ($kind === 'controlled' ? [] : ['register_kind' => $kind]);
            $exists = $company && DB::table($table)->where($identity)->exists();
            $plan[] = ['kind' => 'policies', 'key' => $kind, 'action' => $exists ? 'skip' : ($actor ? 'create' : 'conflict'), 'reason' => ! $exists && ! $actor ? 'Missing local --actor for policy activation' : ''];
            if ($apply && ! $exists && $actor) {
                DB::table($table)->insert($identity + ['activated_by' => $actor->id, 'activated_at' => now()]);
            }
        }

        return $plan;
    }

    /**
     * @param  list<array{kind: string, key: string, action: string, reason: string}>  $plan
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $attributes
     */
    private function merge(array &$plan, string $kind, string $class, array $identity, array $attributes, bool $parentsExist, bool $apply, ?User $actor = null, string $businessKey = ''): ?Model
    {
        $existing = $parentsExist ? $class::query()->where($identity)->first() : null;
        $conflict = $existing && ! $this->matches($existing, $attributes);
        $requiresActor = in_array($kind, ['categories', 'registers', 'types'], true);
        $reason = $conflict ? 'Existing values differ' : '';
        if (! $existing && $requiresActor && ! $actor) {
            $conflict = true;
            $reason = 'Missing local --actor for required creator fields';
        }
        if (! $existing && isset($attributes['name_en'])) {
            $query = $class::query();
            foreach (array_diff_key($identity, ['code' => true]) as $field => $value) {
                $query->where($field, $value);
            }
            if ($parentsExist && $query->whereRaw('LOWER(name_en) = ?', [mb_strtolower($attributes['name_en'])])->exists()) {
                $conflict = true;
                $reason = 'Existing name belongs to another business code';
            }
        }
        $plan[] = ['kind' => $kind, 'key' => $businessKey ?: (string) $attributes['code'], 'action' => $conflict ? 'conflict' : ($existing ? 'skip' : 'create'), 'reason' => $reason];
        if ($apply && ! $existing && ! $conflict) {
            $existing = new $class($identity + $attributes);
            if ($requiresActor) {
                $existing->forceFill(['created_by' => $actor->id, 'updated_by' => $actor->id]);
            }
            $this->save($existing);
            if ($requiresActor) {
                $audit = new MasterDataAudit(['actor_id' => $actor->id, 'scope_id' => $identity['scope_id'], 'subject_type' => $kind, 'subject_id' => $existing->id, 'action' => 'created', 'before' => null, 'after' => $existing->getAttributes()]);
                $this->save($audit);
            }
        }

        return $existing;
    }

    /** @param array<string, mixed> $attributes */
    private function matches(Model $model, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            $actual = $model->getAttribute($key);
            if ($actual instanceof \BackedEnum) {
                $actual = $actual->value;
            }
            if ($actual != $value || ($actual === null) !== ($value === null)) {
                return false;
            }
        }

        return true;
    }

    private function save(Model $model): void
    {
        if (! $model->saveOrFail()) {
            throw new RuntimeException('Record creation was cancelled; nothing imported.');
        }
    }

    /** @param array<string, mixed> $row */
    private function key(string $kind, array $row): string
    {
        return match ($kind) {
            'sites' => $row['code'], 'departments' => $row['site_code'].'/'.$row['code'],
            'registers' => $row['scope'].'/'.$row['register_key'],
            'numbering' => $row['scope'].'/'.$row['register_key'].'/'.$row['purpose'],
            default => $row['scope'].'/'.$row['code'],
        };
    }
}
