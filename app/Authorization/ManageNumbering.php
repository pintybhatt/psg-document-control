<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\NumberingConfiguration;
use App\Models\NumberingSeries;
use App\Models\RegisterConfiguration;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageNumbering
{
    public function __construct(private AccessControl $access) {}

    /** @param array<string, mixed> $data */
    public function save(User $actor, AuthorizationScope $scope, array $data, ?string $id = null): NumberingSeries
    {
        return $this->saveAuthorized($actor, $scope, $data, $id);
    }

    public function forControlledPolicy(User $actor, DocumentScope $unit): NumberingSeries
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Policy supporting configuration requires the registration transaction.');
        }
        abort_unless(app(XpplNumbering::class)->policyActive($unit->companyId), 403);
        app(ManageControlledDocuments::class)->authorizeContext($actor, $unit, 'controlled.register');
        $scope = $unit->authorizationScope();
        $this->access->authorize($actor, 'numbering.issue', $scope);
        $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', 'controlled')->where('active', true)->firstOrFail();

        return $this->saveAuthorized($actor, $scope, [
            'register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'continuous',
            'prefix' => 'XPPL/DCMS/REGISTER/'.$register->id, 'padding' => 4, 'active' => true,
            'change_reason' => 'Supporting configuration under activated company-wide XPPL policy',
        ], null, true);
    }

    /** @param array<string, mixed> $data */
    private function saveAuthorized(User $actor, AuthorizationScope $scope, array $data, ?string $id = null, bool $companyPolicy = false): NumberingSeries
    {
        try {
            return DB::transaction(function () use ($actor, $scope, $data, $id, $companyPolicy): NumberingSeries {
                if ($id !== null && DB::getDriverName() === 'sqlite') {
                    DB::table('numbering_series')->where('scope_id', $scope->id)->where('id', $id)->update(['id' => DB::raw('id')]);
                }
                $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);
                if (! $companyPolicy) {
                    $this->access->authorize($actor, 'numbering.manage', $scope);
                }
                $series = $id === null ? null : NumberingSeries::query()->where('scope_id', $scope->id)->whereKey($id)->lockForUpdate()->first();
                abort_if($id !== null && ! $series, 404);
                $current = $series?->configurations()->orderByDesc('version')->lockForUpdate()->first();
                $validated = Validator::make($data, NumberingFormat::rules() + [
                    'active' => ['required', 'boolean'], 'change_reason' => ['nullable', 'string', 'max:500'],
                    'register_id' => $series ? ['prohibited'] : ['required', 'integer'],
                    'purpose' => $series ? ['prohibited'] : ['required', Rule::enum(NumberingPurpose::class)],
                    'reset_policy' => $series ? ['prohibited'] : ['required', Rule::enum(NumberingResetPolicy::class)],
                ], array_fill_keys(['required', 'string', 'max', 'min', 'regex', 'integer', 'boolean', 'prohibited'], 'numberingInvalidFormat'))->validate();
                if (! $series) {
                    $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->whereKey($validated['register_id'])->first();
                    if (! $register || ! $register->active || ($validated['purpose'] === NumberingPurpose::OfficialOutgoing->value && $register->register_key !== DocumentResourceType::Outgoing)) {
                        throw ValidationException::withMessages(['register_id' => 'numberingInvalidRegister']);
                    }
                    $series = new NumberingSeries(['scope_id' => $scope->persist()->id, 'register_id' => $register->id, 'purpose' => $validated['purpose'], 'reset_policy' => $validated['reset_policy']]);
                    $series->created_by = $actor->id;
                    if (! $series->save()) {
                        throw new \LogicException('Numbering series persistence was cancelled.');
                    }
                }
                if ($current && $current->prefix === $validated['prefix'] && $current->padding === (int) $validated['padding'] && $current->active === (bool) $validated['active']) {
                    return $series->load(['register', 'currentConfiguration']);
                }
                DB::table('numbering_namespaces')->insertOrIgnore(['prefix' => $validated['prefix'], 'series_id' => $series->id]);
                $namespace = DB::table('numbering_namespaces')->where('prefix', $validated['prefix'])->lockForUpdate()->first();
                if (! $namespace || (int) $namespace->series_id !== $series->id) {
                    throw ValidationException::withMessages(['prefix' => 'numberingConflict']);
                }
                $configuration = new NumberingConfiguration([
                    'series_id' => $series->id, 'version' => ($current?->version ?? 0) + 1,
                    'prefix' => $validated['prefix'], 'padding' => $validated['padding'], 'active' => $validated['active'], 'change_reason' => $validated['change_reason'] ?? null,
                ]);
                $configuration->created_by = $actor->id;
                $configuration->effective_at = now();
                if (! $configuration->save()) {
                    throw new \LogicException('Numbering history persistence was cancelled.');
                }

                return $series->load(['register', 'currentConfiguration']);
            }, 1);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['series' => 'numberingConflict']);
        }
    }
}
