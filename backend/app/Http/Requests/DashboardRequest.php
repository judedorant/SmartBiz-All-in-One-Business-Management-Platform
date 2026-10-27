<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('dashboard.view');
    }

    public function rules(): array
    {
        return [
            'preset' => ['sometimes', Rule::in(['today', 'last_7_days', 'last_30_days', 'this_month', 'custom'])],
            'timezone' => ['sometimes', 'string', 'timezone:all_with_bc'], 'currency' => ['sometimes', Rule::in(config('smartbiz.currencies'))],
            'start_date' => ['required_if:preset,custom', 'prohibited_unless:preset,custom', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'end_date' => ['required_if:preset,custom', 'prohibited_unless:preset,custom', 'date_format:Y-m-d', 'after_or_equal:start_date', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->input('preset') === 'custom') {
                $start = CarbonImmutable::parse($this->input('start_date'), 'UTC');
                $end = CarbonImmutable::parse($this->input('end_date'), 'UTC');
                if ($start->diffInDays($end) > 365) {
                    $validator->errors()->add('end_date', 'Select at most 366 calendar days.');
                }
            }
        }];
    }
}
