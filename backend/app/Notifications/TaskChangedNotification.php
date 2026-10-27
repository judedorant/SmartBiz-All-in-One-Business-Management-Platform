<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class TaskChangedNotification extends Notification
{
    public const MESSAGES = ['task_assigned' => 'A task was assigned to you.', 'task_unassigned' => 'A task assignment changed.', 'task_updated' => 'A task was updated.', 'task_status_changed' => 'A task status changed.', 'task_comment' => 'A new task comment was added.', 'task_archived' => 'A task was archived.'];

    public function __construct(public int $taskId, public string $kind) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['task_id' => $this->taskId, 'kind' => array_key_exists($this->kind, self::MESSAGES) ? $this->kind : 'task_updated'];
    }
}
