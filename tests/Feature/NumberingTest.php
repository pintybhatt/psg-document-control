<?php

namespace Tests\Feature;

use App\Authorization\DocumentAccess;
use App\Authorization\DocumentNumbering;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\ManageNumbering;
use App\Authorization\NumberingFormat;
use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\DocumentSecurityProfile;
use App\Models\IssuedDocumentNumber;
use App\Models\NumberingConfiguration;
use App\Models\NumberingSeries;
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithRbac;
use Tests\Fixtures\FormalRegistrationRecord;
use Tests\TestCase;

class NumberingTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{User, AuthorizationScope, RegisterConfiguration} */
    private function actor(array $keys = ['numbering.view', 'numbering.manage', 'numbering.issue']): array
    {
        $this->definitions();
        config(['app.debug' => false]);
        FormalRegistrationRecord::createTestTable();
        $this->travelTo(now()->setDate(2026, 10, 26)->startOfDay());
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $user = User::factory()->create();
        OrganizationMembership::factory()->for($user)->atSite($site)->create();
        $this->grant($user, $scope, $keys);
        $this->signIn($user);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'outgoing']);

        return [$user, $scope, $register];
    }

    private function data(RegisterConfiguration $register, array $extra = []): array
    {
        return array_replace(['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'EXAMPLE/OUT', 'padding' => 6, 'active' => true], $extra);
    }

    private function series(User $actor, AuthorizationScope $scope, RegisterConfiguration $register, array $extra = []): NumberingSeries
    {
        return app(ManageNumbering::class)->save($actor, $scope, $this->data($register, $extra));
    }

    private function issue(User $actor, AuthorizationScope $scope, NumberingSeries $series): IssuedDocumentNumber
    {
        return DB::transaction(fn () => app(DocumentNumbering::class)->register($actor, $scope, $series->id, fn (string $number) => FormalRegistrationRecord::query()->create([
            'scope_id' => $scope->id, 'register_id' => $series->register_id, 'state' => 'registered',
            $series->purpose->value === 'internal_register' ? 'internal_number' : 'official_number' => $number,
            'external_reference' => 'EXTERNAL-REPEATABLE',
        ])));
    }

    #[TestWith(['annual', 'EXAMPLE/OUT/2027/0001'])]
    #[TestWith(['continuous', 'EXAMPLE/OUT/2027/0002'])]
    public function test_saved_series_preview_uses_its_counter_without_consuming_or_changing_history(string $policy, string $expected): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register, ['reset_policy' => $policy, 'padding' => 4]);
        $issued = $this->issue($actor, $scope, $series);
        $body = $this->scopeData($scope) + ['series_id' => $series->id];
        $this->postJson('/api/v1/numbering-series/preview', $body)->assertOk()->assertJsonPath('number', 'EXAMPLE/OUT/2026/0002');
        $this->travelTo(now()->setDate(2027, 1, 1));

        $this->postJson('/api/v1/numbering-series/preview', $body)->assertOk()->assertJsonPath('number', $expected);

        $this->assertDatabaseCount('numbering_counters', 1);
        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->assertSame('EXAMPLE/OUT/2026/0001', $issued->refresh()->number);
        $this->assertSame($expected, $this->issue($actor, $scope, $series)->number);
    }

    public function test_saved_series_preview_is_scoped_and_rejects_format_overrides(): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register);
        $body = $this->scopeData($scope) + ['series_id' => $series->id];
        foreach (['prefix' => 'OTHER', 'padding' => 4, 'year' => 2027, 'sequence' => 5] as $field => $value) {
            $this->postJson('/api/v1/numbering-series/preview', $body + [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $foreign = NumberingSeries::factory()->create();
        foreach ([$foreign->id, 999999] as $id) {
            $this->postJson('/api/v1/numbering-series/preview', array_replace($body, ['series_id' => $id]))->assertNotFound();
        }
        $this->assertDatabaseCount('numbering_counters', 0);
    }

    public function test_saved_series_preview_starts_at_one_without_creating_a_counter(): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register, ['padding' => 4]);
        $body = $this->scopeData($scope) + ['series_id' => $series->id];
        $this->postJson('/api/v1/numbering-series/preview', $body)->assertOk()->assertJsonPath('number', 'EXAMPLE/OUT/2026/0001');
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
    }

    public function test_saved_series_preview_requires_view_permission(): void
    {
        [$actor, $scope, $register] = $this->actor(['numbering.manage']);
        $series = $this->series($actor, $scope, $register);
        $this->postJson('/api/v1/numbering-series/preview', $this->scopeData($scope) + ['series_id' => $series->id])->assertForbidden();
        $this->assertDatabaseCount('numbering_counters', 0);
    }

    public function test_configuration_preview_and_history_never_consume_numbers(): void
    {
        [, $scope, $register] = $this->actor();
        $body = $this->scopeData($scope) + $this->data($register);
        $id = $this->postJson('/api/v1/numbering-series', $body)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/numbering-series/preview', $this->scopeData($scope) + ['prefix' => 'EXAMPLE/OUT', 'padding' => 6, 'year' => 2026])->assertOk()->assertJsonPath('number', 'EXAMPLE/OUT/2026/000001');
        $this->patchJson('/api/v1/numbering-series/'.$id, $this->scopeData($scope) + ['prefix' => 'EXAMPLE/NEW', 'padding' => 4, 'active' => false, 'change_reason' => 'Reviewed configuration'])->assertOk()->assertJsonPath('data.configuration.version', 2);
        $this->getJson($this->scopedUrl('numbering-series/'.$id.'/history', $scope))->assertOk()->assertJsonPath('data.0.change_reason', 'Reviewed configuration')->assertJsonPath('data.1.prefix', 'EXAMPLE/OUT');
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertSame(auth()->id(), NumberingConfiguration::query()->latest('id')->first()->created_by);
    }

    #[TestWith(['annual', 1])]
    #[TestWith(['continuous', 3])]
    public function test_year_rollover_and_format_changes_keep_the_correct_sequence(string $policy, int $next): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register, ['reset_policy' => $policy]);
        $first = $this->issue($actor, $scope, $series);
        $this->assertSame('EXAMPLE/OUT/2026/000001', $first->number);
        app(ManageNumbering::class)->save($actor, $scope, ['prefix' => 'EXAMPLE/NEW', 'padding' => 2, 'active' => true], (string) $series->id);
        $this->assertSame('EXAMPLE/NEW/2026/02', $this->issue($actor, $scope, $series)->number);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $issued = $this->issue($actor, $scope, $series);
        $this->assertSame($next, $issued->sequence);
        $this->assertSame($policy === 'annual' ? 2027 : 0, $issued->period);
        $this->assertSame('EXAMPLE/OUT/2026/000001', $first->refresh()->number);
        $this->assertDatabaseCount('issued_document_numbers', 3);
        $this->assertSame(3, FormalRegistrationRecord::query()->where('external_reference', 'EXTERNAL-REPEATABLE')->count());
    }

    public function test_internal_and_official_numbers_are_separate_for_the_same_record(): void
    {
        [$actor, $scope, $register] = $this->actor();
        $internal = $this->series($actor, $scope, $register);
        $official = $this->series($actor, $scope, $register, ['purpose' => 'official_outgoing', 'prefix' => 'EXAMPLE/OFFICIAL']);
        $first = $this->issue($actor, $scope, $internal);
        $record = FormalRegistrationRecord::query()->sole();
        $second = DB::transaction(fn () => app(DocumentNumbering::class)->register($actor, $scope, $official->id, function (string $number) use ($record): FormalRegistrationRecord {
            $record->update(['official_number' => $number]);

            return $record;
        }));
        $this->assertSame(1, $first->sequence);
        $this->assertSame(1, $second->sequence);
        $this->assertSame('EXAMPLE/OFFICIAL/2026/000001', $record->refresh()->official_number);
        $this->assertSame($first->number, $record->internal_number);
    }

    #[TestWith(['throw'])]
    #[TestWith(['draft'])]
    #[TestWith(['wrong-scope'])]
    #[TestWith(['wrong-register'])]
    #[TestWith(['unsaved'])]
    #[TestWith(['wrong-number'])]
    public function test_failed_or_invalid_registration_rolls_back_even_when_the_outer_caller_catches_it(string $failure): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register);
        DB::transaction(function () use ($actor, $scope, $series, $failure): void {
            try {
                app(DocumentNumbering::class)->register($actor, $scope, $series->id, function (string $number) use ($scope, $series, $failure): FormalRegistrationRecord {
                    $record = new FormalRegistrationRecord(['scope_id' => $failure === 'wrong-scope' ? 999 : $scope->id,
                        'register_id' => $failure === 'wrong-register' ? 999 : $series->register_id,
                        'state' => $failure === 'draft' ? 'draft' : 'registered', 'internal_number' => $failure === 'wrong-number' ? 'NOT-THE-ALLOCATION' : $number]);
                    if ($failure !== 'unsaved') {
                        $record->save();
                    }
                    if ($failure === 'throw') {
                        throw new \RuntimeException('Registration persistence failed');
                    }

                    return $record;
                });
                $this->fail('Invalid registration succeeded.');
            } catch (LogicException|\RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        });
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('test_formal_numbering_records', 0);
        $this->assertSame(1, $this->issue($actor, $scope, $series)->sequence);
    }

    public function test_allocation_requires_an_outer_registration_transaction_and_outer_rollback_restores_everything(): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register);
        DB::beginTransaction();
        try {
            $this->issue($actor, $scope, $series);
            $this->assertDatabaseCount('issued_document_numbers', 1);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertSame(1, $this->issue($actor, $scope, $series)->sequence);
    }

    public function test_duplicate_series_and_permanently_reserved_prefixes_are_rejected_without_foreign_disclosure(): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register);
        $this->postJson('/api/v1/numbering-series', $this->scopeData($scope) + $this->data($register, ['prefix' => 'DIFFERENT']))->assertUnprocessable();
        $this->postJson('/api/v1/numbering-series', $this->scopeData($scope) + $this->data($register, ['purpose' => 'official_outgoing']))->assertUnprocessable();
        app(ManageNumbering::class)->save($actor, $scope, ['prefix' => 'EXAMPLE/NEW', 'padding' => 6, 'active' => false], (string) $series->id);
        $this->postJson('/api/v1/numbering-series', $this->scopeData($scope) + $this->data($register, ['purpose' => 'official_outgoing']))->assertUnprocessable();
        $this->assertDatabaseCount('numbering_series', 1);
        $this->assertDatabaseCount('numbering_namespaces', 2);
    }

    #[TestWith(['prefix', 'bad/{expression}'])]
    #[TestWith(['prefix', 'EXAMPLE//OUT'])]
    #[TestWith(['prefix', 'EXAMPLE/'])]
    #[TestWith(['padding', 0])]
    #[TestWith(['padding', 13])]
    #[TestWith(['reset_policy', 'arbitrary'])]
    #[TestWith(['purpose', 'external_reference'])]
    public function test_invalid_numbering_configuration_is_rejected(string $field, mixed $value): void
    {
        [, $scope, $register] = $this->actor();
        $this->postJson('/api/v1/numbering-series', $this->scopeData($scope) + $this->data($register, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('numbering_series', 0);
    }

    public function test_padding_is_a_minimum_width_and_official_purpose_is_outgoing_only(): void
    {
        $this->assertSame('EXAMPLE/2026/1234', NumberingFormat::render('EXAMPLE', 2, 2026, 1234));
        [, $scope] = $this->actor();
        $incoming = RegisterConfiguration::factory()->create(['scope_id' => $scope->id]);
        $this->postJson('/api/v1/numbering-series', $this->scopeData($scope) + $this->data($incoming, ['purpose' => 'official_outgoing']))->assertUnprocessable();
    }

    public function test_guests_read_only_and_central_identity_administrators_cannot_mutate_or_issue(): void
    {
        $this->getJson('/api/v1/numbering-series')->assertUnauthorized();
        [$actor, $scope, $register] = $this->actor(['numbering.view']);
        $this->getJson($this->scopedUrl('numbering-series', $scope))->assertOk();
        $this->postJson('/api/v1/numbering-series', $this->scopeData($scope) + $this->data($register))->assertForbidden();
        $this->grant($actor, AuthorizationScope::context('global'), PermissionCatalog::ADMINISTRATOR_KEYS);
        $this->postJson('/api/v1/numbering-series', $this->scopeData($scope) + $this->data($register))->assertForbidden();
    }

    #[TestWith(['membership'])]
    #[TestWith(['role'])]
    #[TestWith(['site'])]
    #[TestWith(['company'])]
    public function test_revocations_immediately_block_configuration_and_issuance(string $kind): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register);
        match ($kind) {
            'membership' => $actor->memberships()->sole()->update(['active' => false]),
            'role' => $actor->roleAssignments()->sole()->update(['active' => false]),
            'site' => $scope->site->update(['active' => false]),
            'company' => $scope->company->update(['active' => false]),
        };
        $this->getJson($this->scopedUrl('numbering-series', $scope))->assertForbidden();
        try {
            $this->issue($actor, $scope, $series);
            $this->fail('Revoked issuance succeeded.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('numbering_counters', 0);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_foreign_series_and_registers_are_hidden_from_local_scope(bool $sameCompany): void
    {
        [$actor, $scope, $register] = $this->actor();
        $site = $sameCompany ? Site::factory()->create(['company_id' => $scope->company_id]) : Site::factory()->create();
        $otherScope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $other = NumberingSeries::factory()->create(['scope_id' => $otherScope->id, 'register_id' => RegisterConfiguration::factory()->create(['scope_id' => $otherScope->id])->id]);
        $this->getJson($this->scopedUrl('numbering-series', $otherScope))->assertForbidden();
        $body = $this->scopeData($scope) + ['prefix' => 'EXAMPLE', 'padding' => 6, 'active' => true];
        $known = $this->patchJson('/api/v1/numbering-series/'.$other->id, $body)->assertNotFound();
        $absent = $this->patchJson('/api/v1/numbering-series/999999', $body)->assertNotFound();
        $this->assertSame($known->json(), $absent->json());
        $this->getJson($this->scopedUrl('numbering-series/'.$other->id.'/history', $scope))->assertNotFound();
        $this->postJson('/api/v1/numbering-series', $this->scopeData($scope) + $this->data($register, ['register_id' => $other->register_id]))->assertUnprocessable();
    }

    public function test_identity_counter_and_audit_fields_are_not_editable_and_mutations_require_csrf(): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register);
        foreach (['register_id' => $register->id, 'purpose' => 'official_outgoing', 'reset_policy' => 'continuous', 'counter' => 10, 'created_by' => 1, 'version' => 20] as $field => $value) {
            $this->patchJson('/api/v1/numbering-series/'.$series->id, $this->scopeData($scope) + ['prefix' => 'EXAMPLE', 'padding' => 6, 'active' => true, $field => $value])->assertUnprocessable();
        }
        $this->postJson('/api/v1/numbering-series/allocate', $this->scopeData($scope))->assertStatus(405);
        $this->app['env'] = 'local';
        $this->postJson('/api/v1/numbering-series/preview', $this->scopeData($scope) + ['prefix' => 'EXAMPLE', 'padding' => 6, 'year' => 2026])->assertStatus(419);
    }

    #[TestWith(['numbering_configurations', 'update'])]
    #[TestWith(['numbering_configurations', 'delete'])]
    #[TestWith(['issued_document_numbers', 'update'])]
    #[TestWith(['issued_document_numbers', 'delete'])]
    #[TestWith(['numbering_counters', 'rewind'])]
    public function test_database_protects_history_and_counter_integrity(string $table, string $action): void
    {
        [$actor, $scope, $register] = $this->actor();
        $this->issue($actor, $scope, $this->series($actor, $scope, $register));
        $this->expectException(QueryException::class);
        if ($action === 'delete') {
            DB::table($table)->delete();
        } else {
            DB::table($table)->update($action === 'rewind' ? ['value' => 0] : ['created_at' => now()]);
        }
    }

    public function test_configuration_or_issuance_history_failure_rolls_back_all_mutations(): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register);
        $event = 'eloquent.creating: '.IssuedDocumentNumber::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Test issuance audit failure');
        });
        try {
            $this->issue($actor, $scope, $series);
            $this->fail('Unaudited issuance succeeded.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Test issuance audit failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('test_formal_numbering_records', 0);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertSame(1, $this->issue($actor, $scope, $series)->sequence);
    }

    #[TestWith(['configuration'])]
    #[TestWith(['issuance'])]
    public function test_cancelled_history_inserts_fail_closed(string $kind): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register);
        $class = $kind === 'configuration' ? NumberingConfiguration::class : IssuedDocumentNumber::class;
        $event = 'eloquent.creating: '.$class;
        Event::listen($event, fn (): bool => false);
        try {
            if ($kind === 'configuration') {
                app(ManageNumbering::class)->save($actor, $scope, ['prefix' => 'EXAMPLE/CHANGE', 'padding' => 6, 'active' => true], (string) $series->id);
            } else {
                $this->issue($actor, $scope, $series);
            }
            $this->fail('Cancelled history insert was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cancelled', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('numbering_configurations', 1);
        $this->assertDatabaseCount('numbering_namespaces', 1);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('test_formal_numbering_records', 0);
    }

    public function test_deactivation_and_reactivation_preserve_the_counter_and_block_inactive_registers(): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register);
        $this->issue($actor, $scope, $series);
        app(ManageNumbering::class)->save($actor, $scope, ['prefix' => 'EXAMPLE/OUT', 'padding' => 6, 'active' => false], (string) $series->id);
        try {
            $this->issue($actor, $scope, $series);
            $this->fail('Inactive series issued a number.');
        } catch (ValidationException) {
            $this->assertSame(1, DB::table('numbering_counters')->sole()->value);
        }
        app(ManageNumbering::class)->save($actor, $scope, ['prefix' => 'EXAMPLE/OUT', 'padding' => 6, 'active' => true], (string) $series->id);
        $this->assertSame(2, $this->issue($actor, $scope, $series)->sequence);
        $register->update(['active' => false]);
        $this->getJson($this->scopedUrl('numbering-series', $scope))->assertOk()->assertJsonPath('data.0.effective', false);
        $this->expectException(ValidationException::class);
        $this->issue($actor, $scope, $series);
    }

    public function test_repeated_registration_cannot_rewrite_an_existing_number_or_consume_a_sequence(): void
    {
        [$actor, $scope, $register] = $this->actor();
        $series = $this->series($actor, $scope, $register);
        $issued = $this->issue($actor, $scope, $series);
        $record = FormalRegistrationRecord::query()->sole();
        try {
            DB::transaction(fn () => app(DocumentNumbering::class)->register($actor, $scope, $series->id, function (string $number) use ($record): FormalRegistrationRecord {
                $record->update(['internal_number' => $number]);

                return $record;
            }));
            $this->fail('A formal record received a second number for the same purpose.');
        } catch (QueryException) {
            $this->assertSame($issued->number, $record->refresh()->internal_number);
            $this->assertSame(1, DB::table('numbering_counters')->sole()->value);
        }
        $this->assertSame(2, $this->issue($actor, $scope, $series)->sequence);
    }

    public function test_management_permission_does_not_allow_issuance_or_document_reading(): void
    {
        [$actor, $scope, $register] = $this->actor(['numbering.view', 'numbering.manage']);
        $series = $this->series($actor, $scope, $register);
        try {
            $this->issue($actor, $scope, $series);
            $this->fail('Configuration permission issued a number.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $profile = DocumentSecurityProfile::factory()->create(['company_id' => $scope->company_id, 'site_id' => $scope->site_id]);
        $this->assertFalse(app(DocumentAccess::class)->allows($actor, DocumentSecurityDescriptor::fromProfile($profile), 'metadata'));
        $this->assertDatabaseCount('numbering_counters', 0);
    }

    public function test_company_configuration_and_numbering_require_exact_company_membership(): void
    {
        [$actor, $siteScope, $siteRegister] = $this->actor();
        $siteSeries = $this->series($actor, $siteScope, $siteRegister);
        $scope = AuthorizationScope::context('company', $siteScope->company_id)->persist();
        $this->grant($actor, $scope, ['numbering.view', 'numbering.manage', 'numbering.issue']);
        $this->getJson($this->scopedUrl('numbering-series', $scope))->assertForbidden();
        $actor->memberships()->sole()->update(['active' => false]);
        OrganizationMembership::factory()->for($actor)->create(['company_id' => $scope->company_id]);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id]);
        $series = $this->series($actor, $scope, $register, ['prefix' => 'EXAMPLE/COMPANY']);
        $this->assertSame('EXAMPLE/COMPANY/2026/000001', $this->issue($actor, $scope, $series)->number);
        $this->getJson($this->scopedUrl('numbering-series', $siteScope))->assertForbidden();
        $this->expectException(HttpException::class);
        $this->issue($actor, $siteScope, $siteSeries);
    }

    #[TestWith(['configuration'])]
    #[TestWith(['issuance'])]
    public function test_numbering_services_cannot_use_a_foreign_scope_id_with_local_authorization_attributes(string $operation): void
    {
        [$actor, $scope] = $this->actor();
        [$foreignActor, $foreignScope, $foreignRegister] = $this->actor();
        $series = $this->series($foreignActor, $foreignScope, $foreignRegister);
        $forgedScope = clone $scope;
        $forgedScope->id = $foreignScope->id;

        try {
            if ($operation === 'configuration') {
                app(ManageNumbering::class)->save($actor, $forgedScope, ['prefix' => 'EXAMPLE/FORGED', 'padding' => 4, 'active' => false], (string) $series->id);
            } else {
                $this->issue($actor, $forgedScope, $series);
            }
            $this->fail('Local authorization attributes allowed a foreign numbering operation.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('numbering_configurations', 1);
        $this->assertDatabaseCount('numbering_namespaces', 1);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('test_formal_numbering_records', 0);
    }

    #[TestWith(['numbering_configurations'])]
    #[TestWith(['issued_document_numbers'])]
    public function test_replacement_statements_cannot_rewrite_numbering_history(string $table): void
    {
        [$actor, $scope, $register] = $this->actor();
        $this->issue($actor, $scope, $this->series($actor, $scope, $register));
        $row = (array) DB::table($table)->sole();
        $row[$table === 'numbering_configurations' ? 'prefix' : 'number'] = 'REWRITTEN';
        $columns = implode(', ', array_keys($row));
        $parameters = implode(', ', array_fill(0, count($row), '?'));
        $verb = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
        $this->expectException(QueryException::class);
        DB::statement("{$verb} INTO {$table} ({$columns}) VALUES ({$parameters})", array_values($row));
    }

    #[TestWith(['internal_register', 'incoming'])]
    #[TestWith(['internal_register', 'controlled'])]
    public function test_internal_numbering_supports_the_other_fixed_registers(string $purpose, string $identity): void
    {
        [$actor, $scope] = $this->actor();
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => $identity]);
        $series = $this->series($actor, $scope, $register, ['purpose' => $purpose, 'prefix' => 'EXAMPLE/REGISTER']);
        $this->assertSame(1, $this->issue($actor, $scope, $series)->sequence);
    }
}
