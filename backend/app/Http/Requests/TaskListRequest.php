<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(Task::STATUSES)], 'priority' => ['nullable', Rule::in(Task::PRIORITIES)], 'assigned_to' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1'], 'lead_id' => ['nullable', 'integer', 'min:1'], 'overdue' => ['nullable', 'boolean'], 'sort' => ['nullable', Rule::in(['due_date', 'created_at', 'updated_at', 'title', 'priority'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']];
    }
}
