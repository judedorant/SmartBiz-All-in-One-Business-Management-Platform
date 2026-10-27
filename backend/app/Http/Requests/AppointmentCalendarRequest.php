<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AppointmentCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['start_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'], 'end_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'after:start_at'], 'assigned_to' => ['nullable', 'integer', 'min:1']];
    }

    public function after(): array
    {
        return [function (Validator $v): void {
            if ($v->errors()->isEmpty() && CarbonImmutable::parse($this->start_at)->diffInHours(CarbonImmutable::parse($this->end_at)) > 1490) {
                $v->errors()->add('end_at', 'Select a calendar range of at most 62 days.');
            }
        }];
    }
}
