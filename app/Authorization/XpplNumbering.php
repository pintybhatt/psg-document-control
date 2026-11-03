<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\NumberingConfiguration;
use App\Models\NumberingSeries;
use App\Models\RegisterConfiguration;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class XpplNumbering
{
    public function __construct(private AccessControl $access) {}

    public static function lockCompany(int $companyId): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('companies')->where('id', $companyId)->update(['id' => DB::raw('id')]);
        }
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function masters(int $companyId, array $data): array
    {
        $validated = Validator::make($data, [
            'series_id' => ['sometimes', 'integer'], 'department_id' => ['required', 'integer'],
            'type_id' => ['required', 'integer'], 'format' => ['required', Rule::in(['xppl_controlled', 'xppl_letter'])],
        ])->validate();
        $department = Department::query()->findOrFail($validated['department_id']);
        if (isset($validated['series_id'])) {
            $series = NumberingSeries::query()->with('scope', 'register', 'currentConfiguration')->findOrFail($validated['series_id']);
        } else {
            abort_unless($validated['format'] === 'xppl_controlled' && $department->company_id === $companyId
                && Company::query()->whereKey($companyId)->where('code', 'XPPL')->exists(), 422, 'numberingXpplConflict');
            $scope = AuthorizationScope::query()->where('company_id', $companyId)->where('site_id', $department->site_id)->where('scope_type', 'site')->firstOrFail();
            $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', 'controlled')->where('active', true)->firstOrFail();
            $series = NumberingSeries::query()->with('scope', 'register', 'currentConfiguration')->where('register_id', $register->id)
                ->where('purpose', NumberingPurpose::InternalRegister)->first();
            if (! $series) {
                $series = new NumberingSeries(['scope_id' => $scope->id, 'register_id' => $register->id, 'purpose' => NumberingPurpose::InternalRegister, 'reset_policy' => NumberingResetPolicy::Continuous]);
                $series->id = 0;
                $series->setRelation('scope', $scope)->setRelation('register', $register)->setRelation('currentConfiguration', new NumberingConfiguration(['active' => true]));
            }
        }
        $type = DocumentType::query()->with('category')->findOrFail($validated['type_id']);
        $company = Company::query()->findOrFail($companyId);
        $controlled = $validated['format'] === 'xppl_controlled';
        if ($series->scope->company_id !== $companyId || $series->scope->scope_type !== 'site'
            || ! $series->scope->isActive() || ! $series->currentConfiguration?->active || ! $company->active || ! $department->active
            || $department->company_id !== $companyId || $department->site_id !== $series->scope->site_id
            || $type->scope_id !== $series->scope_id || ! $type->effectiveFor($series->register)
            || $series->register->register_key->value !== ($controlled ? 'controlled' : 'outgoing')
            || $series->purpose->value !== ($controlled ? 'internal_register' : 'official_outgoing')
            || $type->code === 'FRM' || (! $controlled && $type->code !== 'LTR')) {
            $this->invalid();
        }
        foreach ([$company->code, $department->code, $type->code] as $code) {
            if (! preg_match('/^[A-Z][A-Z0-9]{0,31}$/D', $code)) {
                $this->invalid();
            }
        }
        $identity = [$department->name_en, $department->name_lo, $type->name_en, $type->name_lo, $type->category->code];
        $identity = array_map(fn (?string $value): string => mb_strtolower(trim((string) $value)), $identity);
        $prefix = $company->code.'-'.$department->code.'-'.$type->code;
        $identityHash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $masterHash = hash('sha256', json_encode([$prefix, $identityHash, $series->id, $type->id, $department->id], JSON_THROW_ON_ERROR));

        return ['company_id' => $companyId, 'prefix' => $prefix, 'format' => $validated['format'], 'identity_hash' => $identityHash,
            'master_hash' => $masterHash, 'series_id' => $series->id, 'department_id' => $department->id, 'type_id' => $type->id];
    }

    /** @param array<string, mixed> $masters
     * @return array<string, mixed>
     */
    public function reconcile(array $masters): array
    {
        $namespace = DB::table('xppl_numbering_namespaces')->where('prefix', $masters['prefix'])->first();
        $period = $masters['format'] === 'xppl_letter' ? (int) now()->format('Y') : 0;
        $ambiguous = $namespace && ($namespace->identity_hash !== $masters['identity_hash'] || $namespace->format !== $masters['format']);
        $parts = explode('-', $masters['prefix']);
        foreach (Department::query()->where('company_id', $masters['company_id'])->where('code', $parts[1])->where('active', true)->get() as $department) {
            $scope = AuthorizationScope::query()->where('company_id', $masters['company_id'])->where('site_id', $department->site_id)->where('scope_type', 'site')->first();
            if (! $scope) {
                continue;
            }
            foreach (DocumentType::query()->with('category')->where('scope_id', $scope->id)->where('code', $parts[2])->where('active', true)->get() as $type) {
                $identity = array_map(fn (?string $value): string => mb_strtolower(trim((string) $value)), [$department->name_en, $department->name_lo, $type->name_en, $type->name_lo, $type->category->code]);
                if (hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)) !== $masters['identity_hash']) {
                    $ambiguous = true;
                }
            }
        }
        $identities = [];
        $maximum = 0;
        $observed = [];
        $inspect = function (string $number, string $owner) use ($masters, $period, &$ambiguous, &$maximum, &$identities, &$observed): void {
            if (! str_starts_with(mb_strtoupper($number), $masters['prefix'].'-') && ! str_starts_with(mb_strtoupper($number), $masters['prefix'].'_')) {
                return;
            }
            $pattern = $period === 0 ? '/^'.preg_quote($masters['prefix'], '/').'-(\d+)(?:[-_]R\d+)?$/D'
                : '/^'.preg_quote($masters['prefix'], '/').'-(\d{2}|\d{4})-(\d+)$/D';
            if (! preg_match($pattern, $number, $matches)) {
                $ambiguous = true;

                return;
            }
            if ($period !== 0 && (int) substr($matches[1], -2) !== $period % 100) {
                return;
            }
            $sequence = (int) $matches[$period === 0 ? 1 : 2];
            if ($sequence < 1 || $sequence > NumberingFormat::MAX_SEQUENCE) {
                $ambiguous = true;
            }
            $maximum = max($maximum, $sequence);
            $identities[$sequence][$owner] = true;
            $observed[] = [$number, $owner];
        };
        $companyScopes = AuthorizationScope::query()->where('company_id', $masters['company_id'])->select('id');
        foreach (DB::table('issued_document_numbers as i')->join('numbering_series as s', 's.id', '=', 'i.series_id')->whereIn('s.scope_id', $companyScopes)->where('i.number', 'like', $masters['prefix'].'%')->get(['i.number', 'i.record_type', 'i.record_key']) as $row) {
            $inspect($row->number, $row->record_type.':'.$row->record_key);
        }
        foreach (DB::table('controlled_documents')->whereIn('scope_id', $companyScopes)->where(fn (Builder $query): Builder => $query->where('document_code', 'like', $masters['prefix'].'%')->orWhere('internal_number', 'like', $masters['prefix'].'%'))->get(['id', 'document_code', 'internal_number']) as $row) {
            if ($row->document_code !== null) {
                $inspect($row->document_code, 'controlled:'.$row->id);
            }
            if ($row->internal_number) {
                $inspect($row->internal_number, 'controlled:'.$row->id);
            }
        }
        foreach (DB::table('outgoing_correspondences')->whereIn('scope_id', $companyScopes)->where('official_number', 'like', $masters['prefix'].'%')->get(['id', 'official_number']) as $row) {
            $inspect($row->official_number, 'outgoing:'.$row->id);
        }
        $collisions = count(array_filter($identities, fn (array $owners): bool => count($owners) > 1));
        $counter = $namespace ? DB::table('xppl_numbering_counters')->where('namespace_id', $namespace->id)->where('period', $period)->first() : null;
        $next = max($maximum, (int) ($counter?->value ?? 0)) + 1;
        sort($observed);
        $report = ['prefix' => $masters['prefix'], 'format' => $masters['format'], 'period' => $period, 'historical_maximum' => $maximum,
            'observed_count' => count($observed), 'collision_count' => $collisions, 'ambiguous' => (bool) $ambiguous,
            'next_sequence' => $next, 'confirmed' => $counter !== null, 'namespace_id' => $namespace?->id,
            'preview' => $this->render($masters['prefix'], $masters['format'], (int) now()->format('Y'), $next)];
        $report['token'] = hash('sha256', json_encode([$report, $masters, $observed], JSON_THROW_ON_ERROR));

        return $report;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function review(User $actor, AuthorizationScope $scope, array $data, bool $confirm = false): array
    {
        abort_unless($scope->scope_type === 'company', 422, 'numberingXpplCompany');
        $this->access->authorize($actor, $confirm ? 'numbering.manage' : 'numbering.view', $scope);

        return DB::transaction(function () use ($actor, $scope, $data, $confirm): array {
            self::lockCompany($scope->company_id);
            $masters = $this->masters($scope->company_id, $data);
            $report = $this->reconcile($masters);
            if (! $confirm) {
                return $report;
            }
            Validator::make($data, ['token' => ['required', 'string'], 'next_sequence' => ['required', 'integer', 'min:1', 'max:'.NumberingFormat::MAX_SEQUENCE], 'confirm' => ['required', 'accepted']])->validate();
            if ($report['ambiguous'] || $report['collision_count'] || ! hash_equals($report['token'], $data['token']) || (int) $data['next_sequence'] !== $report['next_sequence']) {
                $this->invalid();
            }
            if ($masters['series_id'] === 0) {
                $department = Department::query()->findOrFail($masters['department_id']);
                $siteScope = AuthorizationScope::query()->where('company_id', $scope->company_id)->where('site_id', $department->site_id)->where('scope_type', 'site')->firstOrFail();
                $register = RegisterConfiguration::query()->where('scope_id', $siteScope->id)->where('register_key', 'controlled')->where('active', true)->firstOrFail();
                $series = app(ManageNumbering::class)->save($actor, $siteScope, [
                    'register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'continuous',
                    'prefix' => 'XPPL/DCMS/REGISTER/'.$register->id, 'padding' => 4, 'active' => true,
                    'change_reason' => 'Supporting configuration for explicitly approved official XPPL numbering',
                ]);
                $masters = $this->masters($scope->company_id, array_replace($data, ['series_id' => $series->id]));
                $report = $this->reconcile($masters);
            }
            $namespaceId = $report['namespace_id'] ?? DB::table('xppl_numbering_namespaces')->insertGetId(array_intersect_key($masters, array_flip(['company_id', 'prefix', 'format', 'identity_hash'])) + ['created_by' => $actor->id, 'created_at' => now()]);
            $mapping = DB::table('xppl_numbering_mappings')->where('series_id', $masters['series_id'])->where('department_id', $masters['department_id'])->where('type_id', $masters['type_id'])->first();
            if ($mapping && ($mapping->namespace_id !== $namespaceId || $mapping->master_hash !== $masters['master_hash'])) {
                $this->invalid();
            }
            if (! $mapping) {
                DB::table('xppl_numbering_mappings')->insert(array_intersect_key($masters, array_flip(['series_id', 'department_id', 'type_id', 'master_hash'])) + ['namespace_id' => $namespaceId, 'confirmed_by' => $actor->id, 'created_at' => now()]);
            }
            if (! $report['confirmed']) {
                DB::table('xppl_numbering_counters')->insert(['namespace_id' => $namespaceId, 'period' => $report['period'], 'baseline' => $report['next_sequence'] - 1, 'value' => $report['next_sequence'] - 1,
                    'reconciliation' => json_encode($report, JSON_THROW_ON_ERROR), 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            } elseif ($report['next_sequence'] > 1 + (int) DB::table('xppl_numbering_counters')->where('namespace_id', $namespaceId)->where('period', $report['period'])->value('value')) {
                $this->invalid();
            }

            return $this->reconcile($masters);
        });
    }

    /** @return list<array<string, mixed>> */
    public static function options(int $scopeId, ?int $departmentId, int $typeId, string $format): array
    {
        if ($departmentId === null) {
            return [];
        }

        return DB::table('xppl_numbering_mappings as m')->join('numbering_series as s', 's.id', '=', 'm.series_id')
            ->join('xppl_numbering_namespaces as n', 'n.id', '=', 'm.namespace_id')
            ->where('s.scope_id', $scopeId)->where('m.department_id', $departmentId)->where('m.type_id', $typeId)->where('n.format', $format)
            ->get(['n.id', 'n.prefix', 'n.format'])->map(fn (object $row): array => (array) $row)->all();
    }

    public function render(string $prefix, string $format, int $year, int $sequence): string
    {
        return $prefix.'-'.($format === 'xppl_letter' ? substr((string) $year, -2).'-' : '').str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function invalid(): never
    {
        throw ValidationException::withMessages(['namespace_id' => 'numberingXpplConflict']);
    }
}
