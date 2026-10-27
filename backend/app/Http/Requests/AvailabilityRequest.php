<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('appointments.manage');
    }

    public function rules(): array
    {
        return ['timezone' => ['required', 'string', 'timezone:all_with_bc'], 'version' => ['required', 'integer', 'min:0'], 'windows' => ['present', 'array', 'max:42'], 'windows.*.day_of_week' => ['required', 'integer', 'between:0,6'], 'windows.*.start_time' => ['required', 'date_format:H:i'], 'windows.*.end_time' => ['required', 'date_format:H:i', 'after:windows.*.start_time']];
    }
}
