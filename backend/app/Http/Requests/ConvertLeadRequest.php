<?php

namespace App\Http\Requests;

class ConvertLeadRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('leads.manage');
    }

    public function rules(): array
    {
        return [...parent::rules(), 'version' => ['required', 'integer', 'min:1']];
    }
}
