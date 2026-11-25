<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Color of the gradient laid over the banner image behind the greeting. Null keeps the
     * automatic one (dark under light text, light under dark text).
     */
    public function up(): void
    {
        Schema::table('home_banners', function (Blueprint $table) {
            $table->string('overlay_color', 7)->nullable()->after('overlay_opacity');
        });
    }

    public function down(): void
    {
        Schema::table('home_banners', function (Blueprint $table) {
            $table->dropColumn('overlay_color');
        });
    }
};
