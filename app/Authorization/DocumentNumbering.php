<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\IssuedDocumentNumber;
use App\Models\NumberingSeries;
use App\Models\RegisterConfiguration;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class DocumentNumbering
{
    public function __construct(private AccessControl $access) {}

    /**
     * Trusted formal-registration integration only. The callback must persist the formal
     * record on this connection and perform no external side effects. Returned numbers
     * are provisional until the caller's outer transaction commits. Never call for drafts.
     *
     * @param  Closure(string): (Model&FormalNumberedRegistration)  $persistRegistration
     */
    public function register(User $actor, AuthorizationScope $scope, int $seriesId, Closure $persistRegistration): IssuedDocumentNumber
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Formal registration requires a caller-owned database transaction.');
        }

        return DB::transaction(function () use ($actor, $scope, $seriesId, $persistRegistration): IssuedDocumentNumber {
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
            $record = $persistRegistration($number);
            if (! $record instanceof Model || ! $record instanceof FormalNumberedRegistration || ! $record->exists || $record->getConnection() !== DB::connection()) {
                throw new LogicException('Registration must persist a formal record on the numbering connection.');
            }
            $record = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if (! $record->isFormallyRegistered() || $record->numberingScopeId() !== $scope->id || $record->numberingRegisterId() !== $register->id || $record->registeredNumber($series->purpose) !== $number) {
                throw new LogicException('Formal registration ownership or state does not match the numbering series.');
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

            return $issued;
        }, 1);
    }
}
