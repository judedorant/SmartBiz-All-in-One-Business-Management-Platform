<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('leads.manage') || (! $this->isMethod('POST') && $this->user()->can('leads.update_assigned'));
    }

    public function rules(): array
    {
        $manager = $this->user()->can('leads.manage');

        return [
            'title' => ['required', 'string', 'max:200'], 'source' => ['required', 'string', 'max:100'],
            'estimated_value' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'expected_close_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:10000'],
            'assigned_to' => $manager ? ['required', 'integer', Rule::exists('users', 'id')->where('status', 'active')] : ['prohibited'],
            'customer_id' => $manager ? ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')] : ['prohibited'],
            'pipeline_stage_id' => ['prohibited'], 'status' => ['prohibited'], 'created_by' => ['prohibited'], 'converted_at' => ['prohibited'],
            'version' => $this->isMethod('POST') ? ['prohibited'] : ['required', 'integer', 'min:1'],
        ];
    }
}
