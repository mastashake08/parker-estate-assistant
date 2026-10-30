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
        Schema::create('mcp_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('transport', 10);
            $table->string('command')->nullable();
            $table->json('args')->nullable();
            $table->text('env')->nullable();
            $table->string('url', 2048)->nullable();
            $table->text('headers')->nullable();
            $table->unsignedSmallInteger('timeout')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_servers');
    }
};
