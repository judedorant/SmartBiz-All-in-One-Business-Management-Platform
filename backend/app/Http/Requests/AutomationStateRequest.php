<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AutomationStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('automations.manage');
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']];
    }
}
