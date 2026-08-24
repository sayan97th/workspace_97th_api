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
            $table->foreignId('owner_id')->nullable()->after('created_by_id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_navigation_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
