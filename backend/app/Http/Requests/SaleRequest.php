<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sales.manage');
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'lead_id' => ['nullable', 'integer', Rule::exists('leads', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'currency' => ['required', Rule::in(config('smartbiz.currencies'))],
            'closed_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'before_or_equal:now'],
            'request_id' => ['required', 'uuid'], 'recorded_by' => ['prohibited'], 'status' => ['prohibited'], 'voided_by' => ['prohibited'],
        ];
    }
}
