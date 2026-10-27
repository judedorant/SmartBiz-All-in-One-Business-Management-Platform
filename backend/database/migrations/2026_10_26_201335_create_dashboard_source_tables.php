<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->decimal('amount', 15, 2);
            $t->char('currency', 3);
            $t->string('status', 16)->default('completed');
            $t->dateTime('closed_at');
            $t->uuid('request_id')->unique();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('void_reason', 500)->nullable();
            $t->timestamps();
            $t->index(['currency', 'status', 'closed_at']);
            $t->index(['customer_id', 'closed_at']);
        });
        Schema::create('tasks', function (Blueprint $t): void {
            $t->id();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('priority', 16)->default('medium');
            $t->string('status', 20)->default('pending');
            $t->dateTime('due_date')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['assigned_to', 'created_at']);
            $t->index(['status', 'due_date']);
            $t->index('completed_at');
            $t->index('created_at');
        });
        Schema::create('appointments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->dateTime('start_at');
            $t->dateTime('end_at');
            $t->string('status', 20)->default('scheduled');
            $t->timestamp('reminder_sent_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['assigned_to', 'start_at']);
            $t->index(['status', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('sales');
    }
};
