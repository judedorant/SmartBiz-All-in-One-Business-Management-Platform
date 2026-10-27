<?php

namespace App\Http\Requests;

use App\Services\SafeWebhookUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('integrations.manage');
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'direction' => ['required', Rule::in(['inbound', 'outbound'])], 'is_active' => ['required', 'boolean'], 'endpoint_url' => ['nullable', 'string', 'max:2048'], 'secret' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'min:32', 'max:256'], 'version' => $this->isMethod('POST') ? ['prohibited'] : ['required', 'integer', 'min:1']];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }if ($this->input('direction') === 'outbound') {
                try {
                    app(SafeWebhookUrl::class)->options($this->input('endpoint_url') ?? '');
                } catch (\Throwable) {
                    $validator->errors()->add('endpoint_url', 'Use an approved public HTTPS host configured by the operator.');
                }
            } elseif ($this->filled('endpoint_url')) {
                $validator->errors()->add('endpoint_url', 'Inbound endpoints use the generated receive URL.');
            }
        }];
    }
}
