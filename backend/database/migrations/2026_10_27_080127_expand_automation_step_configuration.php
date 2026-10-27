<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_steps', function (Blueprint $table): void {
            $table->longText('configuration')->change();
        });
    }

    public function down(): void
    {
        Schema::table('automation_steps', function (Blueprint $table): void {
            $table->text('configuration')->change();
        });
    }
};
