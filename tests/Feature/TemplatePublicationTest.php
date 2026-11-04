<?php

namespace Tests\Feature;

use App\Authorization\AttachmentFiles;
use App\Authorization\DocumentApprovals;
use App\Authorization\ManageApprovalWorkflows;
use App\Authorization\ManageAttachments;
use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageNumbering;
use App\Authorization\TemplateCatalogue;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\ControlledDocument;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateAudit;
use App\Models\DocumentTemplatePublication;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\StorageEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;
use ZipArchive;

class TemplatePublicationTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{ControlledDocument, User, Role, DocumentTemplate, string} */
    private function candidate(bool $approved = true, string $classification = 'normal'): array
    {
        [$record, $author, $role] = $this->fixture('controlled', $classification);
        $this->freezeTime();
        $scope = $record->profile->documentScope()->authorizationScope();
        $role->permissions()->syncWithoutDetaching(Permission::whereIn('key', ['templates.view', 'templates.manage', 'templates.publish', 'master-data.view', 'master-data.manage', 'roles.view', 'controlled.approvals.record'])->pluck('id'));
        if ($classification !== 'normal') {
            ConfidentialityClearance::factory()->forProfile($record->profile)->create(['user_id' => $author->id]);
            foreach (['metadata', 'download'] as $operation) {
                DocumentAccessGrant::factory()->create(['profile_id' => $record->profile_id, 'user_id' => $author->id, 'operation' => $operation]);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'publication-');
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
        $attachment = app(ManageAttachments::class)->upload($author, $record->profile->documentScope(), 'controlled', (string) $record->id, $revision->id, UploadedFile::fake()->createWithContent('approved-template.docx', $bytes), 'Synthetic source');
        $version = $attachment->versions()->sole();
        $manager = app(ManageControlledDocuments::class);
        if ($approved) {
            $approver = User::factory()->create();
            OrganizationMembership::factory()->for($approver)->atSite(Site::findOrFail($scope->site_id))->create();
            $approverRole = $this->grant($approver, $scope, ['documents.metadata.view', 'controlled.approvals.record']);
            if ($classification !== 'normal') {
                ConfidentialityClearance::factory()->forProfile($record->profile)->create(['user_id' => $approver->id]);
                DocumentAccessGrant::factory()->create(['profile_id' => $record->profile_id, 'user_id' => $approver->id, 'operation' => 'metadata']);
            }
            app(ManageApprovalWorkflows::class)->save($author, $scope, 'controlled', ['enabled' => true, 'sequence' => 'sequential', 'role_ids' => [$approverRole->id], 'expected_version' => 0, 'change_reason' => 'Synthetic workflow']);
            foreach ([$author, $approver] as $index => $actor) {
                $state = app(DocumentApprovals::class)->state($actor, $record->fresh(), $revision->fresh());
                $record = $manager->recordApproval($actor, $record->profile->documentScope(), (string) $record->id, (string) $revision->id, ['decision' => $index === 0 ? 'submitted' : 'approved', 'lock_version' => $state['lock_version'], 'content_hash' => $state['content_hash'], 'expected_event_id' => $state['expected_event_id']]);
            }
        }
        app(ManageNumbering::class)->save($author, $scope, ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'PUBLICATION/TEST', 'padding' => 4, 'active' => true]);
        $record = $manager->register($author, $record->profile->documentScope(), (string) $record->id, ['lock_version' => $record->lock_version]);
        $record = $manager->release($author, $record->profile->documentScope(), (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $revision->id, 'reason' => 'Synthetic authorized release']);
        $entry = app(TemplateCatalogue::class)->create($author, $scope, ['name_en' => 'Synthetic approved template', 'name_lo' => 'ແບບທົດສອບ', 'purpose' => 'Test purpose', 'attachment_version_id' => $version->id]);
        $this->signIn($author);

        return [$record, $author, $role, $entry, $bytes];
    }

    private function catalogueUrl(ControlledDocument $record, DocumentTemplate $entry, string $action = ''): string
    {
        return $this->scopedUrl('document-templates/'.$entry->id.$action, $record->profile->documentScope()->authorizationScope());
    }

    public function test_explicit_publication_pins_approved_source_without_changing_source_and_downloads_with_security_headers_and_history(): void
    {
        [$record, $author, , $entry, $bytes] = $this->candidate();
        $before = $record->getAttributes();
        $association = DB::table('document_attachments')->get()->map(fn (object $row): array => (array) $row)->all();
        $this->getJson($this->catalogueUrl($record, $entry, '/download'))->assertNotFound();

        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Approved distribution'])->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.published_by', $author->id);
        $publication = DocumentTemplatePublication::query()->sole();
        $this->assertSame($entry->attachment_version_id, $publication->attachment_version_id);
        $this->assertNotNull($publication->published_at);
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Duplicate'])->assertUnprocessable();
        $response = $this->get($this->catalogueUrl($record, $entry, '/download'))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertDatabaseHas('storage_events', ['action' => 'attachment.download', 'subject_id' => $entry->attachment_version_id, 'actor_id' => $author->id]);
        $this->getJson($this->catalogueUrl($record, $entry))->assertOk()->assertJsonCount(3, 'history')->assertJsonPath('history.1.action', 'published')->assertJsonPath('history.2.action', 'downloaded');
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame($association, DB::table('document_attachments')->get()->map(fn (object $row): array => (array) $row)->all());
    }

    public function test_legacy_release_without_pinned_approval_is_kept_inactive(): void
    {
        [$record, , , $entry] = $this->candidate(false);
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Legacy release'])->assertUnprocessable()->assertJsonPath('errors.template.0', 'templateApprovalRequired');
        $this->assertDatabaseCount('document_template_publications', 0);
        $this->assertSame('inactive', $entry->fresh()->catalogueStatus());
    }

    public function test_distribution_requires_source_rights_but_does_not_grant_publication_or_management_rights(): void
    {
        [$record, , , $entry, $bytes] = $this->candidate();
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Approved distribution'])->assertOk();
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite(Site::findOrFail($record->profile->site_id))->create();
        $this->grant($reader, $record->profile->documentScope()->authorizationScope(), ['templates.view', 'documents.metadata.view', 'documents.attachments.download']);
        $this->signIn($reader);

        $download = $this->get($this->catalogueUrl($record, $entry, '/download'))->assertOk();
        $this->assertSame($bytes, $download->streamedContent());
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Not authorized'])->assertForbidden();
        $this->postJson($this->catalogueUrl($record, $entry, '/retire'), ['reason' => 'Not authorized'])->assertForbidden();
        $this->getJson($this->catalogueUrl($record, $entry))->assertOk()->assertJsonPath('data.status', 'published');
        auth()->logout();
        $this->getJson($this->catalogueUrl($record, $entry, '/download'))->assertUnauthorized();
    }

    #[TestWith(['publish_permission'])]
    #[TestWith(['membership'])]
    #[TestWith(['download_permission'])]
    public function test_publication_rechecks_current_permissions_and_membership(string $boundary): void
    {
        [$record, $actor, $role, $entry] = $this->candidate();
        if ($boundary === 'membership') {
            $actor->memberships()->sole()->update(['active' => false]);
        } else {
            $role->permissions()->detach(Permission::where('key', $boundary === 'publish_permission' ? 'templates.publish' : 'documents.attachments.download')->value('id'));
        }
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Unauthorized'])->assertStatus($boundary === 'download_permission' ? 404 : 403);
        $this->assertDatabaseCount('document_template_publications', 0);
    }

    #[TestWith(['missing'])]
    #[TestWith(['corrupt'])]
    public function test_publication_and_download_verify_source_integrity(string $failure): void
    {
        [$record, , , $entry] = $this->candidate();
        $key = app(AttachmentFiles::class)->key($entry->attachment_version_id);
        $original = Storage::disk('documents')->get($key);
        if ($failure === 'missing') {
            Storage::disk('documents')->delete($key);
        } else {
            Storage::disk('documents')->put($key, 'tampered');
        }
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Unavailable source'])->assertStatus(409);
        $this->assertDatabaseCount('document_template_publications', 0);
        Storage::disk('documents')->put($key, $original);
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Restored verified source'])->assertOk();
        Storage::disk('documents')->put($key, 'tampered');
        $this->getJson($this->catalogueUrl($record, $entry, '/download'))->assertStatus(409);
        $this->assertDatabaseCount('document_template_publications', 1);
        $this->assertDatabaseHas('storage_events', ['action' => 'attachment.unavailable', 'subject_id' => $entry->attachment_version_id]);
        $this->assertDatabaseMissing('storage_events', ['action' => 'attachment.download', 'subject_id' => $entry->attachment_version_id]);
    }

    public function test_retirement_stops_catalogue_downloads_and_preserves_publication_and_source_references(): void
    {
        [$record, , $role, $entry] = $this->candidate();
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Publish'])->assertOk();
        $snapshot = DocumentTemplatePublication::query()->sole()->getAttributes();
        $role->permissions()->detach(Permission::where('key', 'templates.publish')->value('id'));
        $this->postJson($this->catalogueUrl($record, $entry, '/retire'), ['reason' => 'Not authorized'])->assertForbidden();
        $role->permissions()->attach(Permission::where('key', 'templates.publish')->sole());
        $this->postJson($this->catalogueUrl($record, $entry, '/retire'), ['reason' => 'Withdraw approved template'])->assertOk()->assertJsonPath('data.status', 'retired');
        $this->getJson($this->catalogueUrl($record, $entry, '/download'))->assertNotFound();
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Republish forbidden'])->assertUnprocessable();
        $this->assertSame($snapshot, DocumentTemplatePublication::query()->sole()->getAttributes());
        $this->assertSame($entry->attachment_version_id, $entry->fresh()->attachment_version_id);
        $this->getJson($this->catalogueUrl($record, $entry))->assertOk()->assertJsonPath('history.2.action', 'retired');
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_cross_company_and_site_requests_cannot_publish_or_download_foreign_entries(bool $sameCompany): void
    {
        [$record, $actor, , $entry] = $this->candidate();
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Publish'])->assertOk();
        $site = Site::factory()->create($sameCompany ? ['company_id' => $record->profile->company_id] : []);
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $this->grant($actor, $scope, ['templates.view', 'templates.manage', 'templates.publish', 'documents.metadata.view', 'documents.attachments.download']);
        foreach (['/publish', '/download'] as $action) {
            $url = $this->scopedUrl('document-templates/'.$entry->id.$action, $scope);
            if ($action === '/publish') {
                $this->postJson($url, ['reason' => 'Foreign'])->assertNotFound();
            } else {
                $this->getJson($url)->assertNotFound();
            }
        }
        $this->getJson($this->scopedUrl('document-templates', $scope))->assertOk()->assertJsonPath('meta.total', 0);
    }

    #[TestWith(['clearance'])]
    #[TestWith(['grant'])]
    #[TestWith(['membership'])]
    public function test_system_administrators_do_not_bypass_source_confidentiality_or_scope_membership(string $boundary): void
    {
        [$record, $actor, , $entry] = $this->candidate(true, 'highly_confidential');
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Explicitly cleared'])->assertOk();
        if ($boundary === 'clearance') {
            ConfidentialityClearance::where('user_id', $actor->id)->sole()->update(['active' => false]);
        } elseif ($boundary === 'grant') {
            DocumentAccessGrant::where('user_id', $actor->id)->where('operation', 'download')->sole()->update(['active' => false]);
        } else {
            $actor->memberships()->sole()->update(['active' => false]);
        }
        $this->getJson($this->catalogueUrl($record, $entry, '/download'))->assertStatus($boundary === 'membership' ? 403 : 404);
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'No bypass'])->assertStatus($boundary === 'membership' ? 403 : 404);
        $this->getJson($this->catalogueUrl($record, $entry))->assertStatus($boundary === 'membership' ? 403 : 404);
    }

    #[TestWith(['publication'])]
    #[TestWith(['download_audit'])]
    #[TestWith(['retirement_audit'])]
    public function test_audit_failure_prevents_publication_delivery_or_retirement(string $operation): void
    {
        [$record, , , $entry] = $this->candidate();
        if ($operation !== 'publication') {
            $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Publish'])->assertOk();
        }
        $class = match ($operation) {
            'publication' => DocumentTemplatePublication::class, 'download_audit' => StorageEvent::class, default => DocumentTemplateAudit::class
        };
        $event = 'eloquent.creating: '.$class;
        Event::listen($event, fn (): bool => false);
        try {
            if ($operation === 'download_audit') {
                $this->getJson($this->catalogueUrl($record, $entry, '/download'))->assertInternalServerError();
            } else {
                $this->postJson($this->catalogueUrl($record, $entry, $operation === 'publication' ? '/publish' : '/retire'), ['reason' => 'Rollback'])->assertInternalServerError();
            }
        } finally {
            Event::forget($event);
        }
        $this->assertSame('inactive', $entry->fresh()->status);
        $this->assertDatabaseCount('document_template_publications', $operation === 'publication' ? 0 : 1);
        $this->assertDatabaseMissing('storage_events', ['action' => 'attachment.download', 'subject_id' => $entry->attachment_version_id]);
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    #[TestWith(['replace'])]
    public function test_database_preserves_the_immutable_published_version_and_evidence(string $operation): void
    {
        [$record, , , $entry] = $this->candidate();
        $this->postJson($this->catalogueUrl($record, $entry, '/publish'), ['reason' => 'Publish'])->assertOk();
        $this->expectException(QueryException::class);
        if ($operation === 'update') {
            DB::table('document_template_publications')->update(['reason' => 'Rewritten publication evidence']);
        } elseif ($operation === 'delete') {
            DB::table('document_template_publications')->delete();
        } else {
            DB::statement((DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE').' INTO document_template_publications SELECT * FROM document_template_publications');
        }
    }
}
