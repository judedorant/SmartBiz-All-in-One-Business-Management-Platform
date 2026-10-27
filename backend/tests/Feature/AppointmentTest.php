<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\StaffAvailability;
use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;

class AppointmentTest extends CrmTestCase
{
    protected function setupBooking(): array
    {
        $manager = $this->signIn();
        $this->travelTo('2026-10-26 08:00:00');
        $staff = User::factory()->create();
        $staff->assignRole('Staff');
        $customer = Customer::factory()->create();
        StaffSchedule::factory()->create(['user_id' => $staff->id]);
        StaffAvailability::factory()->create(['user_id' => $staff->id]);

        return [$manager, $staff, $customer];
    }

    protected function payload(User $staff, Customer $customer, array $changes = []): array
    {
        return array_replace(['title' => 'Discovery meeting', 'description' => 'Discuss project', 'assigned_to' => $staff->id, 'customer_id' => $customer->id, 'start_at' => '2026-10-26T10:00:00Z', 'end_at' => '2026-10-26T11:00:00Z', 'status' => 'scheduled'], $changes);
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/v1/appointments')->assertUnauthorized();
        $this->postJson('/api/v1/appointments', [])->assertUnauthorized();
    }

    public function test_booking_editing_cancelling_and_archiving_are_versioned_and_audited(): void
    {
        [$manager,$staff,$customer] = $this->setupBooking();
        $payload = $this->payload($staff, $customer);
        $created = $this->postJson('/api/v1/appointments', $payload)->assertCreated()->assertJsonPath('data.version', 1)->assertJsonMissingPath('data.assignee.email');
        $id = $created->json('data.id');
        $this->putJson('/api/v1/appointments/'.$id, [...$payload, 'title' => 'Updated meeting', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/appointments/'.$id)->assertOk()->assertJsonPath('data.title', 'Updated meeting');
        $this->patchJson('/api/v1/appointments/'.$id.'/cancel', ['version' => 2])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->deleteJson('/api/v1/appointments/'.$id, ['version' => 3])->assertOk();
        $this->assertSoftDeleted('appointments', ['id' => $id]);
        $this->getJson('/api/v1/appointments/'.$id)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $manager->id, 'action' => 'appointments.create:'.$id]);
    }

    public function test_overlaps_are_rejected_but_adjacent_appointments_are_allowed(): void
    {
        [$manager,$staff,$customer] = $this->setupBooking();
        $this->postJson('/api/v1/appointments', $this->payload($staff, $customer))->assertCreated();
        $this->postJson('/api/v1/appointments', $this->payload($staff, $customer, ['start_at' => '2026-10-26T10:30:00Z', 'end_at' => '2026-10-26T11:30:00Z']))->assertConflict();
        $this->postJson('/api/v1/appointments', $this->payload($staff, $customer, ['start_at' => '2026-10-26T11:00:00Z', 'end_at' => '2026-10-26T12:00:00Z']))->assertCreated();
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_cancelled_slots_can_be_rebooked_and_different_staff_can_overlap(): void
    {
        [$manager,$staff,$customer] = $this->setupBooking();
        $other = User::factory()->create();
        $other->assignRole('Staff');
        StaffSchedule::factory()->create(['user_id' => $other->id]);
        StaffAvailability::factory()->create(['user_id' => $other->id]);
        $a = Appointment::factory()->for($staff, 'assignee')->for($customer)->create(['start_at' => '2026-10-26 10:00:00', 'end_at' => '2026-10-26 11:00:00']);
        $this->postJson('/api/v1/appointments', $this->payload($other, $customer))->assertCreated();
        $this->patchJson('/api/v1/appointments/'.$a->id.'/cancel', ['version' => 1])->assertOk();
        $this->postJson('/api/v1/appointments', $this->payload($staff, $customer))->assertCreated();
    }

    public function test_reschedule_conflicts_and_stale_writes_do_not_change_original_booking(): void
    {
        [$manager,$staff,$customer] = $this->setupBooking();
        $a = Appointment::factory()->for($staff, 'assignee')->for($customer)->create(['start_at' => '2026-10-26 10:00:00', 'end_at' => '2026-10-26 11:00:00']);
        Appointment::factory()->for($staff, 'assignee')->for($customer)->create(['start_at' => '2026-10-26 12:00:00', 'end_at' => '2026-10-26 13:00:00']);
        $this->patchJson('/api/v1/appointments/'.$a->id.'/reschedule', ['start_at' => '2026-10-26T12:00:00Z', 'end_at' => '2026-10-26T13:00:00Z', 'version' => 1])->assertConflict();
        $this->patchJson('/api/v1/appointments/'.$a->id.'/reschedule', ['start_at' => '2026-10-26T14:00:00Z', 'end_at' => '2026-10-26T15:00:00Z', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/appointments/'.$a->id.'/cancel', ['version' => 1])->assertConflict();
        $this->assertSame('14:00', $a->fresh()->start_at->format('H:i'));
    }

    public function test_missing_availability_and_out_of_hours_bookings_return_422(): void
    {
        [$manager,$staff,$customer] = $this->setupBooking();
        $this->postJson('/api/v1/appointments', $this->payload($staff, $customer, ['start_at' => '2026-10-26T18:00:00Z', 'end_at' => '2026-10-26T19:00:00Z']))->assertUnprocessable()->assertJsonValidationErrors('start_at');
        StaffSchedule::whereKey($staff->id)->delete();
        StaffAvailability::where('user_id', $staff->id)->delete();
        $this->postJson('/api/v1/appointments', $this->payload($staff, $customer))->assertUnprocessable();
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_staff_is_read_only_and_cannot_find_other_staff_appointments(): void
    {
        $staff = $this->signIn('Staff');
        $this->travelTo('2026-10-26 08:00:00');
        $customer = Customer::factory()->create();
        $own = Appointment::factory()->for($staff, 'assignee')->for($customer)->create();
        $other = Appointment::factory()->create();
        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/appointments/'.$own->id)->assertOk();
        $this->getJson('/api/v1/appointments/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/customers/'.$customer->id)->assertOk();
        $this->postJson('/api/v1/appointments', [])->assertForbidden();
        $this->putJson('/api/v1/appointments/'.$own->id, [])->assertForbidden();
        $this->patchJson('/api/v1/appointments/'.$own->id.'/reschedule', [])->assertForbidden();
        $this->patchJson('/api/v1/appointments/'.$own->id.'/cancel', ['version' => 1])->assertForbidden();
        $this->deleteJson('/api/v1/appointments/'.$own->id, ['version' => 1])->assertForbidden();
        $this->getJson('/api/v1/appointments/staff')->assertForbidden();
    }

    public function test_calendar_range_is_bounded_overlap_aware_and_exclusive_at_end(): void
    {
        [$manager,$staff,$customer] = $this->setupBooking();
        Appointment::factory()->for($staff, 'assignee')->for($customer)->create(['start_at' => '2026-10-26 10:00:00', 'end_at' => '2026-10-26 11:00:00']);
        Appointment::factory()->for($staff, 'assignee')->for($customer)->create(['start_at' => '2026-10-26 11:00:00', 'end_at' => '2026-10-26 12:00:00']);
        $this->getJson('/api/v1/appointments/calendar?start_at=2026-10-26T10:30:00Z&end_at=2026-10-26T11:00:00Z')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/appointments/calendar?start_at=2026-01-01T00:00:00Z&end_at=2027-01-01T00:00:00Z')->assertUnprocessable();
    }

    public function test_list_search_status_sort_and_pagination_are_scoped(): void
    {
        $staff = $this->signIn('Staff');
        Appointment::factory()->for($staff, 'assignee')->create(['title' => 'Visible', 'status' => 'confirmed']);
        Appointment::factory()->create(['title' => 'Hidden']);
        $this->getJson('/api/v1/appointments?search=Hidden')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/appointments?status=confirmed&per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/appointments?sort=start_at%3BDROP')->assertUnprocessable();
    }

    public function test_past_short_long_and_invalid_owner_bookings_are_rejected(): void
    {
        [$manager,$staff,$customer] = $this->setupBooking();
        foreach ([['2026-10-25T10:00:00Z', '2026-10-25T11:00:00Z'], ['2026-10-26T10:00:00Z', '2026-10-26T10:01:00Z'], ['2026-10-26T08:00:00Z', '2026-10-26T17:00:00Z']] as [$start,$end]) {
            $this->postJson('/api/v1/appointments', $this->payload($staff, $customer, ['start_at' => $start, 'end_at' => $end]))->assertUnprocessable();
        }
        $roleless = User::factory()->create();
        $this->postJson('/api/v1/appointments', $this->payload($roleless, $customer))->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->postJson('/api/v1/appointments', $this->payload($staff, $customer, ['created_by' => 999]))->assertUnprocessable()->assertJsonValidationErrors('created_by');
    }

    public function test_reminder_marker_is_reset_when_booking_is_rescheduled(): void
    {
        [$manager,$staff,$customer] = $this->setupBooking();
        $a = Appointment::factory()->for($staff, 'assignee')->for($customer)->create(['start_at' => '2026-10-26 10:00:00', 'end_at' => '2026-10-26 11:00:00', 'reminder_sent_at' => now()]);
        $this->patchJson('/api/v1/appointments/'.$a->id.'/reschedule', ['start_at' => '2026-10-26T14:00:00Z', 'end_at' => '2026-10-26T15:00:00Z', 'version' => 1])->assertOk()->assertJsonPath('data.reminder_sent_at', null);
    }

    public function test_completion_requires_elapsed_end_time_and_prevents_future_rescheduling(): void
    {
        [$manager,$staff,$customer] = $this->setupBooking();
        $a = Appointment::factory()->for($staff, 'assignee')->for($customer)->create(['start_at' => '2026-10-26 10:00:00', 'end_at' => '2026-10-26 11:00:00']);
        $this->putJson('/api/v1/appointments/'.$a->id, [...$this->payload($staff, $customer), 'version' => 1, 'status' => 'completed'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->travelTo('2026-10-26 12:00:00');
        $this->putJson('/api/v1/appointments/'.$a->id, [...$this->payload($staff, $customer), 'version' => 1, 'status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->patchJson('/api/v1/appointments/'.$a->id.'/reschedule', ['version' => 2, 'start_at' => '2026-10-26T14:00:00Z', 'end_at' => '2026-10-26T15:00:00Z'])->assertConflict();
    }

    #[TestWith(['Admin', true])]
    #[TestWith(['Manager', true])]
    #[TestWith(['Staff', false])]
    public function test_appointment_policy_matrix(string $role, bool $manage): void
    {
        $user = $this->signIn($role);
        $own = Appointment::factory()->for($user, 'assignee')->create();
        $other = Appointment::factory()->create();
        $this->assertTrue(Gate::forUser($user)->allows('view', $own));
        $this->assertSame($manage, Gate::forUser($user)->allows('view', $other));
        $this->assertSame($manage, Gate::forUser($user)->allows('create', Appointment::class));
        $this->assertSame($manage, Gate::forUser($user)->allows('update', $own));
    }
}
