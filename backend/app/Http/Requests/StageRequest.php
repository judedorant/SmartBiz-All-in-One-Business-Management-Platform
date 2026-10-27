<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('leads.manage') || $this->user()->can('leads.update_assigned');
    }

    public function rules(): array
    {
        return ['pipeline_stage_id' => ['required', 'integer', Rule::exists('pipeline_stages', 'id')], 'version' => ['required', 'integer', 'min:1']];
    }
}
