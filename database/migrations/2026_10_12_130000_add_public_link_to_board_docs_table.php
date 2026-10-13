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
        // The Share dialog's "Share public link": a read only link anyone can open.
        // The token is kept when the link is turned off, so turning it back on restores the same url.
        Schema::table('board_docs', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('content');
            $table->boolean('is_public_link_enabled')->default(false)->after('public_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_docs', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'is_public_link_enabled']);
        });
    }
};
