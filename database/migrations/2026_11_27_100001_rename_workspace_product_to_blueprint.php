<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The app was renamed from "Workspace 97th" to "Blueprint". Workspaces that still carry the old
 * product label move to the new one, and new rows default to it. Custom labels ("Sales CRM",
 * "Work Management") are left as they are.
 */
return new class extends Migration
{
    private const LEGACY_PRODUCT = 'Workspace 97th';

    private const PRODUCT = 'Blueprint';

    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('product')->default(self::PRODUCT)->change();
        });

        DB::table('workspaces')->where('product', self::LEGACY_PRODUCT)->update(['product' => self::PRODUCT]);
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('product')->default(self::LEGACY_PRODUCT)->change();
        });

        DB::table('workspaces')->where('product', self::PRODUCT)->update(['product' => self::LEGACY_PRODUCT]);
    }
};
