<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Models\User;
use App\Notifications\AppointmentReminderNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

class AppointmentReminderTest extends CrmTestCase
{
    public function test_scheduler_queues_only_upcoming_active_bookings(): void
    {
        $this->signIn();
        $this->travelTo('2026-10-26 10:00:00');
        Queue::fake([SendAppointmentReminder::class]);
        $a = Appointment::factory()->create(['start_at' => now()->addMinutes(20), 'end_at' => now()->addHour()]);
        Appointment::factory()->create(['start_at' => now()->addHours(2), 'end_at' => now()->addHours(3)]);
        Appointment::factory()->create(['status' => 'cancelled', 'start_at' => now()->addMinutes(10)]);
        $this->artisan('smartbiz:appointment-reminders')->assertSuccessful();
        $this->assertDatabaseHas('appointment_reminders', ['appointment_id' => $a->id, 'appointment_version' => 1]);
        Queue::assertPushed(SendAppointmentReminder::class, 1);
    }

    public function test_reminder_is_sent_once_and_persisted(): void
    {
        $this->signIn();
        $this->travelTo('2026-10-26 10:00:00');
        $owner = User::factory()->create();
        $owner->assignRole('Staff');
        $a = Appointment::factory()->for($owner, 'assignee')->create(['start_at' => now()->addMinutes(20)]);
        $r = AppointmentReminder::factory()->for($a)->create();
        Notification::fake();
        $job = new SendAppointmentReminder($r->id);
        $job->handle();
        $job->handle();
        Notification::assertSentToTimes($owner, AppointmentReminderNotification::class, 1);
        $this->assertSame('sent', $r->fresh()->status);
        $this->assertNotNull($a->fresh()->reminder_sent_at);
    }

    public function test_stale_cancelled_archived_and_inactive_assignments_are_skipped(): void
    {
        $this->signIn();
        $this->travelTo('2026-10-26 10:00:00');
        Notification::fake();
        foreach (['cancelled', 'stale', 'archived', 'inactive', 'revoked'] as $case) {
            $owner = User::factory()->create(['status' => $case === 'inactive' ? 'inactive' : 'active']);
            $owner->assignRole('Staff');
            if ($case === 'revoked') {
                $owner->removeRole('Staff');
            }$a = Appointment::factory()->for($owner, 'assignee')->create(['start_at' => now()->addMinutes(20), 'status' => $case === 'cancelled' ? 'cancelled' : 'scheduled', 'version' => $case === 'stale' ? 2 : 1]);
            if ($case === 'archived') {
                $a->delete();
            }$r = AppointmentReminder::factory()->for($a)->create(['appointment_version' => 1]);
            (new SendAppointmentReminder($r->id))->handle();
            $this->assertSame('skipped', $r->fresh()->status);
        }Notification::assertNothingSent();
    }

    public function test_mail_failure_retains_retryable_state_without_secret_error_content(): void
    {
        $owner = $this->signIn();
        $this->travelTo('2026-10-26 10:00:00');
        $a = Appointment::factory()->for($owner, 'assignee')->create(['start_at' => now()->addMinutes(20)]);
        $r = AppointmentReminder::factory()->for($a)->create();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('private-provider-secret'));
        try {
            (new SendAppointmentReminder($r->id))->handle();
            $this->fail('Expected sanitized retry exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('Appointment reminder mail delivery failed.', $e->getMessage());
        }$this->assertSame('pending', $r->fresh()->status);
        $this->assertSame('mail_delivery_failed', $r->fresh()->error_code);
        $this->assertSame(1, $r->fresh()->attempts);
        $this->assertNull($a->fresh()->reminder_sent_at);
    }

    public function test_operator_retry_can_deliver_a_previously_failed_reminder(): void
    {
        $owner = $this->signIn();
        $this->travelTo('2026-10-26 10:00:00');
        $appointment = Appointment::factory()->for($owner, 'assignee')->create(['start_at' => now()->addMinutes(20)]);
        $reminder = AppointmentReminder::factory()->for($appointment)->create(['status' => 'failed', 'attempts' => 3]);
        Notification::fake();

        (new SendAppointmentReminder($reminder->id))->handle();

        Notification::assertSentToTimes($owner, AppointmentReminderNotification::class, 1);
        $this->assertSame('sent', $reminder->fresh()->status);
        $this->assertSame(4, $reminder->fresh()->attempts);
    }

    public function test_scheduler_does_not_redispatch_in_progress_retries_or_terminal_failures(): void
    {
        $this->signIn();
        $this->travelTo('2026-10-26 10:00:00');
        Queue::fake([SendAppointmentReminder::class]);
        foreach (['pending', 'failed'] as $status) {
            $appointment = Appointment::factory()->create(['start_at' => now()->addMinutes(20)]);
            AppointmentReminder::factory()->for($appointment)->create(['status' => $status, 'attempts' => 1]);
        }

        $this->artisan('smartbiz:appointment-reminders')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_reminder_mail_escapes_hostile_titles(): void
    {
        $notification = new AppointmentReminderNotification('<script>alert(1)</script>', '2026-10-26T10:00:00Z', '2026-10-26T11:00:00Z');
        $html = $notification->toMail(User::factory()->make())->render();
        $this->assertStringNotContainsString('<script>', $html);
    }
}
