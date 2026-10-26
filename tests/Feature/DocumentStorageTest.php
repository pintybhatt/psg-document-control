<?php

namespace Tests\Feature;

use App\Authorization\AttachmentFiles;
use App\Authorization\DocumentScope;
use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Models\AttachmentVersion;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\RegisterConfiguration;
use App\Models\Role;
use App\Models\Site;
use App\Models\StorageEvent;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class DocumentStorageTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private function fixture(string $resource = 'incoming', string $classification = 'normal'): array
    {
        Storage::fake('documents');
        $this->definitions();
        config(['app.debug' => false]);
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $actor = User::factory()->create();
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $role = $this->grant($actor, $scope, ['incoming.drafts.manage', 'outgoing.drafts.manage', 'controlled.drafts.manage', 'controlled.register', 'controlled.release', 'controlled.status.manage', 'numbering.issue', 'numbering.manage', 'documents.metadata.view', 'documents.attachments.manage', 'documents.content.preview', 'documents.attachments.download']);
        if ($classification !== 'normal') {
            UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => Role::where('code', 'SECURITY_ADMINISTRATOR')->value('id'), 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        }
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => $resource]);
        $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
        $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id]);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $unit = new DocumentScope($site->company_id, $site->id);
        $manager = app(match ($resource) {
            'controlled' => ManageControlledDocuments::class, 'outgoing' => ManageOutgoingCorrespondence::class, default => ManageIncomingCorrespondence::class
        });
        $body = ['type_id' => $type->id, 'priority' => 'normal', 'classification' => $classification];
        $body += match ($resource) {
            'controlled' => ['document_code' => 'TEST/SOP/001', 'title_en' => 'Test procedure', 'owner_id' => $actor->id, 'custodian_id' => $actor->id, 'issue_date' => '2026-10-25', 'effective_date' => '2026-10-26', 'revision_reason' => 'Initial', 'change_description' => 'Initial'],
            'outgoing' => ['subject' => 'Test outgoing', 'document_date' => '2026-10-26', 'signatory_name' => 'Example', 'signatory_title' => 'Officer', 'recipients' => [['kind' => 'primary', 'organization' => 'Example']]],
            default => ['subject' => 'Synthetic storage test', 'sender_organization' => 'Example', 'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26', 'channel' => 'email'],
        };
        if ($resource === 'controlled') {
            unset($body['priority']);
        }
        $record = $manager->save($actor, $unit, $body);
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

    public function test_preview_and_download_require_separate_content_permissions_and_are_audited(): void
    {
        [$record, $actor, $role] = $this->fixture();
        $this->postJson($this->url($record), ['file' => $this->pdf(), 'reason' => 'Original'])->assertCreated();
        $version = AttachmentVersion::sole();
        $preview = $this->url($record, 'versions/'.$version->id.'/preview');
        $download = $this->url($record, 'versions/'.$version->id.'/download');
        $this->getJson($preview)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson($download)->assertOk()->assertDownload('document.pdf')->assertHeader('Content-Disposition', HeaderUtils::makeDisposition('attachment', 'ຄວາມປອດໄພ.pdf', 'document.pdf'));
        $role->permissions()->detach(Permission::where('key', 'documents.attachments.download')->value('id'));
        $this->getJson($download)->assertNotFound();
        $this->getJson($preview)->assertOk();
        $role->permissions()->detach(Permission::where('key', 'documents.content.preview')->value('id'));
        $this->getJson($preview)->assertNotFound();
        $this->getJson($this->url($record))->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('storage_events', ['action' => 'attachment.preview', 'subject_id' => $version->id]);
        $this->assertDatabaseHas('storage_events', ['action' => 'attachment.download', 'subject_id' => $version->id]);
        $this->assertDatabaseHas('document_security_audits', ['event' => 'access.denied', 'operation' => 'download']);
    }

    public function test_missing_and_corrupt_files_fail_closed_and_backup_restores_disposable_files(): void
    {
        [$record] = $this->fixture();
        $this->postJson($this->url($record), ['file' => $this->pdf(), 'reason' => 'Original'])->assertCreated();
        $version = AttachmentVersion::sole();
        $key = app(AttachmentFiles::class)->key($version->id);
        $archive = sys_get_temp_dir().'/dcms-backup-'.Str::uuid().'.zip';
        try {
            $this->artisan('dcms:files-backup', ['destination' => $archive])->assertSuccessful();
            $this->artisan('dcms:files-verify')->assertSuccessful();
            Storage::disk('documents')->delete($key);
            $this->getJson($this->url($record, 'versions/'.$version->id.'/download'))->assertStatus(409)->assertJsonPath('message', 'storageFileUnavailable');
            $this->artisan('dcms:files-verify')->assertFailed();
            $this->artisan('dcms:files-restore', ['archive' => $archive])->assertSuccessful();
            Storage::disk('documents')->put($key, 'corrupt');
            $this->getJson($this->url($record, 'versions/'.$version->id.'/preview'))->assertStatus(409);
            $this->artisan('dcms:files-restore', ['archive' => $archive])->assertSuccessful();
            $this->artisan('dcms:files-verify')->assertSuccessful();
            $this->assertDatabaseCount('attachment_versions', 1);
        } finally {
            @unlink($archive);
        }
    }

    public function test_foreign_parent_and_foreign_site_cannot_discover_or_download_file_versions(): void
    {
        [$first] = $this->fixture();
        $this->postJson($this->url($first), ['file' => $this->pdf(), 'reason' => 'Original'])->assertCreated();
        $version = AttachmentVersion::sole();
        [$second] = $this->fixture();
        $this->getJson($this->url($second, 'versions/'.$version->id.'/download'))->assertNotFound();
        $this->getJson($this->url($first, extra: ['company_id' => $second->profile->company_id, 'site_id' => $second->profile->site_id]))->assertNotFound();
        $this->assertDatabaseHas('storage_events', ['action' => 'request.denied']);
    }

    public function test_controlled_release_freezes_file_associations_and_corrections_require_new_revision(): void
    {
        $this->travelTo('2026-10-26 10:00:00');
        [$record, $actor] = $this->fixture('controlled');
        $revision = $record->revisions()->sole();
        $extra = ['revision_id' => $revision->id];
        $attachment = $this->postJson($this->url($record, type: 'controlled', extra: $extra), ['file' => $this->pdf(), 'reason' => 'Initial'])->assertCreated()->json('data');
        $scope = $record->profile->documentScope();
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'TEST/CD', 'padding' => 6, 'active' => true]);
        $manager = app(ManageControlledDocuments::class);
        $record = $manager->register($actor, $scope, (string) $record->id, ['lock_version' => 1]);
        $record = $manager->release($actor, $scope, (string) $record->id, ['lock_version' => 2, 'revision_id' => $revision->id, 'reason' => 'Authorized release']);
        $this->postJson($this->url($record, type: 'controlled', extra: $extra), ['file' => $this->pdf(), 'reason' => 'Forbidden replacement', 'attachment_id' => $attachment['id'], 'expected_version' => 1])->assertUnprocessable()->assertJsonPath('errors.storage.0', 'storageRevisionImmutable');
        $this->postJson($this->url($record, type: 'controlled', extra: $extra), ['file' => $this->pdf(), 'reason' => 'Forbidden addition'])->assertUnprocessable();
        $record = $manager->revise($actor, $scope, (string) $record->id, ['lock_version' => 3, 'revision_reason' => 'File correction', 'change_description' => 'Corrected scan']);
        $next = $record->revisions()->whereNull('released_at')->sole();
        $this->postJson($this->url($record, type: 'controlled', extra: ['revision_id' => $next->id]), ['file' => $this->pdf('corrected.pdf'), 'reason' => 'Corrected scan'])->assertCreated();
        $this->getJson($this->url($record, type: 'controlled', extra: $extra))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('writable', false);
        $this->assertDatabaseCount('attachment_versions', 2);
    }

    public function test_drawing_formats_are_download_only_and_outgoing_register_is_supported(): void
    {
        [$record] = $this->fixture('outgoing');
        $this->postJson($this->url($record, type: 'outgoing'), ['file' => UploadedFile::fake()->createWithContent('drawing.dxf', "0\nSECTION\n2\nHEADER\n0\nENDSEC\n0\nEOF"), 'reason' => 'Drawing'])->assertCreated()->assertJsonPath('data.versions.0.previewable', false);
        $version = AttachmentVersion::sole();
        $this->getJson($this->url($record, 'versions/'.$version->id.'/preview', 'outgoing'))->assertStatus(415);
        $this->getJson($this->url($record, 'versions/'.$version->id.'/download', 'outgoing'))->assertOk();
    }

    public function test_highly_confidential_files_need_clearance_and_operation_specific_grants(): void
    {
        [$record] = $this->fixture(classification: 'highly_confidential');
        $this->postJson($this->url($record), ['file' => $this->pdf('secret.pdf'), 'reason' => 'Secret'])->assertCreated();
        $version = AttachmentVersion::sole();
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite($record->profile->documentScope()->authorizationScope()->site)->create();
        $this->grant($reader, $record->profile->documentScope()->authorizationScope(), ['documents.metadata.view', 'documents.content.preview', 'documents.attachments.download']);
        $this->signIn($reader);
        $this->getJson($this->url($record))->assertNotFound()->assertDontSee('secret.pdf');
        $this->getJson($this->url($record, 'versions/'.$version->id.'/download'))->assertNotFound();
        ConfidentialityClearance::factory()->forProfile($record->profile)->create(['user_id' => $reader->id]);
        DocumentAccessGrant::factory()->create(['profile_id' => $record->profile_id, 'user_id' => $reader->id, 'operation' => 'metadata']);
        $this->getJson($this->url($record))->assertOk();
        $this->getJson($this->url($record, 'versions/'.$version->id.'/preview'))->assertNotFound();
        DocumentAccessGrant::factory()->create(['profile_id' => $record->profile_id, 'user_id' => $reader->id, 'operation' => 'preview']);
        $this->getJson($this->url($record, 'versions/'.$version->id.'/preview'))->assertOk();
        $this->getJson($this->url($record, 'versions/'.$version->id.'/download'))->assertNotFound();
        $this->assertDatabaseHas('document_security_audits', ['reason' => 'system_administrator']);
    }

    public function test_failed_private_file_write_rolls_back_database_state(): void
    {
        [$record] = $this->fixture();
        Storage::disk('documents')->put('unchanged', 'Existing file');
        Storage::partialMock()->shouldReceive('disk')->with('documents')->andReturn(new class
        {
            public function put(...$arguments): bool
            {
                throw new \RuntimeException('Disk full');
            }

            public function delete(...$arguments): bool
            {
                return true;
            }
        });
        $this->postJson($this->url($record), ['file' => $this->pdf(), 'reason' => 'Storage failure'])->assertInternalServerError();
        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertDatabaseCount('attachment_versions', 0);
    }
}
