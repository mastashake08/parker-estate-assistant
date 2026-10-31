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
        Schema::create('audio_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('status', 20);
            $table->string('title');
            $table->text('prompt');
            $table->string('model')->nullable();
            $table->json('options');
            $table->string('path')->nullable();
            $table->string('score_path')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->float('duration')->nullable();
            $table->json('stems')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audio_generations');
    }
};
