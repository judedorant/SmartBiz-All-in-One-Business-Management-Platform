<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class AutomationMail extends Mailable
{
    public function __construct(public string $mailSubject, public string $mailBody) {}

    public function build(): static
    {
        return $this->subject($this->mailSubject)->text('mail.automation', ['body' => $this->mailBody]);
    }
}
