<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('customers.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($this->route('customer'))],
            'phone' => ['nullable', 'string', 'max:40'], 'company' => ['nullable', 'string', 'max:200'], 'address' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'assigned_user_ids' => ['present', 'array', 'max:100'], 'assigned_user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('status', 'active')],
            'version' => $this->isMethod('POST') ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'created_by' => ['prohibited'], 'customer_code' => ['prohibited'],
        ];
    }
}
