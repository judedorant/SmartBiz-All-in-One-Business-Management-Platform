<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('appointments.manage');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:10000'],
            'customer_id' => ['bail', 'required', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! Customer::visibleTo($this->user())->whereKey($value)->exists()) {
                    $fail('Choose a customer you have permission to view.');
                }
            }], 'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')->where('status', 'active')],
            'start_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'], 'end_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'after:start_at'],
            'status' => ['required', Rule::in($this->isMethod('POST') ? ['scheduled', 'confirmed'] : ['scheduled', 'confirmed', 'completed', 'cancelled'])],
            'version' => $this->isMethod('POST') ? ['prohibited'] : ['required', 'integer', 'min:1'], 'created_by' => ['prohibited'], 'reminder_sent_at' => ['prohibited'],
        ];
    }
}
