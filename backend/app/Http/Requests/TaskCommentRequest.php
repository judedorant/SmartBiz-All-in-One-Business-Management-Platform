<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaskCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('tasks.assign') || $this->user()->can('tasks.manage_assigned');
    }

    public function rules(): array
    {
        return ['comment' => ['required', 'string', 'max:5000'], 'version' => $this->isMethod('POST') ? ['prohibited'] : ['required', 'integer', 'min:1'], 'user_id' => ['prohibited'], 'task_id' => ['prohibited']];
    }
}
