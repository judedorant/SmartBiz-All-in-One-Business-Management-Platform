<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TaskComment> */
class TaskCommentFactory extends Factory
{
    public function definition(): array
    {
        return ['task_id' => Task::factory(), 'user_id' => User::factory(), 'comment' => fake()->sentence()];
    }
}
