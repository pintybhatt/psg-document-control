<?php

namespace Tests\Feature;

use App\Authorization\AttachmentFiles;
use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Models\AttachmentVersion;
use App\Models\ConfidentialityClearance;
use App\Models\DocumentAccessGrant;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\StorageEvent;
use App\Models\User;
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
    use InteractsWithRbac, LazilyRefreshDatabase, \Tests\Concerns\InteractsWithDocumentStorage;

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
        $this->getJson($this->url($record))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('capabilities.preview', true)->assertJsonPath('capabilities.download', true);
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

    #[TestWith(['incoming'])]
    #[TestWith(['controlled'])]
    public function test_metadata_permission_does_not_grant_upload_and_denials_are_durable(string $resource): void
    {
        [$record, $actor, $role] = $this->fixture($resource);
        $role->permissions()->detach(Permission::where('key', 'documents.attachments.manage')->value('id'));
        $this->postJson($this->url($record, type: $resource, extra: $resource === 'controlled' ? ['revision_id' => $record->revisions()->sole()->id] : []), ['file' => $this->pdf(), 'reason' => 'Unauthorized'])->assertForbidden();
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
        $this->getJson($this->url($record))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('capabilities.preview', false)->assertJsonPath('capabilities.download', false);
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
        $nextAttachment = $this->postJson($this->url($record, type: 'controlled', extra: ['revision_id' => $next->id]), ['file' => $this->pdf('corrected.pdf'), 'reason' => 'Corrected scan'])->assertCreated()->json('data');
        $this->postJson($this->url($record, type: 'controlled', extra: ['revision_id' => $next->id]), ['file' => $this->pdf('r01-replaced.pdf'), 'reason' => 'Replace draft scan', 'attachment_id' => $nextAttachment['id'], 'expected_version' => 1])->assertOk()->assertJsonPath('data.versions.1.original_name', 'r01-replaced.pdf');
        $this->getJson($this->url($record, type: 'controlled', extra: $extra))->assertOk()->assertJsonPath('data.0.versions.0.original_name', 'ຄວາມປອດໄພ.pdf');
        $this->getJson($this->url($record, 'versions/'.$attachment['versions'][0]['id'].'/download', 'controlled', $extra))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson($this->url($record, type: 'controlled', extra: ['revision_id' => $next->id]), ['file' => $this->pdf(), 'reason' => 'Wrong revision attachment', 'attachment_id' => $attachment['id'], 'expected_version' => 1])->assertNotFound();
        $this->getJson($this->url($record, type: 'controlled', extra: $extra))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('writable', false);
        $this->assertDatabaseCount('attachment_versions', 3);
    }

    public function test_controlled_draft_can_be_registered_and_released_without_an_attachment(): void
    {
        $this->travelTo('2026-10-26 10:00:00');
        [$record, $actor] = $this->fixture('controlled');
        $scope = $record->profile->documentScope();
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'TEST/CD', 'padding' => 6, 'active' => true]);
        $parameters = '?'.http_build_query(['company_id' => $scope->companyId, 'site_id' => $scope->siteId]);
        $this->postJson('/api/v1/controlled-documents/'.$record->id.'/register'.$parameters, ['lock_version' => 1])->assertOk();
        $this->postJson('/api/v1/controlled-documents/'.$record->id.'/release'.$parameters, ['lock_version' => 2, 'revision_id' => $record->revisions()->sole()->id, 'reason' => 'Authorized physical-only release'])->assertOk()->assertJsonPath('data.status', 'current');
        $this->assertDatabaseCount('attachment_versions', 0);
        $this->assertDatabaseHas('controlled_document_audits', ['document_id' => $record->id, 'action' => 'released']);
    }

    public function test_drawing_formats_are_download_only_and_outgoing_register_is_supported(): void
    {
        [$record] = $this->fixture('outgoing');
        $this->postJson($this->url($record, type: 'outgoing'), ['file' => UploadedFile::fake()->createWithContent('drawing.dxf', "0\nSECTION\n2\nHEADER\n0\nENDSEC\n0\nEOF"), 'reason' => 'Drawing'])->assertCreated()->assertJsonPath('data.versions.0.previewable', false);
        $version = AttachmentVersion::sole();
        $this->getJson($this->url($record, 'versions/'.$version->id.'/preview', 'outgoing'))->assertStatus(415);
        $this->getJson($this->url($record, 'versions/'.$version->id.'/download', 'outgoing'))->assertOk();
    }

    #[TestWith(['incoming'])]
    #[TestWith(['controlled'])]
    public function test_highly_confidential_files_need_clearance_and_operation_specific_grants(string $resource): void
    {
        [$record] = $this->fixture($resource, classification: 'highly_confidential');
        $url = fn (string $suffix = 'attachments'): string => $this->url($record, $suffix, $resource, $resource === 'controlled' ? ['revision_id' => $record->revisions()->sole()->id] : []);
        $this->postJson($url(), ['file' => $this->pdf('secret.pdf'), 'reason' => 'Secret'])->assertCreated();
        $version = AttachmentVersion::sole();
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite($record->profile->documentScope()->authorizationScope()->site)->create();
        $this->grant($reader, $record->profile->documentScope()->authorizationScope(), ['documents.metadata.view', 'documents.content.preview', 'documents.attachments.download']);
        $this->signIn($reader);
        $this->getJson($url())->assertNotFound()->assertDontSee('secret.pdf');
        $this->getJson($url('versions/'.$version->id.'/download'))->assertNotFound();
        ConfidentialityClearance::factory()->forProfile($record->profile)->create(['user_id' => $reader->id]);
        DocumentAccessGrant::factory()->create(['profile_id' => $record->profile_id, 'user_id' => $reader->id, 'operation' => 'metadata']);
        $this->getJson($url())->assertOk();
        $this->getJson($url('versions/'.$version->id.'/preview'))->assertNotFound();
        DocumentAccessGrant::factory()->create(['profile_id' => $record->profile_id, 'user_id' => $reader->id, 'operation' => 'preview']);
        $this->getJson($url('versions/'.$version->id.'/preview'))->assertOk();
        $this->getJson($url('versions/'.$version->id.'/download'))->assertNotFound();
        $this->assertDatabaseHas('document_security_audits', ['reason' => 'system_administrator']);
    }

    public function test_failed_private_file_write_rolls_back_database_state(): void
    {
        [$record] = $this->fixture();
        config(['app.debug' => true]);
        Storage::disk('documents')->put('unchanged', 'Existing file');
        Storage::partialMock()->shouldReceive('disk')->with('documents')->andReturn(new class
        {
            public function put(mixed ...$arguments): bool
            {
                throw new \RuntimeException('Disk full');
            }

            public function delete(mixed ...$arguments): bool
            {
                return true;
            }
        });
        $this->postJson($this->url($record), ['file' => $this->pdf(), 'reason' => 'Storage failure'])->assertInternalServerError()->assertJsonPath('message', 'rbacRequestFailed')->assertJsonMissingPath('exception')->assertDontSee('Disk full');
        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertDatabaseCount('attachment_versions', 0);
    }

    public function test_guest_denial_is_logged_without_disclosing_document_metadata(): void
    {
        $this->getJson('/api/v1/document-storage/incoming/999/attachments?company_id=1&site_id=1')->assertUnauthorized()->assertDontSee('original_name');
        $this->assertDatabaseHas('storage_events', ['action' => 'request.denied', 'reason' => '401', 'actor_id' => null]);
    }

    public function test_sql_replace_cannot_rewrite_attachment_identities_or_access_history(): void
    {
        [$record] = $this->fixture();
        $this->postJson($this->url($record), ['file' => $this->pdf(), 'reason' => 'Original'])->assertCreated();
        foreach (['document_attachments', 'attachment_versions', 'storage_events'] as $table) {
            $original = (array) DB::table($table)->first();
            try {
                $grammar = DB::connection()->getQueryGrammar();
                $columns = implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), array_keys($original)));
                $placeholders = implode(', ', array_fill(0, count($original), '?'));
                $verb = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
                DB::statement($verb.' INTO '.$grammar->wrapTable($table).' ('.$columns.') VALUES ('.$placeholders.')', array_values($original));
                $this->fail('Stored history was replaced.');
            } catch (QueryException) {
            }
            $this->assertSame($original, (array) DB::table($table)->where('id', $original['id'])->first());
        }
    }

    #[TestWith(['docx', 'word/document.xml'])]
    #[TestWith(['xlsx', 'xl/workbook.xml'])]
    public function test_macro_free_office_packages_are_downloadable_but_macro_payloads_are_rejected(string $extension, string $mainPart): void
    {
        [$record] = $this->fixture();
        $path = tempnam(sys_get_temp_dir(), 'dcms-office-');
        try {
            $archive = new \ZipArchive;
            $this->assertTrue($archive->open($path, \ZipArchive::OVERWRITE) === true);
            $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
            $archive->addFromString($mainPart, '<?xml version="1.0"?><document/>');
            $archive->close();
            $this->postJson($this->url($record), ['file' => UploadedFile::fake()->createWithContent('document.'.$extension, file_get_contents($path)), 'reason' => 'Office attachment'])->assertCreated()->assertJsonPath('data.versions.0.previewable', false);
            $archive->open($path);
            $archive->addFromString('word/vbaProject.bin', 'Prohibited macro');
            $archive->close();
            $this->postJson($this->url($record), ['file' => UploadedFile::fake()->createWithContent('macro.'.$extension, file_get_contents($path)), 'reason' => 'Invalid macro'])->assertUnprocessable();
            $this->assertDatabaseCount('attachment_versions', 1);
        } finally {
            unlink($path);
        }
    }

    public function test_raster_preview_stream_returns_the_verified_image_bytes(): void
    {
        [$record] = $this->fixture();
        $file = UploadedFile::fake()->image('scan.png', 20, 20);
        $original = file_get_contents($file->getRealPath());
        $this->postJson($this->url($record), ['file' => $file, 'reason' => 'Scan'])->assertCreated();
        $version = AttachmentVersion::sole();
        $response = $this->getJson($this->url($record, 'versions/'.$version->id.'/preview'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($original, $response->streamedContent());
    }

    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    public function test_registered_metadata_corrections_leave_existing_attachment_versions_unchanged(string $kind): void
    {
        [$record, $actor, $role] = $this->fixture($kind);
        $scope = $record->profile->documentScope();
        $authorizationScope = $scope->authorizationScope();
        $permissions = Permission::query()->whereIn('key', [$kind.'.register', $kind.'.operations.manage'])->pluck('id');
        $role->permissions()->syncWithoutDetaching($permissions);
        foreach ($kind === 'outgoing' ? ['internal_register', 'official_outgoing'] : ['internal_register'] as $purpose) {
            app(ManageNumbering::class)->save($actor, $authorizationScope, ['register_id' => $record->register_id, 'purpose' => $purpose, 'reset_policy' => 'annual', 'prefix' => strtoupper('FIX/'.$kind.'/'.($purpose === 'official_outgoing' ? 'OFF' : 'INT')), 'padding' => 3, 'active' => true]);
        }
        $attachment = $this->postJson($this->url($record, type: $kind), ['file' => $this->pdf(), 'reason' => 'Original'])->assertCreated()->json('data');
        $this->postJson($this->url($record, type: $kind), ['file' => $this->pdf('corrected.pdf'), 'reason' => 'File correction', 'attachment_id' => $attachment['id'], 'expected_version' => 1])->assertOk();
        $manager = app($kind === 'incoming' ? ManageIncomingCorrespondence::class : ManageOutgoingCorrespondence::class);
        $manager->register($actor, $scope, (string) $record->id);
        $versions = DB::table('attachment_versions')->orderBy('id')->get()->toArray();
        $attachments = DB::table('document_attachments')->get()->toArray();
        $events = DB::table('storage_events')->get()->toArray();
        $this->postJson('/api/v1/'.$kind.'-correspondence/'.$record->id.'/operations?'.http_build_query(['company_id' => $scope->companyId, 'site_id' => $scope->siteId]), ['action' => 'correct_details', 'revision' => 0, 'note' => 'Correct subject transcription only', 'details' => ['subject' => 'Corrected registered subject']])->assertOk();
        $this->assertEquals($versions, DB::table('attachment_versions')->orderBy('id')->get()->toArray());
        $this->assertEquals($attachments, DB::table('document_attachments')->get()->toArray());
        $this->assertEquals($events, DB::table('storage_events')->get()->toArray());
        foreach (AttachmentVersion::all() as $version) {
            Storage::disk('documents')->assertExists(app(AttachmentFiles::class)->key($version->id));
        }
    }
}
