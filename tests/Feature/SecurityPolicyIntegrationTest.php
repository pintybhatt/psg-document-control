<?php

namespace Tests\Feature;

use App\Authorization\Confidentiality;
use App\Authorization\DocumentAccess;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\ManageDocumentSecurity;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentSecurityProfile;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class SecurityPolicyIntegrationTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{User, DocumentSecurityProfile, DocumentSecurityDescriptor} */
    private function resource(): array
    {
        $this->definitions();
        $site = Site::factory()->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->for($user)->atSite($site)->create();
        $this->grant($user, AuthorizationScope::context('global'), ['documents.metadata.view']);
        $profile = DocumentSecurityProfile::factory()->atSite($site)->create();

        return [$user, $profile, DocumentSecurityDescriptor::fromProfile($profile)];
    }

    public function test_reused_descriptor_observes_authoritative_resource_ownership_changes(): void
    {
        [$reader, $profile, $descriptor] = $this->resource();
        $service = app(DocumentAccess::class);
        $this->assertTrue($service->allows($reader, $descriptor, 'metadata'));
        $foreign = Site::factory()->create();

        DB::table('document_security_profiles')->where('id', $profile->id)->update(['company_id' => $foreign->company_id, 'site_id' => $foreign->id]);

        $this->assertFalse($service->allows($reader, $descriptor, 'metadata'));
    }

    #[TestWith(['confidential'])]
    #[TestWith(['highly_confidential'])]
    public function test_reused_descriptor_observes_authoritative_classification_changes(string $classification): void
    {
        [$reader, $profile, $descriptor] = $this->resource();
        $service = app(DocumentAccess::class);
        $this->assertTrue($service->allows($reader, $descriptor, 'metadata'));

        DB::table('document_security_profiles')->where('id', $profile->id)->update(['classification' => $classification, 'restricted' => $classification === 'highly_confidential']);

        $this->assertFalse($service->allows($reader, $descriptor, 'metadata'));
    }

    #[TestWith(['clearance'])]
    #[TestWith(['allowlist'])]
    public function test_security_management_ignores_forged_loaded_grant_scope(string $kind): void
    {
        [$actor, $local] = $this->resource();
        $this->grant($actor, AuthorizationScope::context('global'), ['document-access.manage', 'clearances.confidential.manage']);
        $foreign = DocumentSecurityProfile::factory()->classified(Confidentiality::Confidential)->create(['restricted' => true]);
        if ($kind === 'clearance') {
            $grant = ConfidentialityClearance::factory()->forProfile($foreign)->inactive()->create();
            $grant->company_id = $local->company_id;
            $grant->site_id = $local->site_id;
        } else {
            $grant = DocumentAccessGrant::factory()->create(['profile_id' => $foreign->id, 'active' => false]);
            $grant->setRelation('profile', $foreign);
            $foreign->company_id = $local->company_id;
            $foreign->site_id = $local->site_id;
        }

        try {
            app(ManageDocumentSecurity::class)->updateGrant($actor, $grant, true);
            $this->fail('A forged scope was trusted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse($grant->refresh()->active);
        $this->assertDatabaseCount('document_security_audits', 0);
    }

    public function test_revocation_after_the_clearance_read_denies_the_next_evaluation_but_does_not_cancel_the_inflight_decision(): void
    {
        [$reader, $profile] = $this->resource();
        DB::table('document_security_profiles')->where('id', $profile->id)->update(['classification' => 'confidential']);
        $profile->refresh();
        $clearance = ConfidentialityClearance::factory()->for($reader)->forProfile($profile)->create();
        $descriptor = DocumentSecurityDescriptor::fromProfile($profile);
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        $connection->setEventDispatcher($dispatcher);
        $armed = true;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $clearance): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'confidentiality_clearances')) {
                $armed = false;
                DB::table('confidentiality_clearances')->where('id', $clearance->id)->update(['active' => false, 'revoked_at' => now(), 'revoked_by' => $clearance->created_by]);
            }
        });

        try {
            $inflight = app(DocumentAccess::class)->allows($reader, $descriptor, 'metadata');
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $this->assertFalse($armed);
        $this->assertTrue($inflight);
        $this->assertFalse(app(DocumentAccess::class)->allows($reader, $descriptor, 'metadata'));
    }
}
