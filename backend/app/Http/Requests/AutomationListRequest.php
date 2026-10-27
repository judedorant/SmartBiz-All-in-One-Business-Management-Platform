<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutomationListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('automations.manage');
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'is_active' => ['nullable', 'boolean'], 'execution_status' => ['nullable', Rule::in(['pending', 'running', 'succeeded', 'failed', 'skipped', 'uncertain'])], 'sort' => ['nullable', Rule::in(['created_at', 'name'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']];
    }
}
