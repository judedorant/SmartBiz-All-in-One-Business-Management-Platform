<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflows', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 150);
            $t->string('trigger_type', 50)->index();
            $t->json('configuration');
            $t->boolean('is_active')->default(false)->index();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('automation_events', function (Blueprint $t): void {
            $t->id();
            $t->string('event_key', 191)->unique();
            $t->string('trigger_type', 50);
            $t->string('subject_type', 30);
            $t->unsignedBigInteger('subject_id');
            $t->unsignedInteger('subject_version')->default(1);
            $t->json('context');
            $t->timestamp('occurred_at');
            $t->timestamp('processed_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('automation_logs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('workflow_id')->constrained('automation_workflows')->restrictOnDelete();
            $t->foreignId('event_id')->constrained('automation_events')->restrictOnDelete();
            $t->unsignedInteger('workflow_version');
            $t->string('execution_status', 30)->default('pending')->index();
            $t->json('execution_data')->nullable();
            $t->string('error_message')->nullable();
            $t->timestamp('executed_at')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->uuid('lease_token')->nullable();
            $t->timestamps();
            $t->unique(['workflow_id', 'event_id']);
        });
        Schema::create('automation_steps', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('log_id')->constrained('automation_logs')->cascadeOnDelete();
            $t->unsignedSmallInteger('action_index');
            $t->string('action_type', 40);
            $t->text('configuration');
            $t->string('status', 30)->default('pending');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->boolean('retryable')->default(false);
            $t->string('error_code')->nullable();
            $t->timestamps();
            $t->unique(['log_id', 'action_index']);
        });
        Schema::create('integration_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('provider', 40);
            $t->string('external_account_id', 191);
            $t->string('display_email')->nullable();
            $t->text('encrypted_credentials')->nullable();
            $t->string('status', 30)->default('connected');
            $t->timestamps();
            $t->unique(['user_id', 'provider', 'external_account_id'], 'integration_identity_unique');
        });
        Schema::create('oauth_states', function (Blueprint $t): void {
            $t->id();
            $t->char('state_hash', 64)->unique();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 40);
            $t->char('session_hash', 64);
            $t->text('verifier');
            $t->timestamp('expires_at')->index();
            $t->timestamp('used_at')->nullable();
        });
        Schema::create('webhook_endpoints', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 150);
            $t->string('direction', 10);
            $t->string('endpoint_url', 2048)->nullable();
            $t->text('secret');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('webhook_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->uuid('delivery_id')->unique();
            $t->foreignId('webhook_endpoint_id')->constrained()->restrictOnDelete();
            $t->foreignId('step_id')->unique()->constrained('automation_steps')->restrictOnDelete();
            $t->string('event_type', 50);
            $t->text('payload');
            $t->string('status', 30)->default('pending');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('last_attempt_at')->nullable();
            $t->timestamps();
        });
        Schema::create('webhook_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('webhook_endpoint_id')->constrained()->restrictOnDelete();
            $t->uuid('delivery_id');
            $t->uuid('nonce');
            $t->char('body_hash', 64);
            $t->timestamps();
            $t->unique(['webhook_endpoint_id', 'delivery_id'], 'webhook_receipt_delivery_unique');
            $t->unique(['webhook_endpoint_id', 'nonce'], 'webhook_receipt_nonce_unique');
        });
    }

    public function down(): void
    {
        foreach (['webhook_receipts', 'webhook_deliveries', 'webhook_endpoints', 'oauth_states', 'integration_accounts', 'automation_steps', 'automation_logs', 'automation_events', 'automation_workflows'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
