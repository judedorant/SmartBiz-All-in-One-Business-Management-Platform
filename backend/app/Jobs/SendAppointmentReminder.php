<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Models\User;
use App\Notifications\AppointmentReminderNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendAppointmentReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 1800;

    public function __construct(public int $reminderId) {}

    public function uniqueId(): string
    {
        return 'appointment-reminder:'.$this->reminderId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $failed = false;
        DB::transaction(function () use (&$failed): void {
            $preview = AppointmentReminder::find($this->reminderId);
            if (! $preview) {
                return;
            }
            $snapshot = Appointment::withTrashed()->find($preview->appointment_id);
            if (! $snapshot) {
                return;
            }
            $owner = User::whereKey($snapshot->assigned_to)->lockForUpdate()->first();
            $appointment = Appointment::withTrashed()->lockForUpdate()->find($preview->appointment_id);
            $reminder = AppointmentReminder::lockForUpdate()->find($this->reminderId);
            if (! $reminder || ! in_array($reminder->status, ['pending', 'failed'], true)) {
                return;
            }
            if (! $appointment || $appointment->deleted_at || ! $owner || $appointment->assigned_to !== $snapshot->assigned_to || $appointment->version !== $reminder->appointment_version || ! in_array($appointment->status, ['scheduled', 'confirmed'], true) || $appointment->reminder_sent_at || $appointment->start_at->isPast() || $owner->status !== 'active' || (! $owner->can('appointments.view_assigned') && ! $owner->can('appointments.manage'))) {
                $reminder->update(['status' => 'skipped', 'error_code' => 'appointment_no_longer_eligible']);

                return;
            }
            $reminder->status = 'pending';
            $reminder->attempts++;
            try {
                $owner->notify(new AppointmentReminderNotification($appointment->title, $appointment->start_at->toISOString(), $appointment->end_at->toISOString()));
                $reminder->status = 'sent';
                $reminder->sent_at = now();
                $reminder->error_code = null;
                $appointment->reminder_sent_at = now();
                $appointment->save();
            } catch (\Throwable) {
                $reminder->error_code = 'mail_delivery_failed';
                $failed = true;
            }
            $reminder->save();
        });
        if ($failed) {
            throw new \RuntimeException('Appointment reminder mail delivery failed.');
        }
    }

    public function failed(?\Throwable $exception): void
    {
        AppointmentReminder::whereKey($this->reminderId)->where('status', 'pending')->update(['status' => 'failed', 'error_code' => 'mail_delivery_failed']);
    }
}
