<?php

namespace Tests\Feature;

use App\Authorization\AttachmentFiles;
use App\Authorization\DocumentScope;
use App\Authorization\ManageIncomingCorrespondence;
use App\Models\AttachmentVersion;
use App\Models\AuthorizationScope;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\StorageEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class DocumentStorageTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('documents');
        $this->definitions();
        config(['app.debug' => false]);
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $actor = User::factory()->create();
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $role = $this->grant($actor, $scope, ['incoming.drafts.manage', 'documents.metadata.view', 'documents.attachments.manage', 'documents.content.preview', 'documents.attachments.download']);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'incoming']);
        $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
        $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id]);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $record = app(ManageIncomingCorrespondence::class)->save($actor, new DocumentScope($site->company_id, $site->id),
            ['type_id' => $type->id, 'subject' => 'Synthetic storage test', 'sender_organization' => 'Example', 'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26', 'priority' => 'normal', 'channel' => 'email', 'classification' => 'normal']);
        $this->signIn($actor);

        return [$record, $actor, $role];
    }

    private function url($record, string $suffix = 'attachments', string $type = 'incoming', array $extra = []): string
    {
        return '/api/v1/document-storage/'.$type.'/'.$record->id.'/'.$suffix.'?'.http_build_query($extra + ['company_id' => $record->profile->company_id, 'site_id' => $record->profile->site_id]);
    }

    private function pdf(string $name = 'ຄວາມປອດໄພ.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }

    public function test_private_upload_records_hash_metadata_and_no_public_paths(): void
    {
        [$record, $actor] = $this->fixture();
        $response = $this->postJson($this->url($record), ['file' => $this->pdf(), 'reason' => 'Signed original'])->assertCreated();
        $version = AttachmentVersion::sole();
        $response->assertJsonPath('data.versions.0.original_name', 'ຄວາມປອດໄພ.pdf')->assertJsonPath('data.versions.0.uploaded_by', $actor->id)
            ->assertJsonMissingPath('data.versions.0.sha256')->assertDontSee('objects/');
        $this->assertSame(hash('sha256', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"), $version->sha256);
        Storage::disk('documents')->assertExists(app(AttachmentFiles::class)->key($version->id));
        $this->assertDatabaseHas('storage_events', ['action' => 'attachment.uploaded', 'subject_id' => $version->id]);
        $this->getJson($this->url($record))->assertOk()->assertJsonCount(1, 'data');
        $this->get('/storage/objects/'.$version->id)->assertNotFound();
    }

    #[TestWith(['payload.php', '<?php echo 1;'])]
    #[TestWith(['payload.pdf', '<?php echo 1;'])]
    #[TestWith(['payload.svg', '<svg onload="alert(1)"></svg>'])]
    #[TestWith(['payload.php.pdf', '%PDF-1.4'])]
    #[TestWith(['payload.exe', 'MZ'])]
    #[TestWith(['payload.docx', 'not a zip'])]
    public function test_invalid_uploads_leave_no_files_or_attachment_metadata(string $name, string $content): void
    {
        [$record] = $this->fixture();
        $this->postJson($this->url($record), ['file' => UploadedFile::fake()->createWithContent($name, $content), 'reason' => 'Invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_filename_traversal_and_configured_size_limit_are_rejected(): void
    {
        [$record] = $this->fixture();
        $file = $this->pdf('..\\private.pdf');
        $this->postJson($this->url($record), ['file' => $file, 'reason' => 'Invalid'])->assertUnprocessable();
        config(['document_storage.max_upload_kb' => 1]);
        $this->postJson($this->url($record), ['file' => UploadedFile::fake()->createWithContent('large.pdf', '%PDF-1.4'.str_repeat('x', 2000)), 'reason' => 'Too large'])->assertUnprocessable();
        $this->assertDatabaseCount('attachment_versions', 0);
    }

    public function test_metadata_permission_does_not_grant_upload_and_denials_are_durable(): void
    {
        [$record, $actor, $role] = $this->fixture();
        $role->permissions()->detach(Permission::where('key', 'documents.attachments.manage')->value('id'));
        $this->postJson($this->url($record), ['file' => $this->pdf(), 'reason' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseHas('storage_events', ['actor_id' => $actor->id, 'action' => 'request.denied']);
        $this->assertDatabaseCount('attachment_versions', 0);
    }

    public function test_audit_failure_rolls_back_metadata_and_removes_new_blob(): void
    {
        [$record] = $this->fixture();
        $event = 'eloquent.creating: '.StorageEvent::class;
        app('events')->listen($event, fn (StorageEvent $event): ?bool => $event->action === 'attachment.uploaded' ? false : null);
        try {
            $this->postJson($this->url($record), ['file' => $this->pdf(), 'reason' => 'Rollback'])->assertInternalServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertDatabaseCount('attachment_versions', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_replacement_is_append_only_reasoned_and_rejects_stale_version(): void
    {
        [$record] = $this->fixture();
        $attachment = $this->postJson($this->url($record), ['file' => $this->pdf(), 'reason' => 'Original'])->assertCreated()->json('data');
        $this->postJson($this->url($record), ['file' => $this->pdf('corrected.pdf'), 'reason' => 'Correction', 'attachment_id' => $attachment['id'], 'expected_version' => 1])->assertOk()->assertJsonPath('data.versions.1.version', 2);
        $this->postJson($this->url($record), ['file' => $this->pdf(), 'reason' => 'Stale', 'attachment_id' => $attachment['id'], 'expected_version' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('attachment_versions', 2);
        $this->assertDatabaseHas('storage_events', ['action' => 'attachment.replaced', 'reason' => 'Correction']);
        try {
            DB::table('attachment_versions')->update(['original_name' => 'forged.pdf']);
            $this->fail('History was mutable.');
        } catch (QueryException) {
        }
    }
}
