<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskComment;

class TaskCommentTest extends CrmTestCase
{
    public function test_comments_can_be_created_edited_deleted_and_are_versioned_and_audited(): void
    {
        $staff = $this->signIn('Staff');
        $task = Task::factory()->for($staff, 'assignee')->create();
        $path = '/api/v1/tasks/'.$task->id.'/comments';
        $id = $this->postJson($path, ['comment' => 'Initial comment'])->assertCreated()->assertJsonPath('data.user.id', $staff->id)->assertJsonPath('data.version', 1)->assertJsonMissingPath('data.user.email')->json('data.id');
        $this->getJson($path)->assertOk()->assertJsonPath('meta.total', 1);
        $this->putJson($path.'/'.$id, ['comment' => 'Revised', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($path.'/'.$id, ['comment' => 'Stale', 'version' => 1])->assertConflict();
        $this->deleteJson($path.'/'.$id, ['version' => 1])->assertConflict();
        $this->deleteJson($path.'/'.$id, ['version' => 2])->assertOk();
        $this->assertDatabaseCount('task_comments', 0);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $staff->id, 'action' => 'tasks.comment_create:'.$task->id]);
    }

    public function test_staff_cannot_edit_other_authors_comments_but_manager_can_moderate(): void
    {
        $staff = $this->signIn('Staff');
        $task = Task::factory()->for($staff, 'assignee')->create();
        $comment = TaskComment::factory()->for($task)->create();
        $path = '/api/v1/tasks/'.$task->id.'/comments/'.$comment->id;
        $this->putJson($path, ['comment' => 'No', 'version' => 1])->assertForbidden();
        $this->deleteJson($path, ['version' => 1])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->signIn('Manager');
        $this->putJson($path, ['comment' => 'Moderated', 'version' => 1])->assertOk();
        $this->deleteJson($path, ['version' => 2])->assertOk();
    }

    public function test_parent_scope_nesting_archive_and_reassignment_are_enforced(): void
    {
        $staff = $this->signIn('Staff');
        $task = Task::factory()->for($staff, 'assignee')->create();
        $other = Task::factory()->create();
        $foreign = TaskComment::factory()->for($other)->create();
        $path = '/api/v1/tasks/'.$task->id.'/comments';
        $this->putJson($path.'/'.$foreign->id, ['comment' => 'No', 'version' => 1])->assertNotFound();
        $this->getJson('/api/v1/tasks/'.$other->id.'/comments')->assertNotFound();
        $this->postJson('/api/v1/tasks/'.$other->id.'/comments', ['comment' => 'No'])->assertNotFound();
        $own = TaskComment::factory()->for($task)->for($staff, 'user')->create();
        $task->update(['assigned_to' => $other->assigned_to]);
        $this->putJson($path.'/'.$own->id, ['comment' => 'No', 'version' => 1])->assertNotFound();
        $task->delete();
        $this->getJson($path)->assertNotFound();
    }

    public function test_comment_validation_prevents_spoofed_authors_and_empty_or_oversized_comments(): void
    {
        $staff = $this->signIn('Staff');
        $task = Task::factory()->for($staff, 'assignee')->create();
        $path = '/api/v1/tasks/'.$task->id.'/comments';
        foreach (['', str_repeat('x', 5001)] as $text) {
            $this->postJson($path, ['comment' => $text])->assertUnprocessable()->assertJsonValidationErrors('comment');
        }
        $this->postJson($path, ['comment' => 'Valid', 'user_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('task_comments', 0);
    }
}
