<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\ControlledDocument;
use App\Models\IncomingCorrespondence;
use App\Models\IssuedDocumentNumber;
use App\Models\NumberingSeries;
use App\Models\OutgoingCorrespondence;
use App\Models\RegisterConfiguration;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class DocumentNumbering
{
    public function __construct(private AccessControl $access, private XpplNumbering $xpplNumbering) {}

    /**
     * Trusted formal-registration integration only. The callback must persist the formal
     * record on this connection and perform no external side effects. Returned numbers
     * are provisional until the caller's outer transaction commits. Never call for drafts.
     *
     * @param  array{namespace_id: int, department_id: ?int, type_id: int}|array{format: string}|null  $xppl
     * @param  Closure(string): (Model&FormalNumberedRegistration)  $persistRegistration
     */
    public function register(User $actor, AuthorizationScope $scope, int $seriesId, Closure $persistRegistration, ?array $xppl = null): IssuedDocumentNumber
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Formal registration requires a caller-owned database transaction.');
        }

        return DB::transaction(function () use ($actor, $scope, $seriesId, $persistRegistration, $xppl): IssuedDocumentNumber {
            if ($xppl !== null) {
                XpplNumbering::lockCompany($scope->company_id);
            }
            // SQLite has no FOR UPDATE; acquire its writer lock before policy or counter reads.
            if (DB::getDriverName() === 'sqlite') {
                DB::table('numbering_series')->where('scope_id', $scope->id)->where('id', $seriesId)->update(['id' => DB::raw('id')]);
            }
            $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);
            $this->access->authorize($actor, 'numbering.issue', $scope);
            $register = RegisterConfiguration::query()->where('scope_id', $scope->id)
                ->whereIn('id', NumberingSeries::query()->where('scope_id', $scope->id)->whereKey($seriesId)->select('register_id'))
                ->lockForUpdate()->first();
            abort_if(! $register, 404);
            $series = NumberingSeries::query()->where('scope_id', $scope->id)->whereKey($seriesId)->lockForUpdate()->first();
            abort_if(! $series, 404);
            $configuration = $series->configurations()->orderByDesc('version')->lockForUpdate()->first();
            if (! $configuration?->active || ! $register->active || ! $scope->isActive()) {
                throw ValidationException::withMessages(['series' => 'numberingInactive']);
            }
            NumberingFormat::validateResetPolicy($configuration->format, $series->reset_policy);
            $year = (int) now()->format('Y');
            $period = $series->reset_policy === NumberingResetPolicy::Annual ? $year : 0;
            DB::table('numbering_counters')->insertOrIgnore(['series_id' => $series->id, 'period' => $period, 'value' => 0]);
            $counter = DB::table('numbering_counters')->where('series_id', $series->id)->where('period', $period);
            if ($counter->where('value', '<', NumberingFormat::MAX_SEQUENCE)->increment('value') !== 1) {
                throw ValidationException::withMessages(['series' => 'numberingExhausted']);
            }
            $sequence = (int) DB::table('numbering_counters')->where('series_id', $series->id)->where('period', $period)->lockForUpdate()->value('value');
            $number = NumberingFormat::render($configuration->prefix, $configuration->padding, $year, $sequence, $configuration->format);
            $shared = null;
            $registerFormat = $xppl !== null && in_array($xppl['format'] ?? null, ['xppl_incoming', 'xppl_outgoing'], true);
            if ($xppl !== null) {
                if ($registerFormat) {
                    $masters = $this->xpplNumbering->internalMasters($scope->company_id, $series->id);
                    if ($masters['format'] !== $xppl['format'] || ! $this->xpplNumbering->policyActive($scope->company_id, $series->register->register_key->value)) {
                        $this->xpplNumbering->invalid();
                    }
                    $namespaceId = DB::table('xppl_numbering_namespaces')->where('company_id', $scope->company_id)->where('prefix', $masters['prefix'])->value('id');
                } else {
                    $masters = $this->xpplNumbering->masters($scope->company_id, $xppl + ['series_id' => $series->id,
                        'format' => $series->purpose === NumberingPurpose::OfficialOutgoing ? 'xppl_letter' : 'xppl_controlled']);
                    $mapping = DB::table('xppl_numbering_mappings')->where('namespace_id', $xppl['namespace_id'])
                        ->where('series_id', $series->id)->where('department_id', $masters['department_id'])->where('type_id', $masters['type_id'])->first();
                    if (! $mapping || $mapping->master_hash !== $masters['master_hash']) {
                        $this->xpplNumbering->invalid();
                    }
                    $namespaceId = $mapping->namespace_id;
                }
                $report = $this->xpplNumbering->reconcile($masters);
                if (! $report['confirmed'] || $report['ambiguous'] || $report['collision_count'] || $report['next_sequence'] > NumberingFormat::MAX_SEQUENCE) {
                    $this->xpplNumbering->invalid();
                }
                $shared = ['namespace_id' => $namespaceId, 'period' => $report['period'], 'sequence' => $report['next_sequence'], 'format' => $masters['format']];
                $counter = DB::table('xppl_numbering_counters')->where('namespace_id', $namespaceId)->where('period', $report['period']);
                if ($counter->where('value', $report['next_sequence'] - 1)->increment('value') !== 1) {
                    $this->xpplNumbering->invalid();
                }
                $number = $report['preview'];
            }
            $record = $persistRegistration($number);
            if (! $record instanceof Model || ! $record instanceof FormalNumberedRegistration || ! $record->exists || $record->getConnection() !== DB::connection()) {
                throw new LogicException('Registration must persist a formal record on the numbering connection.');
            }
            $record = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if (! $record->isFormallyRegistered() || $record->numberingScopeId() !== $scope->id || $record->numberingRegisterId() !== $register->id || $record->registeredNumber($series->purpose) !== $number) {
                throw new LogicException('Formal registration ownership or state does not match the numbering series.');
            }
            if ($registerFormat && ! ($record instanceof IncomingCorrespondence || $record instanceof OutgoingCorrespondence)) {
                $this->xpplNumbering->invalid();
            }
            if ($xppl !== null && ! $registerFormat && (! $record instanceof ControlledDocument && ! $record instanceof OutgoingCorrespondence
                || $record->profile->department_id !== $xppl['department_id'] || $record->type_id !== $xppl['type_id'])) {
                $this->xpplNumbering->invalid();
            }
            if ($record->getKey() === null || mb_strlen((string) $record->getKey()) > 128) {
                throw new LogicException('Use bounded opaque registration identities.');
            }

            $issued = IssuedDocumentNumber::query()->create([
                'series_id' => $series->id, 'configuration_id' => $configuration->id, 'period' => $period,
                'year' => $year, 'sequence' => $sequence, 'number' => $number, 'issued_by' => $actor->id,
                'record_type' => $register->register_key->value, 'record_key' => (string) $record->getKey(),
            ]);
            if (! $issued->exists) {
                throw new LogicException('Issuance history persistence was cancelled.');
            }

            if ($shared !== null) {
                DB::table('xppl_numbering_allocations')->insert($shared + ['issuance_id' => $issued->id]);
            }

            return $issued;
        }, 1);
    }
}
