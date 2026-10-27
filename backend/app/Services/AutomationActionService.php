<?php

namespace App\Services;

use App\Mail\AutomationMail;
use App\Models\Appointment;
use App\Models\AutomationEvent;
use App\Models\AutomationStep;
use App\Models\Customer;
use App\Models\IntegrationAccount;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\AutomationNotice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;

class AutomationActionService
{
    public function __construct(private TemplateRenderer $templates, private GoogleIntegrationService $google, private WebhookService $webhooks, private TaskService $tasks) {}

    public function local(string $type): bool
    {
        return in_array($type, ['create_task', 'send_notification'], true);
    }

    public function execute(AutomationStep $step, AutomationEvent $event, User $actor, ?Model $source, array $context): void
    {
        $config = $step->configuration;
        if ($step->action_type === 'create_task') {
            $owner = $config['assigned_to'] ?? $source?->assigned_to ?? $actor->id;
            $title = $this->templates->render($config['title'], $context);
            if (mb_strlen($title) > 200) {
                throw new ActionFailure('rendered_task_title_too_long');
            }
            $createdTask = $this->tasks->store($actor, ['title' => $title, 'description' => null, 'assigned_to' => $owner, 'customer_id' => $source instanceof Customer ? $source->id : $source?->customer_id, 'lead_id' => $source instanceof Lead ? $source->id : null, 'status' => 'pending', 'priority' => $config['priority'], 'due_date' => now()->addHours($config['due_in_hours'])->format('Y-m-d\TH:i:s\Z')]);

            $createdTask->automation_log_id = $step->log_id;
            $createdTask->save();

            return;
        }
        if ($step->action_type === 'send_notification') {
            $recipient = User::where('status', 'active')->find($config['user_id'] ?? $source?->assigned_to ?? $actor->id);
            if (! $recipient) {
                throw new ActionFailure('notification_recipient_unavailable');
            }$recipient->notify(new AutomationNotice($step->log_id));

            return;
        }
        if ($step->action_type === 'webhook') {
            $this->webhooks->send($step, $context);

            return;
        }
        $account = null;
        if (isset($config['integration_account_id'])) {
            $account = IntegrationAccount::where('user_id', $actor->id)->where('status', 'connected')->find($config['integration_account_id']);
            if (! $actor->can('integrations.manage') || ! $account) {
                throw new ActionFailure('integration_unavailable');
            }
        }
        if ($step->action_type === 'send_email') {
            $address = match ($config['recipient']) {
                'fixed' => $config['to'] ?? null,'assignee' => User::whereKey($source?->assigned_to)->where('status', 'active')->value('email'),'customer' => $source instanceof Customer ? $source->email : $source?->customer?->email,default => null
            };
            if (! is_string($address) || ! filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $address)) {
                throw new ActionFailure('email_recipient_unavailable');
            }
            $subject = str_replace(["\r", "\n"], ' ', $this->templates->render($config['subject'], $context));
            $body = $this->templates->render($config['body'], $context);
            if (($config['transport'] ?? 'mail') === 'gmail') {
                $this->google->gmail($account, $address, $subject, $body);
            } else {
                try {
                    Mail::to($address)->send(new AutomationMail($subject, $body));
                } catch (\Throwable) {
                    throw new ActionFailure('mail_outcome_unknown', false, true);
                }
            }

            return;
        }
        if ($step->action_type === 'google_sheets_row') {
            $this->google->sheets($account, $config['spreadsheet_id'], $config['range'], [$event->event_key, $context['trigger'], $event->subject_type, (string) $context['id'], $context['title'], $context['status'], $event->occurred_at->toISOString()]);

            return;
        }
        if ($step->action_type === 'google_calendar_event') {
            if (! $source instanceof Appointment) {
                throw new ActionFailure('calendar_requires_appointment');
            }
            $this->google->calendar($account, $config['calendar_id'], $source);

            return;
        }
        throw new ActionFailure('unsupported_action');
    }
}
