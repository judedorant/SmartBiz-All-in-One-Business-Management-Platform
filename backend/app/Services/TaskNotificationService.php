<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskChangedNotification;

class TaskNotificationService
{
    public function changed(Task $task, User $actor, ?int $oldOwner = null, ?string $oldStatus = null, bool $created = false, bool $archived = false): void
    {
        $recipients = [];
        if ($created) {
            $recipients[$task->assigned_to] = 'task_assigned';
        } else {
            if ($oldOwner !== null && $oldOwner !== $task->assigned_to) {
                $recipients[$task->assigned_to] = 'task_assigned';
                if ($oldOwner !== $actor->id) {
                    $recipients[$oldOwner] = 'task_unassigned';
                }
            }
            foreach (array_unique([$task->assigned_to, $task->created_by]) as $recipient) {
                if ($recipient !== $actor->id && ! isset($recipients[$recipient])) {
                    $recipients[$recipient] = $archived ? 'task_archived' : ($oldStatus !== $task->status ? 'task_status_changed' : 'task_updated');
                }
            }
        }
        $this->deliver($task, $recipients);
    }

    public function commented(Task $task, User $actor): void
    {
        $recipients = [];
        foreach (array_unique([$task->assigned_to, $task->created_by]) as $id) {
            if ($id !== $actor->id) {
                $recipients[$id] = 'task_comment';
            }
        }
        $this->deliver($task, $recipients);
    }

    private function deliver(Task $task, array $recipients): void
    {
        foreach (User::whereIn('id', array_keys($recipients))->where('status', 'active')->get() as $recipient) {
            $kind = $recipients[$recipient->id];
            $allowed = in_array($kind, ['task_unassigned', 'task_archived'], true) ? ($recipient->can('tasks.assign') || $recipient->can('tasks.manage_assigned')) : $recipient->can('view', $task);
            if ($allowed) {
                $recipient->notify(new TaskChangedNotification($task->id, $kind));
            }
        }
    }
}
