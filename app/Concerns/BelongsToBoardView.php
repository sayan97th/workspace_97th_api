<?php

namespace App\Concerns;

use App\Models\BoardView;

/**
 * Auto-resolves a model's `board_view_id` to its board's primary tab when a
 * caller creates the row without specifying one — a safety net for direct
 * `create()` calls (factories, seeders, console commands) that predate
 * per-tab content scoping and only know about `board_id`. The board's
 * primary view is created on the fly if it doesn't exist yet.
 */
trait BelongsToBoardView
{
    protected static function bootBelongsToBoardView(): void
    {
        static::creating(function ($model) {
            if ($model->board_view_id !== null) {
                return;
            }

            $model->board_view_id = BoardView::where('board_id', $model->board_id)
                ->where('is_primary', true)
                ->value('id')
                ?? BoardView::create([
                    'board_id' => $model->board_id,
                    'label' => 'Main table',
                    'position' => 0,
                    'is_primary' => true,
                    'row_height' => 'single',
                ])->id;
        });
    }
}
