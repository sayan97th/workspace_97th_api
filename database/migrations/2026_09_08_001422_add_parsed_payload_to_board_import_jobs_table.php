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
        Schema::table('board_import_jobs', function (Blueprint $table) {
            // Holds the parsed headers/rows the job actually writes, copied
            // in from the token-keyed disk cache at commit() time — the
            // queue worker reads this column instead of that cache file, so
            // a worker running on a different instance/filesystem than the
            // web process that handled commit() can still find its data.
            $table->json('parsed_payload')->nullable()->after('options');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_import_jobs', function (Blueprint $table) {
            $table->dropColumn('parsed_payload');
        });
    }
};
