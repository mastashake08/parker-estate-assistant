<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('instructions');
            $table->string('frequency', 10);
            $table->string('time', 5)->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('every_hours')->nullable();
            $table->string('cron', 100);
            $table->string('timezone', 64);
            $table->string('model')->nullable();
            $table->json('tools');
            $table->boolean('auto_approve')->default(false);
            $table->boolean('deliver_email')->default(false);
            $table->boolean('deliver_sms')->default(false);
            $table->string('sms_to', 20)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routines');
    }
};
