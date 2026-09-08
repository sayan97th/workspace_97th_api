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
        Schema::table('users', function (Blueprint $table) {
            // The workspace the user last had open — read back on login/page
            // reload so the switcher restores it instead of always defaulting
            // to the home workspace. Nulled out (not cascaded) if that
            // workspace is later hard-deleted, mirroring `current_team_id`.
            $table->foreignId('last_active_workspace_id')
                ->nullable()
                ->after('current_team_id')
                ->constrained('workspaces')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_active_workspace_id');
        });
    }
};
