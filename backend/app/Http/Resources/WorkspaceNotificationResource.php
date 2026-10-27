<?php

namespace App\Http\Resources;

use App\Notifications\AutomationNotice;
use App\Notifications\TaskChangedNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkspaceNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $kind = $this->type === TaskChangedNotification::class ? ($this->data['kind'] ?? '') : '';
        $message = $this->type === AutomationNotice::class ? 'An automation sent you a notification.' : (TaskChangedNotification::MESSAGES[$kind] ?? 'Workspace notification.');
        $task = $this->resource->getRelation('task_context');

        return ['id' => $this->id, 'kind' => array_key_exists($kind, TaskChangedNotification::MESSAGES) ? $kind : 'workspace', 'message' => $message, 'task_title' => $task?->title, 'action_url' => $task ? '/tasks/'.$task->id : null, 'read_at' => $this->read_at?->toISOString(), 'created_at' => $this->created_at->toISOString()];
    }
}
