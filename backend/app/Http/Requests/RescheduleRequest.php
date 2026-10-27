<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('appointments.manage');
    }

    public function rules(): array
    {
        return ['start_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'], 'end_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'after:start_at'], 'version' => ['required', 'integer', 'min:1']];
    }
}
