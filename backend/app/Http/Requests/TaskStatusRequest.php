<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('tasks.assign') || $this->user()->can('tasks.manage_assigned');
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(Task::STATUSES)], 'version' => ['required', 'integer', 'min:1']];
    }
}
