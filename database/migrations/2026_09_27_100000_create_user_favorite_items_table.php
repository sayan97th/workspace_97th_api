<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Favorites are personal in monday.com: starring a board only adds it to
     * the starring user's own Favorites list. The old shared
     * `workspace_navigation_items.is_favorite` flag is copied over to each
     * item's creator so nobody loses a star they already had.
     */
    public function up(): void
    {
        Schema::create('user_favorite_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('navigation_item_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'navigation_item_id']);
        });

        $now = now();
        $rows = DB::table('workspace_navigation_items')
            ->where('is_favorite', true)
            ->whereNotNull('created_by_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'created_by_id'])
            ->map(fn ($item, $index) => [
                'user_id' => $item->created_by_id,
                'navigation_item_id' => $item->id,
                'position' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('user_favorite_items')->insertOrIgnore($rows);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_favorite_items');
    }
};
