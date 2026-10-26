<?php

namespace Tests\Feature;

use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\PhysicalCopy;
use App\Models\StorageEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class PhysicalCustodyTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    private function location($record, string $room = 'Archive'): int
    {
        return $this->postJson($this->url($record, 'locations'), ['room' => $room, 'cabinet' => 'A', 'shelf' => '2', 'box' => '001'])->assertCreated()->json('data.id');
    }

    private function copy($record, int $location): array
    {
        return $this->postJson($this->url($record, 'copies'), ['identifier' => 'ORIGINAL-001', 'kind' => 'original', 'location_id' => $location, 'reason' => 'Archive original'])->assertCreated()->json('data');
    }

    public function test_physical_only_document_has_hierarchical_location_and_no_required_attachment(): void
    {
        [$record] = $this->fixture();
        $location = $this->location($record);
        $copy = $this->copy($record, $location);
        $this->assertSame('stored', $copy['status']);
        $this->assertSame('Archive → A → 2 → 001', $copy['location']);
        $this->getJson($this->url($record, 'copies'))->assertOk()->assertJsonPath('history.0.action', 'copy.created')->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('attachment_versions', 0);
        $this->assertDatabaseCount('physical_copies', 1);
    }

    public function test_checkout_transfer_overdue_and_return_keep_single_current_custodian_and_history(): void
    {
        $this->travelTo('2026-10-26 10:00:00');
        [$record, $actor] = $this->fixture();
        $location = $this->location($record);
        $copy = $this->copy($record, $location);
        $url = $this->url($record, 'copies/'.$copy['id'].'/events');
        $checkout = ['action' => 'checkout', 'lock_version' => 1, 'custodian_id' => $actor->id, 'checkout_date' => '2026-10-25', 'expected_return_date' => '2026-10-25', 'reason' => 'Review'];
        $this->postJson($url, $checkout)->assertOk()->assertJsonPath('data.status', 'checked_out')->assertJsonPath('data.overdue', true);
        $this->postJson($url, $checkout)->assertUnprocessable()->assertJsonPath('errors.storage.0', 'storageStale');
        $this->postJson($url, array_replace($checkout, ['lock_version' => 2]))->assertUnprocessable()->assertJsonPath('errors.storage.0', 'storageCustodyConflict');
        $other = User::factory()->create();
        OrganizationMembership::factory()->for($other)->atSite($record->profile->documentScope()->authorizationScope()->site)->create();
        $this->postJson($url, ['action' => 'transfer', 'lock_version' => 2, 'custodian_id' => $other->id, 'expected_return_date' => '2026-10-28', 'reason' => 'Transfer for review'])->assertOk()->assertJsonPath('data.custodian_id', $other->id)->assertJsonPath('data.overdue', false);
        $newLocation = $this->location($record, 'New archive');
        $this->postJson($url, ['action' => 'return', 'lock_version' => 3, 'location_id' => $newLocation, 'reason' => 'Returned'])->assertOk()->assertJsonPath('data.status', 'stored')->assertJsonPath('data.custodian_id', null)->assertJsonPath('data.checkout_date', null)->assertJsonPath('data.location_id', $newLocation);
        $this->getJson($this->url($record, 'copies'))->assertOk()->assertJsonCount(4, 'history');
        $this->assertDatabaseCount('physical_copies', 1);
    }

    public function test_inventory_discrepancy_and_reasoned_correction_preserve_original_history(): void
    {
        [$record] = $this->fixture();
        $copy = $this->copy($record, $this->location($record));
        $url = $this->url($record, 'copies/'.$copy['id'].'/events');
        $this->postJson($url, ['action' => 'inventory', 'lock_version' => 1, 'observation' => 'discrepancy', 'reason' => 'Not found at shelf'])->assertOk()->assertJsonPath('data.status', 'stored');
        $event = StorageEvent::where('action', 'custody.inventory')->sole();
        $this->assertSame('discrepancy', $event->after['observation']);
        $this->postJson($url, ['action' => 'correct', 'lock_version' => 1, 'status' => 'missing', 'correction_event_id' => $event->id, 'reason' => 'Confirmed missing'])->assertOk()->assertJsonPath('data.status', 'missing');
        $this->assertDatabaseHas('storage_events', ['action' => 'custody.correct', 'reason' => 'Confirmed missing']);
        $this->assertSame('stored', $event->refresh()->after['status']);
        $this->postJson($url, ['action' => 'checkout', 'lock_version' => 2, 'reason' => 'Cannot checkout missing copy'])->assertUnprocessable();
        try {
            DB::table('storage_events')->where('id', $event->id)->delete();
            $this->fail('Custody history was deleted.');
        } catch (QueryException) {
        }
    }

    public function test_cross_site_locations_and_custodians_are_rejected_by_application_and_database(): void
    {
        [$first] = $this->fixture();
        $location = $this->location($first);
        [$second, $actor] = $this->fixture();
        $this->postJson($this->url($second, 'copies'), ['identifier' => 'FOREIGN', 'kind' => 'original', 'location_id' => $location, 'reason' => 'Invalid'])->assertUnprocessable();
        $copy = $this->copy($second, $this->location($second));
        $foreign = User::factory()->create();
        $this->postJson($this->url($second, 'copies/'.$copy['id'].'/events'), ['action' => 'checkout', 'lock_version' => 1, 'custodian_id' => $foreign->id, 'checkout_date' => '2026-10-25', 'expected_return_date' => '2026-10-28', 'reason' => 'Invalid'])->assertUnprocessable()->assertJsonValidationErrors('custodian_id');
        try {
            DB::table('physical_copies')->where('id', $copy['id'])->update(['location_id' => $location, 'lock_version' => 2]);
            $this->fail('Cross-site location was accepted.');
        } catch (QueryException) {
        }
        $this->assertSame(1, PhysicalCopy::findOrFail($copy['id'])->lock_version);
    }

    public function test_invalid_location_hierarchy_duplicate_copy_and_forged_custody_states_are_rejected(): void
    {
        [$record] = $this->fixture();
        $this->postJson($this->url($record, 'locations'), ['room' => 'Archive', 'box' => 'Missing parents'])->assertUnprocessable();
        $location = $this->location($record);
        $copy = $this->copy($record, $location);
        $this->postJson($this->url($record, 'copies'), ['identifier' => 'ORIGINAL-001', 'kind' => 'copy', 'location_id' => $location, 'reason' => 'Duplicate'])->assertUnprocessable();
        try {
            DB::table('physical_copies')->where('id', $copy['id'])->update(['status' => 'checked_out', 'lock_version' => 2]);
            $this->fail('Contradictory custody state was accepted.');
        } catch (QueryException) {
        }
        $this->assertDatabaseCount('physical_copies', 1);
    }

    public function test_missing_permission_and_audit_failure_do_not_change_custody(): void
    {
        [$record, $actor, $role] = $this->fixture();
        $copy = $this->copy($record, $this->location($record));
        $url = $this->url($record, 'copies/'.$copy['id'].'/events');
        $event = 'eloquent.creating: '.StorageEvent::class;
        app('events')->listen($event, fn (StorageEvent $event): ?bool => $event->action === 'custody.checkout' ? false : null);
        try {
            $this->postJson($url, ['action' => 'checkout', 'lock_version' => 1, 'custodian_id' => $actor->id, 'checkout_date' => '2026-10-25', 'expected_return_date' => '2026-10-28', 'reason' => 'Review'])->assertInternalServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseHas('physical_copies', ['id' => $copy['id'], 'status' => 'stored', 'lock_version' => 1]);
        $role->permissions()->detach(Permission::where('key', 'documents.custody.manage')->value('id'));
        $this->postJson($url, ['action' => 'return', 'lock_version' => 1, 'reason' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseHas('storage_events', ['action' => 'request.denied']);
    }
}
