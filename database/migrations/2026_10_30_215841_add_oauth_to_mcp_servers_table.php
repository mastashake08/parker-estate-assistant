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
        Schema::table('mcp_servers', function (Blueprint $table) {
            $table->string('oauth_client_id')->nullable()->after('headers');
            $table->text('oauth_client_secret')->nullable()->after('oauth_client_id');
            $table->string('oauth_scope')->nullable()->after('oauth_client_secret');
            $table->text('oauth_tokens')->nullable()->after('oauth_scope');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcp_servers', function (Blueprint $table) {
            $table->dropColumn(['oauth_client_id', 'oauth_client_secret', 'oauth_scope', 'oauth_tokens']);
        });
    }
};
