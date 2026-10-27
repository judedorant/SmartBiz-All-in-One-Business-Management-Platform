<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $t): void {
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('staff_schedules', function (Blueprint $t): void {
            $t->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->string('timezone', 100)->default('UTC');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('staff_availability', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('day_of_week');
            $t->time('start_time');
            $t->time('end_time');
            $t->timestamps();
            $t->unique(['user_id', 'day_of_week', 'start_time']);
        });
        Schema::create('appointment_reminders', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('appointment_version');
            $t->string('status', 16)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('error_code', 60)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['appointment_id', 'appointment_version']);
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reminders');
        Schema::dropIfExists('staff_availability');
        Schema::dropIfExists('staff_schedules');
        Schema::table('appointments', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn('version');
        });
    }
};
