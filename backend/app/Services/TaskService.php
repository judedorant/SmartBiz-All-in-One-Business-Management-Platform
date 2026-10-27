<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(private TaskNotificationService $notifications) {}

    public function store(User $actor, array $data): Task
    {
        return $this->save($actor, null, $data);
    }

    public function update(User $actor, int $id, array $data): Task
    {
        return $this->save($actor, $id, $data);
    }

    private function save(User $actor, ?int $id, array $data): Task
    {
        return DB::transaction(function () use ($actor, $id, $data): Task {
            $task = $id ? Task::visibleTo($actor)->lockForUpdate()->findOrFail($id) : new Task;
            Gate::forUser($actor)->authorize($id ? 'update' : 'create', $id ? $task : Task::class);
            if ($id) {
                $this->version($task, (int) $data['version']);
            }
            if (! $actor->can('tasks.assign') && array_intersect(['assigned_to', 'customer_id', 'lead_id'], array_keys($data))) {
                abort(403);
            }
            $oldOwner = $id ? $task->assigned_to : null;
            $oldStatus = $id ? $task->status : null;
            $task->fill($data);
            if (! $id || $task->isDirty('assigned_to')) {
                $owner = User::whereKey($task->assigned_to)->where('status', 'active')->lockForUpdate()->first();
                if (! $owner || (! $owner->can('tasks.assign') && ! $owner->can('tasks.manage_assigned'))) {
                    throw ValidationException::withMessages(['assigned_to' => ['Choose an active staff member with task access.']]);
                }
            }
            if (! $id || $task->isDirty(['customer_id', 'lead_id'])) {
                if ($task->lead_id) {
                    $lead = Lead::visibleTo($actor)->lockForUpdate()->findOrFail($task->lead_id);
                    if ($task->customer_id && $task->customer_id !== $lead->customer_id) {
                        throw ValidationException::withMessages(['lead_id' => ['Choose a lead linked to this customer, or clear the customer link.']]);
                    }
                    if (! $task->customer_id) {
                        $task->customer_id = $lead->customer_id;
                    }
                }
                if ($task->customer_id) {
                    Customer::visibleTo($actor)->lockForUpdate()->findOrFail($task->customer_id);
                }
            }
            if ($id && ! $task->isDirty()) {
                return $task;
            }
            if (! $id) {
                $task->created_by = $actor->id;
                $task->version = 1;
            } else {
                $task->version++;
            }
            if ($oldStatus !== $task->status) {
                $task->completed_at = $task->status === 'completed' ? now() : null;
            }
            $task->save();
            $this->audit($actor, $id ? 'update' : 'create', $task->id);
            $this->notifications->changed($task, $actor, $oldOwner, $oldStatus, ! $id);

            return $task;
        }, 5);
    }

    public function archive(User $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version): void {
            $task = Task::visibleTo($actor)->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('delete', $task);
            $this->version($task, $version);
            $task->version++;
            $task->save();
            $task->delete();
            $this->audit($actor, 'archive', $id);
            $this->notifications->changed($task, $actor, $task->assigned_to, $task->status, false, true);
        }, 5);
    }

    public function comment(User $actor, int $id, array $data, ?int $commentId = null): TaskComment
    {
        return DB::transaction(function () use ($actor, $id, $data, $commentId): TaskComment {
            $task = Task::visibleTo($actor)->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('update', $task);
            $comment = $commentId ? $task->comments()->lockForUpdate()->findOrFail($commentId) : $task->comments()->make();
            $comment->setRelation('task', $task);
            if ($commentId) {
                Gate::forUser($actor)->authorize('update', $comment);
                abort_if($comment->version !== (int) $data['version'], 409, 'This comment changed. Reload before saving.');
                $comment->version++;
            } else {
                $comment->user_id = $actor->id;
            }
            $comment->comment = $data['comment'];
            $comment->save();
            $this->audit($actor, $commentId ? 'comment_update' : 'comment_create', $id);
            $this->notifications->commented($task, $actor);

            return $comment;
        }, 5);
    }

    public function deleteComment(User $actor, int $id, int $commentId, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $commentId, $version): void {
            $task = Task::visibleTo($actor)->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('update', $task);
            $comment = $task->comments()->lockForUpdate()->findOrFail($commentId);
            $comment->setRelation('task', $task);
            Gate::forUser($actor)->authorize('delete', $comment);
            abort_if($comment->version !== $version, 409, 'This comment changed. Reload before deleting.');
            $comment->delete();
            $this->audit($actor, 'comment_delete', $id);
        }, 5);
    }

    private function version(Task $task, int $version): void
    {
        abort_if($task->version !== $version, 409, 'This task changed. Reload it before retrying.');
    }

    private function audit(User $actor, string $action, int $id): void
    {
        DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => 'tasks.'.$action.':'.$id, 'created_at' => now()]);
    }
}
