<?php

namespace App\Console\Commands;

use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use Illuminate\Console\Command;

class DispatchAppointmentReminders extends Command
{
    protected $signature = 'smartbiz:appointment-reminders';

    protected $description = 'Queue reminders for appointments starting within 30 minutes';

    public function handle(): int
    {
        $queued = 0;
        Appointment::whereIn('status', ['scheduled', 'confirmed'])->whereNull('reminder_sent_at')->where('start_at', '>', now())->where('start_at', '<=', now()->addMinutes(30))->chunkById(100, function ($appointments) use (&$queued): void {
            foreach ($appointments as $appointment) {
                $reminder = AppointmentReminder::firstOrCreate(['appointment_id' => $appointment->id, 'appointment_version' => $appointment->version]);
                if ($reminder->status === 'pending' && $reminder->attempts === 0) {
                    SendAppointmentReminder::dispatch($reminder->id)->afterCommit();
                    $queued++;
                }
            }
        });
        $this->info('Eligible reminder jobs processed: '.$queued);

        return self::SUCCESS;
    }
}
