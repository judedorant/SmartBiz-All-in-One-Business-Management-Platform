<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t): void {
            $t->id();
            $t->string('customer_code', 32)->unique();
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('email')->nullable()->unique();
            $t->string('phone', 40)->nullable();
            $t->string('company', 200)->nullable();
            $t->text('address')->nullable();
            $t->string('status', 16)->default('active')->index();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['last_name', 'first_name']);
            $t->index('created_at');
        });
        Schema::create('customer_user', function (Blueprint $t): void {
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['customer_id', 'user_id']);
            $t->index(['user_id', 'customer_id']);
            $t->timestamps();
        });
        Schema::create('pipeline_stages', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 50);
            $t->string('slug', 30)->unique();
            $t->unsignedTinyInteger('position')->unique();
            $t->unsignedTinyInteger('probability');
            $t->timestamps();
        });
        Schema::create('leads', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('pipeline_stage_id')->constrained()->restrictOnDelete();
            $t->string('title', 200);
            $t->string('source', 100);
            $t->decimal('estimated_value', 15, 2)->default(0);
            $t->date('expected_close_date')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('converted_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['assigned_to', 'pipeline_stage_id']);
            $t->index(['pipeline_stage_id', 'created_at']);
            $t->index('expected_close_date');
        });
        foreach (['customer', 'lead'] as $owner) {
            Schema::create($owner.'_activities', function (Blueprint $t) use ($owner): void {
                $t->id();
                $t->foreignId($owner.'_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->restrictOnDelete();
                $t->string('activity_type', 40);
                $t->text('description');
                $t->timestamps();
                $t->index([$owner.'_id', 'created_at']);
            });
        }
        Schema::create('customer_notes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->text('body');
            $t->timestamps();
            $t->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['customer_notes', 'lead_activities', 'customer_activities', 'leads', 'pipeline_stages', 'customer_user', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
