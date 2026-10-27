<?php

namespace App\Services;

class AutomationCatalog
{
    public const TRIGGERS = ['customer_created', 'lead_created', 'lead_status_changed', 'appointment_created', 'appointment_updated', 'appointment_approaching', 'task_overdue', 'webhook_received'];

    public const ACTIONS = ['send_email', 'create_task', 'send_notification', 'google_sheets_row', 'webhook', 'google_calendar_event'];

    public const PROVIDERS = [
        'google_gmail' => 'https://www.googleapis.com/auth/gmail.send',
        'google_sheets' => 'https://www.googleapis.com/auth/spreadsheets',
        'google_calendar' => 'https://www.googleapis.com/auth/calendar.events',
    ];
}
