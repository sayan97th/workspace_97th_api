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
        Schema::table('workspace_navigation_items', function (Blueprint $table) {
            // A board saved to (or moved to) the workspace's templates. Templates are kept out of the
            // tree and every other board listing, see WorkspaceNavigationItem::SCOPE_WITHOUT_TEMPLATES.
            $table->boolean('is_template')->default(false)->after('is_archived');
            $table->index(['workspace_id', 'is_template']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_navigation_items', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'is_template']);
            $table->dropColumn('is_template');
        });
    }
};
