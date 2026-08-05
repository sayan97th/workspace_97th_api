<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Assigns every pre-existing `board_columns`/`board_groups` row to its
     * board's primary tab, so content created before per-tab scoping existed
     * isn't orphaned. Only boards that already have columns or groups are
     * touched — a board with none doesn't need a primary tab yet, since one
     * is created lazily on first load (see `BoardViewController::index`).
     */
    public function up(): void
    {
        WorkspaceNavigationItem::where('type', WorkspaceNavigationItem::TYPE_LEAF)
            ->where(fn ($query) => $query->whereHas('columns')->orWhereHas('groups'))
            ->chunkById(100, function ($boards) {
                foreach ($boards as $board) {
                    $primary_view = BoardView::where('board_id', $board->id)->where('is_primary', true)->first()
                        ?? $board->views()->create([
                            'label' => 'Main table',
                            'position' => 0,
                            'is_primary' => true,
                            'row_height' => 'single',
                        ]);

                    BoardColumn::where('board_id', $board->id)->whereNull('board_view_id')
                        ->update(['board_view_id' => $primary_view->id]);
                    BoardGroup::where('board_id', $board->id)->whereNull('board_view_id')
                        ->update(['board_view_id' => $primary_view->id]);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * Irreversible data backfill — intentionally left blank.
     */
    public function down(): void
    {
        //
    }
};
