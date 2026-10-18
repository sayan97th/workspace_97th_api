<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Import items" wizard can now keep a monday.com export's groups and
 * subitems, so the progress step reports how many of each it created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_import_jobs', function (Blueprint $table) {
            $table->unsignedInteger('groups_created')->default(0)->after('columns_created');
            $table->unsignedInteger('subitems_created')->default(0)->after('groups_created');
        });
    }

    public function down(): void
    {
        Schema::table('board_import_jobs', function (Blueprint $table) {
            $table->dropColumn(['groups_created', 'subitems_created']);
        });
    }
};
