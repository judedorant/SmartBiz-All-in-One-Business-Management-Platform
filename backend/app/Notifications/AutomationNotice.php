<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class AutomationNotice extends Notification
{
    public function __construct(public int $logId) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['automation_log_id' => $this->logId];
    }
}
