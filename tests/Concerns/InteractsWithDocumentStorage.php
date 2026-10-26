<?php

namespace Tests\Concerns;

use App\Authorization\DocumentScope;
use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Models\AuthorizationScope;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait InteractsWithDocumentStorage
{
    private function fixture(string $resource = 'incoming', string $classification = 'normal'): array
    {
        Storage::fake('documents');
        $this->definitions();
        config(['app.debug' => false]);
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $actor = User::factory()->create();
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $role = $this->grant($actor, $scope, ['incoming.drafts.manage', 'outgoing.drafts.manage', 'controlled.drafts.manage', 'controlled.register', 'controlled.release', 'controlled.status.manage', 'numbering.issue', 'numbering.manage', 'documents.metadata.view', 'documents.attachments.manage', 'documents.custody.manage', 'storage-locations.manage', 'documents.content.preview', 'documents.attachments.download']);
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
}
