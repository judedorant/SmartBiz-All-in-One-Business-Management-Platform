<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('POST') ? $this->user()->can('create', Task::class) : ($this->user()->can('tasks.assign') || $this->user()->can('tasks.manage_assigned'));
    }

    public function rules(): array
    {
        $manager = $this->user()->can('tasks.assign');

        return ['automation_log_id' => ['prohibited'], 'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:10000'], 'priority' => ['required', Rule::in(Task::PRIORITIES)], 'status' => ['required', Rule::in(Task::STATUSES)], 'due_date' => ['nullable', 'date_format:Y-m-d\TH:i:s\Z'],
            'assigned_to' => $manager ? [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', Rule::exists('users', 'id')->where('status', 'active')] : ['prohibited'],
            'customer_id' => $manager ? ['bail', 'sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! Customer::visibleTo($this->user())->whereKey($value)->exists()) {
                    $fail('Choose a customer you have permission to view.');
                }
            }] : ['prohibited'],
            'lead_id' => $manager ? ['bail', 'sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! Lead::visibleTo($this->user())->whereKey($value)->exists()) {
                    $fail('Choose a lead you have permission to view.');
                }
            }] : ['prohibited'],
            'version' => $this->isMethod('POST') ? ['prohibited'] : ['required', 'integer', 'min:1'], 'created_by' => ['prohibited'], 'completed_at' => ['prohibited'],
        ];
    }
}
