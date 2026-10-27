<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => ['nullable', Rule::in(config('smartbiz.currencies'))], 'status' => ['nullable', Rule::in(['completed', 'voided'])],
            'customer_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in(['closed_at', 'amount', 'created_at'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
