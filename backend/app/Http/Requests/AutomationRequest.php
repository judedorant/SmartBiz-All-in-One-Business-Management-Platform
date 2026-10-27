<?php

namespace App\Http\Requests;

use App\Models\IntegrationAccount;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\AutomationCatalog;
use App\Services\TemplateRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('automations.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'], 'trigger_type' => ['required', Rule::in(AutomationCatalog::TRIGGERS)], 'is_active' => ['required', 'boolean'], 'version' => $this->isMethod('POST') ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'configuration' => ['required', 'array:actions,lead_status'], 'configuration.lead_status' => ['nullable', Rule::in(['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'])],
            'configuration.actions' => ['required', 'array', 'list', 'min:1', 'max:8'], 'configuration.actions.*' => ['required', 'array'], 'configuration.actions.*.type' => ['required', Rule::in(AutomationCatalog::ACTIONS)], 'created_by' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }$templates = app(TemplateRenderer::class);
            if ($this->input('configuration.lead_status') && $this->input('trigger_type') !== 'lead_status_changed') {
                $validator->errors()->add('configuration.lead_status', 'A stage condition requires the lead status trigger.');
            }
            foreach ($this->input('configuration.actions', []) as $index => $action) {
                $type = $action['type'];
                $allowed = match ($type) {
                    'create_task' => ['type', 'title', 'assigned_to', 'priority', 'due_in_hours'],'send_notification' => ['type', 'user_id'],'send_email' => ['type', 'recipient', 'to', 'subject', 'body', 'transport', 'integration_account_id'],'google_sheets_row' => ['type', 'integration_account_id', 'spreadsheet_id', 'range'],'webhook' => ['type', 'webhook_endpoint_id'],'google_calendar_event' => ['type', 'integration_account_id', 'calendar_id'],default => ['type']
                };
                $errors = [];
                if (array_diff(array_keys($action), $allowed)) {
                    $errors[] = 'Unsupported action settings.';
                }
                foreach (['title' => 200, 'subject' => 200, 'body' => 10000] as $key => $max) {
                    if (($type === 'create_task' && $key === 'title') || ($type === 'send_email' && in_array($key, ['subject', 'body'], true))) {
                        if (! is_string($action[$key] ?? null) || trim($action[$key]) === '' || mb_strlen($action[$key]) > $max || ! $templates->valid($action[$key])) {
                            $errors[] = 'Enter valid '.$key.' text using only supported placeholders.';
                        }
                    }
                }
                if ($type === 'create_task' && (! in_array($action['priority'] ?? null, ['low', 'medium', 'high', 'urgent'], true) || ! is_int($action['due_in_hours'] ?? null) || $action['due_in_hours'] < 0 || $action['due_in_hours'] > 8760)) {
                    $errors[] = 'Choose a valid priority and due delay (0–8760 hours).';
                }
                foreach (['assigned_to', 'user_id'] as $key) {
                    if (isset($action[$key]) && (! is_int($action[$key]) || ! User::whereKey($action[$key])->where('status', 'active')->exists())) {
                        $errors[] = 'Choose an active user.';
                    }
                }
                if ($type === 'send_email') {
                    if (! in_array($action['recipient'] ?? null, ['customer', 'assignee', 'fixed'], true) || ! in_array($action['transport'] ?? 'mail', ['mail', 'gmail'], true)) {
                        $errors[] = 'Choose a supported recipient and mail transport.';
                    }
                    if (($action['recipient'] ?? null) === 'fixed' && (! is_string($action['to'] ?? null) || ! filter_var($action['to'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $action['to']))) {
                        $errors[] = 'Enter a valid fixed recipient email.';
                    }
                }
                $provider = match ($type) {
                    'google_sheets_row' => 'google_sheets','google_calendar_event' => 'google_calendar','send_email' => ($action['transport'] ?? 'mail') === 'gmail' ? 'google_gmail' : null,default => null
                };
                if ($provider && (! $this->user()->can('integrations.manage') || ! is_int($action['integration_account_id'] ?? null) || ! IntegrationAccount::whereKey($action['integration_account_id'])->where('user_id', $this->user()->id)->where('provider', $provider)->where('status', 'connected')->exists())) {
                    $errors[] = 'Choose your connected account for this Google service.';
                }
                if ($type === 'google_sheets_row' && (! is_string($action['spreadsheet_id'] ?? null) || ! preg_match('/^[a-zA-Z0-9_-]{10,200}$/D', $action['spreadsheet_id']) || ! is_string($action['range'] ?? null) || ! preg_match('/^[a-zA-Z0-9 _!\x27:$.-]{1,150}$/D', $action['range']))) {
                    $errors[] = 'Enter a valid spreadsheet ID and range.';
                }
                if ($type === 'google_calendar_event' && (! in_array($this->input('trigger_type'), ['appointment_created', 'appointment_updated', 'appointment_approaching'], true) || ! is_string($action['calendar_id'] ?? null) || strlen($action['calendar_id']) > 200 || trim($action['calendar_id']) === '')) {
                    $errors[] = 'Calendar actions require an appointment trigger and calendar ID.';
                }
                if ($type === 'webhook' && (! is_int($action['webhook_endpoint_id'] ?? null) || ! WebhookEndpoint::whereKey($action['webhook_endpoint_id'])->where('direction', 'outbound')->where('is_active', true)->exists())) {
                    $errors[] = 'Choose an active outbound webhook.';
                }
                foreach ($errors as $error) {
                    $validator->errors()->add('configuration.actions.'.$index,$error);
                }
            }
        }];
    }
}
