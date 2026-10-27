<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->index(['assigned_to', 'status', 'due_date']);
            $table->index(['status', 'priority']);
        });
        Schema::create('task_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('comment');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['assigned_to', 'status', 'due_date']);
            $table->dropIndex(['status', 'priority']);
            $table->dropColumn('version');
        });
    }
};
