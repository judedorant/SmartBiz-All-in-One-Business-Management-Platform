<?php

namespace App\Notifications;

use App\Services\PasswordSecurity;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChanged extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->mailer(PasswordSecurity::mailer())->subject('Your SmartBiz password was changed')
            ->line('Your SmartBiz password was changed. All existing sessions have been signed out.')
            ->line('If you did not make this change, contact your workspace administrator immediately.')
            ->salutation('The SmartBiz team');
    }
}
