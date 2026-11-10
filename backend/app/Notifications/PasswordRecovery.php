<?php

namespace App\Notifications;

use App\Services\PasswordSecurity;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordRecovery extends Notification
{
    public function __construct(#[\SensitiveParameter] private string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()], '', '&', PHP_QUERY_RFC3986);

        return (new MailMessage)->mailer(PasswordSecurity::mailer())->subject('Reset your SmartBiz password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a request to reset your SmartBiz account password.')
            ->action('Reset SmartBiz password', $url)
            ->line('This single-use link expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If you did not request this, no action is needed. Your password has not changed.')
            ->salutation('The SmartBiz team');
    }
}
