<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\StaffSchedule;
use App\Models\User;

class AvailabilityTest extends CrmTestCase
{
    private function hours(array $changes = []): array
    {
        return array_replace(['timezone' => 'UTC', 'version' => 0, 'windows' => [['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']]], $changes);
    }

    public function test_manager_can_create_read_and_version_weekly_schedule(): void
    {
        $manager = $this->signIn();
        $staff = User::factory()->create();
        $this->getJson('/api/v1/staff/'.$staff->id.'/availability')->assertOk()->assertJsonPath('data.version', 0)->assertJsonCount(0, 'data.windows');
        $this->putJson('/api/v1/staff/'.$staff->id.'/availability', $this->hours())->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.windows.0.start_time', '09:00');
        $this->putJson('/api/v1/staff/'.$staff->id.'/availability', $this->hours())->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $manager->id, 'action' => 'availability.update:'.$staff->id]);
    }

    public function test_staff_can_only_read_own_hours_and_cannot_change_them(): void
    {
        $staff = $this->signIn('Staff');
        $other = User::factory()->create();
        $this->getJson('/api/v1/staff/'.$staff->id.'/availability')->assertOk();
        $this->getJson('/api/v1/staff/'.$other->id.'/availability')->assertNotFound();
        $this->putJson('/api/v1/staff/'.$staff->id.'/availability', $this->hours())->assertForbidden();
    }

    public function test_overlapping_and_inverted_windows_are_rejected_but_adjacent_windows_work(): void
    {
        $manager = $this->signIn();
        $path = '/api/v1/staff/'.$manager->id.'/availability';
        $this->putJson($path, $this->hours(['windows' => [['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00'], ['day_of_week' => 1, 'start_time' => '11:00', 'end_time' => '17:00']]]))->assertUnprocessable();
        $this->putJson($path, $this->hours(['windows' => [['day_of_week' => 1, 'start_time' => '17:00', 'end_time' => '09:00']]]))->assertUnprocessable();
        $this->putJson($path, $this->hours(['windows' => [['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00'], ['day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '17:00']]]))->assertOk();
    }

    public function test_hours_change_cannot_strand_an_existing_upcoming_booking(): void
    {
        $manager = $this->signIn();
        $this->travelTo('2026-10-26 08:00:00');
        $this->putJson('/api/v1/staff/'.$manager->id.'/availability', $this->hours())->assertOk();
        Appointment::factory()->for($manager, 'assignee')->create(['start_at' => '2026-10-26 10:00:00', 'end_at' => '2026-10-26 11:00:00']);
        $this->putJson('/api/v1/staff/'.$manager->id.'/availability', $this->hours(['version' => 1, 'windows' => []]))->assertConflict();
        $this->assertSame(1, StaffSchedule::find($manager->id)->version);
        $this->assertDatabaseCount('staff_availability', 1);
    }

    public function test_schedule_timezone_is_used_for_booking_not_server_timezone(): void
    {
        $manager = $this->signIn();
        $this->travelTo('2026-10-26 08:00:00');
        $customer = Customer::factory()->create();
        $this->putJson('/api/v1/staff/'.$manager->id.'/availability', $this->hours(['timezone' => 'America/New_York']))->assertOk();
        $this->postJson('/api/v1/appointments', ['title' => 'Local hours', 'customer_id' => $customer->id, 'assigned_to' => $manager->id, 'status' => 'scheduled', 'start_at' => '2026-10-26T13:00:00Z', 'end_at' => '2026-10-26T14:00:00Z'])->assertCreated();
        $this->postJson('/api/v1/appointments', ['title' => 'Too early', 'customer_id' => $customer->id, 'assigned_to' => $manager->id, 'status' => 'scheduled', 'start_at' => '2026-10-26T09:00:00Z', 'end_at' => '2026-10-26T10:00:00Z'])->assertUnprocessable();
    }

    public function test_fall_back_clock_transition_cannot_escape_a_narrow_working_window(): void
    {
        $manager = $this->signIn();
        $this->travelTo('2026-10-31 08:00:00');
        $customer = Customer::factory()->create();
        $this->putJson('/api/v1/staff/'.$manager->id.'/availability', $this->hours(['timezone' => 'America/New_York', 'windows' => [['day_of_week' => 0, 'start_time' => '01:20', 'end_time' => '01:50']]]))->assertOk();
        $this->postJson('/api/v1/appointments', ['title' => 'Fall back', 'customer_id' => $customer->id, 'assigned_to' => $manager->id, 'status' => 'scheduled', 'start_at' => '2026-11-01T05:30:00Z', 'end_at' => '2026-11-01T06:45:00Z'])->assertUnprocessable();
    }

    public function test_bad_timezone_and_oversized_schedules_are_rejected(): void
    {
        $manager = $this->signIn();
        $this->putJson('/api/v1/staff/'.$manager->id.'/availability', $this->hours(['timezone' => 'Invalid/Zone']))->assertUnprocessable()->assertJsonValidationErrors('timezone');
    }
}
