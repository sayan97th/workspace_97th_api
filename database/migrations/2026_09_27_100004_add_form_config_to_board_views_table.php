<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A `form`-type view stores its builder settings in `form_config` and is
     * reachable publicly through the unguessable `form_token`.
     */
    public function up(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->json('form_config')->nullable()->after('chart_config');
            $table->string('form_token', 64)->nullable()->unique()->after('form_config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->dropUnique(['form_token']);
            $table->dropColumn(['form_config', 'form_token']);
        });
    }
};
