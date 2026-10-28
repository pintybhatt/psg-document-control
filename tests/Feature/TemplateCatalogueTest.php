<?php

namespace Tests\Feature;

use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageNumbering;
use App\Authorization\TemplateCatalogue;
use App\Models\AttachmentVersion;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\ControlledDocument;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateAudit;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;
use ZipArchive;

class TemplateCatalogueTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    private function catalogueFixture(string $classification = 'normal'): array
    {
        [$record, $actor, $role] = $this->fixture('controlled', $classification);
        $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', ['templates.view', 'templates.manage'])->pluck('id'));

        return [$record, $actor, $role];
    }

    private function scope(ControlledDocument $record): AuthorizationScope
    {
        return $record->profile->documentScope()->authorizationScope();
    }

    private function catalogueUrl(AuthorizationScope $scope, string $suffix = ''): string
    {
        return $this->scopedUrl('document-templates'.$suffix, $scope);
    }

    private function data(?string $version = null): array
    {
        return ['name_en' => 'Synthetic template candidate', 'name_lo' => 'ແບບທົດສອບ', 'description_en' => 'Not an approved XPPL template', 'purpose' => 'Synthetic purpose', 'attachment_version_id' => $version];
    }

    private function createEntry(AuthorizationScope $scope, ?string $version = null): array
    {
        return $this->postJson('/api/v1/document-templates', $this->scopeData($scope) + $this->data($version))->assertCreated()->json('data');
    }

    /** @return array{string, string} */
    private function source(ControlledDocument $record, User $actor, bool $release = true): array
    {
        $path = tempnam(sys_get_temp_dir(), 'template-test-');
        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
            $zip->addFromString('word/document.xml', '<?xml version="1.0"?><document/>');
            $zip->close();
            $bytes = file_get_contents($path);
        } finally {
            unlink($path);
        }
        $revision = $record->revisions()->sole();
        $version = $this->postJson($this->url($record, type: 'controlled', extra: ['revision_id' => $revision->id]), ['file' => UploadedFile::fake()->createWithContent('synthetic.docx', $bytes), 'reason' => 'Synthetic editable source'])->assertCreated()->json('data.versions.0.id');
        if ($release) {
            $this->releaseSource($record, $actor);
        }

        return [$version, $bytes];
    }

    private function releaseSource(ControlledDocument $record, User $actor): void
    {
        $scope = $record->profile->documentScope();
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'TEST/TEMPLATE', 'padding' => 4, 'active' => true]);
        $manager = app(ManageControlledDocuments::class);
        $record = $manager->register($actor, $scope, (string) $record->id, ['lock_version' => 1]);
        $manager->release($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $record->revisions()->sole()->id, 'reason' => 'Synthetic release']);
    }

    public function test_metadata_creation_is_inactive_audited_and_does_not_seed_purposes(): void
    {
        [$record, $actor] = $this->catalogueFixture();
        $scope = $this->scope($record);
        $this->assertDatabaseCount('document_templates', 0);
        $entry = $this->createEntry($scope);
        $this->assertSame('inactive', $entry['status']);
        $this->assertNull($entry['attachment_version_id']);
        $this->assertArrayNotHasKey('download_url', $entry);
        $this->getJson($this->catalogueUrl($scope))->assertOk()->assertJsonPath('publication_enabled', false)->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('document_template_audits', ['template_id' => $entry['id'], 'actor_id' => $actor->id, 'action' => 'created']);
        $this->assertDatabaseCount('numbering_counters', 0);
    }

    #[TestWith(['name_en', ''])]
    #[TestWith(['name_lo', ''])]
    #[TestWith(['purpose', ''])]
    #[TestWith(['purpose', 12])]
    #[TestWith(['attachment_version_id', 'invalid'])]
    #[TestWith(['status', 'active'])]
    #[TestWith(['published_at', '2026-10-28'])]
    #[TestWith(['scope_id', 999])]
    #[TestWith(['created_by', 999])]
    public function test_invalid_or_server_controlled_fields_are_rejected(string $field, mixed $value): void
    {
        [$record] = $this->catalogueFixture();
        $this->postJson('/api/v1/document-templates', $this->scopeData($this->scope($record)) + array_replace($this->data(), [$field => $value]))->assertUnprocessable();
        $this->assertDatabaseCount('document_templates', 0);
        $this->assertDatabaseCount('document_template_audits', 0);
    }

    public function test_authorized_immutable_docx_reference_reuses_existing_secure_download_and_logs_access(): void
    {
        [$record, $actor, $role] = $this->catalogueFixture();
        [$version, $bytes] = $this->source($record, $actor);
        $before = AttachmentVersion::findOrFail($version)->getRawOriginal();
        $scope = $this->scope($record);
        $this->getJson($this->catalogueUrl($scope, '/sources').'&q=synthetic')->assertOk()->assertJsonPath('data.0.id', $version)->assertJsonPath('data.0.revision', 'R00');
        $entry = $this->createEntry($scope, $version);
        $this->assertSame($version, $entry['attachment_version_id']);
        $download = $this->url($record, 'versions/'.$version.'/download', 'controlled', ['revision_id' => $record->revisions()->sole()->id]);
        $response = $this->get($download)->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertDatabaseHas('storage_events', ['action' => 'attachment.download', 'actor_id' => $actor->id, 'subject_id' => $version]);
        $this->assertSame($before, AttachmentVersion::findOrFail($version)->getRawOriginal());
        $role->permissions()->detach(Permission::where('key', 'documents.attachments.download')->value('id'));
        $this->getJson($download)->assertNotFound();
        $this->getJson($this->catalogueUrl($scope))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->catalogueUrl($scope, '/'.$entry['id']))->assertNotFound();
    }

    public function test_unreleased_non_docx_and_missing_sources_are_not_eligible(): void
    {
        [$record, $actor] = $this->catalogueFixture();
        [$version] = $this->source($record, $actor, false);
        $scope = $this->scope($record);
        $this->postJson('/api/v1/document-templates', $this->scopeData($scope) + $this->data($version))->assertNotFound();
        $this->getJson($this->catalogueUrl($scope, '/sources').'&q=synthetic')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/document-templates', $this->scopeData($scope) + $this->data('00000000-0000-4000-8000-000000000000'))->assertNotFound();
        $this->postJson($this->url($record, type: 'controlled', extra: ['revision_id' => $record->revisions()->sole()->id]), ['file' => UploadedFile::fake()->createWithContent('synthetic.dotx', 'not supported'), 'reason' => 'Unsupported extension'])->assertUnprocessable();
        $pdf = $this->postJson($this->url($record, type: 'controlled', extra: ['revision_id' => $record->revisions()->sole()->id]), ['file' => $this->pdf('synthetic.pdf'), 'reason' => 'Non editable source'])->assertCreated()->json('data.versions.0.id');
        $this->releaseSource($record, $actor);
        $this->postJson('/api/v1/document-templates', $this->scopeData($scope) + $this->data($pdf))->assertNotFound();
        $this->getJson($this->catalogueUrl($scope, '/sources').'&q=synthetic.pdf')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('document_templates', 0);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_retirement_preserves_history_and_publication_and_catalogue_download_are_unavailable(bool $administrator): void
    {
        [$record, $actor] = $this->catalogueFixture($administrator ? 'highly_confidential' : 'normal');
        $scope = $this->scope($record);
        $entry = $this->createEntry($scope);
        $url = $this->catalogueUrl($scope, '/'.$entry['id']);
        $this->postJson($this->catalogueUrl($scope, '/'.$entry['id'].'/publish'))->assertNotFound();
        $this->getJson($this->catalogueUrl($scope, '/'.$entry['id'].'/download'))->assertNotFound();
        $this->postJson($this->catalogueUrl($scope, '/'.$entry['id'].'/retire'), $this->scopeData($scope) + ['reason' => 'Candidate withdrawn'])->assertOk()->assertJsonPath('data.status', 'retired')->assertJsonPath('data.retired_by', $actor->id);
        $this->getJson($url)->assertOk()->assertJsonPath('publication_enabled', false)->assertJsonCount(2, 'history')->assertJsonPath('history.0.snapshot.status', 'inactive')->assertJsonPath('history.1.action', 'retired');
        $this->postJson($this->catalogueUrl($scope, '/'.$entry['id'].'/retire'), $this->scopeData($scope) + ['reason' => 'Again'])->assertUnprocessable();
        $this->patchJson($url, ['attachment_version_id' => null])->assertStatus(405);
        $this->deleteJson($url)->assertStatus(405);
    }

    public function test_guests_readers_and_revoked_memberships_cannot_manage_entries(): void
    {
        $this->getJson('/api/v1/document-templates')->assertUnauthorized();
        [$record, $actor, $role] = $this->catalogueFixture();
        $scope = $this->scope($record);
        $entry = $this->createEntry($scope);
        $role->permissions()->detach(Permission::where('key', 'templates.manage')->value('id'));
        $this->getJson($this->catalogueUrl($scope))->assertOk();
        $this->postJson('/api/v1/document-templates', $this->scopeData($scope) + $this->data())->assertForbidden();
        $this->postJson($this->catalogueUrl($scope, '/'.$entry['id'].'/retire'), $this->scopeData($scope) + ['reason' => 'Unauthorized'])->assertForbidden();
        $actor->memberships()->sole()->update(['active' => false]);
        $this->getJson($this->catalogueUrl($scope))->assertForbidden();
        $this->assertDatabaseCount('document_template_audits', 1);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_company_and_site_boundaries_hide_foreign_sources_entries_and_history(bool $sameCompany): void
    {
        [$record, $actor] = $this->catalogueFixture();
        [$version] = $this->source($record, $actor);
        $entry = $this->createEntry($this->scope($record), $version);
        $site = Site::factory()->create($sameCompany ? ['company_id' => $record->profile->company_id] : []);
        $other = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $this->grant($actor, $other, ['templates.view', 'templates.manage']);
        $this->getJson($this->catalogueUrl($other))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->catalogueUrl($other, '/'.$entry['id']))->assertNotFound()->assertDontSee($version);
        $this->postJson('/api/v1/document-templates', $this->scopeData($other) + $this->data($version))->assertNotFound();
        $this->getJson($this->catalogueUrl($other, '/sources').'&q=synthetic')->assertOk()->assertJsonCount(0, 'data');
        $forged = clone $other;
        $forged->id = $this->scope($record)->id;
        $this->assertSame([], app(TemplateCatalogue::class)->sources($actor, $forged, 'synthetic'));
    }

    public function test_confidential_sources_require_existing_clearance_and_both_document_permissions(): void
    {
        [$record, $actor] = $this->catalogueFixture('highly_confidential');
        [$version] = $this->source($record, $actor);
        $scope = $this->scope($record);
        $entry = $this->createEntry($scope, $version);
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite(Site::findOrFail($scope->site_id))->create();
        $this->grant($reader, $scope, ['templates.view', 'templates.manage', 'documents.metadata.view', 'documents.attachments.download']);
        $this->signIn($reader);
        $this->getJson($this->catalogueUrl($scope))->assertOk()->assertJsonPath('meta.total', 0)->assertDontSee($version);
        $this->getJson($this->catalogueUrl($scope, '/'.$entry['id']))->assertNotFound();
        $this->postJson('/api/v1/document-templates', $this->scopeData($scope) + $this->data($version))->assertNotFound();
        ConfidentialityClearance::factory()->forProfile($record->profile)->create(['user_id' => $reader->id]);
        DocumentAccessGrant::factory()->create(['profile_id' => $record->profile_id, 'user_id' => $reader->id, 'operation' => 'metadata']);
        $this->getJson($this->catalogueUrl($scope))->assertOk()->assertJsonPath('meta.total', 0);
        DocumentAccessGrant::factory()->create(['profile_id' => $record->profile_id, 'user_id' => $reader->id, 'operation' => 'download']);
        $this->getJson($this->catalogueUrl($scope))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->catalogueUrl($scope, '/sources').'&q=synthetic')->assertOk()->assertJsonCount(1, 'data');
    }

    #[TestWith(['create'])]
    #[TestWith(['retire'])]
    public function test_failed_audit_insertion_rolls_back_mutation(string $operation): void
    {
        [$record] = $this->catalogueFixture();
        $scope = $this->scope($record);
        $entry = $operation === 'retire' ? $this->createEntry($scope) : null;
        $event = 'eloquent.creating: '.DocumentTemplateAudit::class;
        Event::listen($event, fn (): bool => false);
        try {
            if ($entry) {
                $this->postJson($this->catalogueUrl($scope, '/'.$entry['id'].'/retire'), $this->scopeData($scope) + ['reason' => 'Rollback'])->assertInternalServerError();
            } else {
                $this->postJson('/api/v1/document-templates', $this->scopeData($scope) + $this->data())->assertInternalServerError();
            }
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('document_templates', $entry ? 1 : 0);
        $this->assertDatabaseCount('document_template_audits', $entry ? 1 : 0);
        if ($entry) {
            $this->assertSame('inactive', DocumentTemplate::findOrFail($entry['id'])->status);
        }
    }

    #[TestWith(['status'])]
    #[TestWith(['reference'])]
    #[TestWith(['delete'])]
    #[TestWith(['audit'])]
    #[TestWith(['replace'])]
    #[TestWith(['retirement_metadata'])]
    #[TestWith(['retirement_timestamp'])]
    public function test_database_preserves_inactive_state_source_binding_metadata_and_audits(string $operation): void
    {
        [$record, $actor] = $this->catalogueFixture();
        $version = $operation === 'reference' ? $this->source($record, $actor)[0] : null;
        $entry = $this->createEntry($this->scope($record), $version);
        $retirement = ['status' => 'retired', 'retired_by' => $entry['created_by'], 'retired_at' => now(), 'retirement_reason' => 'Synthetic retirement'];
        $this->expectException(QueryException::class);
        match ($operation) {
            'status' => DB::table('document_templates')->update(['status' => 'active']),
            'reference' => DB::table('document_templates')->update($retirement + ['attachment_version_id' => null]),
            'retirement_metadata' => DB::table('document_templates')->update($retirement + ['name_en' => 'Rewritten']),
            'retirement_timestamp' => DB::table('document_templates')->update($retirement + ['created_at' => null]),
            'delete' => DB::table('document_templates')->delete(),
            'audit' => DB::table('document_template_audits')->delete(),
            'replace' => DB::statement((DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE').' INTO document_templates SELECT * FROM document_templates'),
        };
    }

    public function test_model_binding_is_immutable_and_catalogue_posts_require_csrf(): void
    {
        [$record] = $this->catalogueFixture();
        $entry = $this->createEntry($this->scope($record));
        $this->app['env'] = 'local';
        $this->postJson('/api/v1/document-templates', $this->scopeData($this->scope($record)) + $this->data())->assertStatus(419);
        $this->expectException(ValidationException::class);
        DocumentTemplate::findOrFail($entry['id'])->update(['name_en' => 'Rewritten']);
    }
}
