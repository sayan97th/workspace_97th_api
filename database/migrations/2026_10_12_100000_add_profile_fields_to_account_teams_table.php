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
        Schema::table('account_teams', function (Blueprint $table) {
            // A sub-team points at its parent team, top level teams have none.
            $table->foreignId('parent_id')->nullable()->after('name')->constrained('account_teams')->nullOnDelete();
            // When true the roster may include guests (client accounts), not only staff.
            $table->boolean('allow_guests')->default(false)->after('parent_id');
            $table->string('picture_path')->nullable()->after('allow_guests');
            $table->string('cover_path')->nullable()->after('picture_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['allow_guests', 'picture_path', 'cover_path']);
        });
    }
};
