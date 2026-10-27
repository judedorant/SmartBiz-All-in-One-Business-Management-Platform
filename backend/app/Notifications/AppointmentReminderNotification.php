<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentReminderNotification extends Notification
{
    public function __construct(public string $title, public string $startAt, public string $endAt) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Upcoming SmartBiz appointment')->greeting('Hello '.$notifiable->name)->line('You have an upcoming appointment: '.$this->title)->line('Starts (UTC): '.$this->startAt)->line('Ends (UTC): '.$this->endAt)->line('Sign in to your SmartBiz workspace to review the appointment.');
    }
}
