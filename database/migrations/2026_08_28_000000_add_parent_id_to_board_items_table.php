<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_items', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('group_id')
                ->constrained('board_items')->cascadeOnDelete();

            $table->index(['board_id', 'parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('board_items', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['board_id', 'parent_id', 'position']);
            $table->dropColumn('parent_id');
        });
    }
};
