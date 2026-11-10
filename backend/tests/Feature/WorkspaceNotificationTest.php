<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskChangedNotification;
use App\Services\TaskService;
use Illuminate\Support\Facades\DB;

class WorkspaceNotificationTest extends CrmTestCase
{
    private function staff(): User
    {
        $staff = User::factory()->create();
        $staff->assignRole('Staff');

        return $staff;
    }

    public function test_assignment_persists_minimal_payload_and_resolves_authorized_context(): void
    {
        $manager = $this->signIn();
        $staff = $this->staff();
        $task = app(TaskService::class)->store($manager, ['title' => 'Private customer follow-up', 'assigned_to' => $staff->id, 'priority' => 'urgent', 'status' => 'pending']);
        $notice = $staff->notifications()->firstOrFail();
        $this->assertSame(['task_id' => $task->id, 'kind' => 'task_assigned'], $notice->data);
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.task_title', $task->title)->assertJsonPath('data.0.action_url', '/tasks/'.$task->id)->assertJsonMissingPath('data.0.data')->assertJsonMissingPath('data.0.notifiable_id');
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 1);
    }

    public function test_reassignment_redacts_old_alerts_and_revokes_old_owner_actions(): void
    {
        $manager = $this->signIn();
        $old = $this->staff();
        $new = $this->staff();
        $service = app(TaskService::class);
        $task = $service->store($manager, ['title' => 'Confidential title', 'assigned_to' => $old->id, 'priority' => 'medium', 'status' => 'pending']);
        $service->update($manager, $task->id, ['assigned_to' => $new->id, 'version' => 1]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($old, 'web');
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.task_title', null)->assertJsonPath('data.0.action_url', null)->assertJsonPath('data.1.task_title', null);
        $this->getJson('/api/v1/tasks/'.$task->id)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($new, 'web');
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.task_title', $task->title);
        $service->archive($manager, $task->id, 2);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.task_title', null)->assertJsonPath('data.1.action_url', null);
    }

    public function test_status_and_comments_notify_authorized_creator_once_and_omit_actor(): void
    {
        $this->travelTo('2026-10-27 12:00:00');
        $manager = $this->signIn();
        $staff = $this->staff();
        $service = app(TaskService::class);
        $task = $service->store($manager, ['title' => 'Task', 'assigned_to' => $staff->id, 'priority' => 'medium', 'status' => 'pending']);
        $this->assertSame(0, $manager->notifications()->count());
        $this->assertSame(1, $staff->notifications()->count());
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        $this->patchJson('/api/v1/tasks/'.$task->id.'/status', ['version' => 1, 'status' => 'completed'])->assertOk();
        $this->postJson('/api/v1/tasks/'.$task->id.'/comments', ['comment' => 'Complete'])->assertCreated();
        $this->assertSame(2, $manager->notifications()->count());
        $this->assertSame(1, $staff->notifications()->count());
        $this->assertEqualsCanonicalizing(['task_status_changed', 'task_comment'], $manager->notifications()->get()->map(fn ($n) => $n->data['kind'])->all());
    }

    public function test_owned_reads_are_idempotent_and_read_all_is_isolated(): void
    {
        $staff = $this->signIn('Staff');
        $other = $this->staff();
        $task = Task::factory()->for($staff, 'assignee')->create();
        $staff->notify(new TaskChangedNotification($task->id, 'task_assigned'));
        $staff->notify(new TaskChangedNotification($task->id, 'task_updated'));
        $other->notify(new TaskChangedNotification($task->id, 'task_assigned'));
        $id = $staff->notifications()->firstOrFail()->id;
        $foreign = $other->notifications()->firstOrFail()->id;
        $this->patchJson('/api/v1/notifications/'.$foreign.'/read')->assertNotFound();
        $this->patchJson('/api/v1/notifications/'.$id.'/read')->assertOk();
        $this->patchJson('/api/v1/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/api/v1/notifications?unread=1&per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_unknown_notification_payloads_never_become_user_controlled_links_or_messages(): void
    {
        $staff = $this->signIn('Staff');
        $task = Task::factory()->for($staff, 'assignee')->create();
        $staff->notify(new TaskChangedNotification($task->id, 'task_assigned'));
        $notice = $staff->notifications()->firstOrFail();
        $notice->update(['type' => 'UnknownNotification', 'data' => ['task_id' => $task->id, 'kind' => 'task_assigned', 'message' => 'Secret', 'action_url' => 'https://evil.example']]);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.message', 'Workspace notification.')->assertJsonPath('data.0.action_url', null)->assertJsonPath('data.0.task_title', null)->assertJsonMissingPath('data.0.data');
    }

    public function test_notifications_are_part_of_same_database_transaction_as_task_writes(): void
    {
        $manager = $this->signIn();
        $staff = $this->staff();
        $service = app(TaskService::class);
        try {
            DB::transaction(function () use ($service, $manager, $staff): void {
                $service->store($manager, ['title' => 'Rolled back', 'assigned_to' => $staff->id, 'priority' => 'medium', 'status' => 'pending']);
                $this->assertDatabaseCount('notifications', 1);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notification_endpoints_reject_guests_and_invalid_filters(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
        $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
        $this->signIn('Staff');
        $this->getJson('/api/v1/notifications?per_page=1000')->assertUnprocessable();
        $this->getJson('/api/v1/notifications?unread=maybe')->assertUnprocessable();
    }
}
