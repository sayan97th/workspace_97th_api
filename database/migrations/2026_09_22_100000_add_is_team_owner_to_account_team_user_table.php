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
        // A team owner is a member the admins delegated the roster to, mirroring monday.com's
        // "team owners". It lives on the membership row so an owner is always a member and
        // leaving the team drops the delegation automatically.
        Schema::table('account_team_user', function (Blueprint $table) {
            $table->boolean('is_team_owner')->default(false)->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_team_user', function (Blueprint $table) {
            $table->dropColumn('is_team_owner');
        });
    }
};
