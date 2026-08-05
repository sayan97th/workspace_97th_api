<?php

namespace App\Services\Board;

use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;

/**
 * Resolves which tab (`BoardView`) a `boards/{item}/columns|groups|items`
 * request is scoped to, from an optional `view_id` param — defaulting to the
 * board's primary tab when omitted.
 */
class BoardViewResolver
{
    /**
     * For read endpoints (`index`). Never creates the primary tab — the
     * frontend fires `getColumns`/`getGroups`/`getItems`/`getViews` in one
     * `Promise.all`, so if every read endpoint independently lazy-created
     * the primary tab, a brand-new board's first load would race multiple
     * concurrent creators. Only `BoardViewController::index` is allowed to
     * create it; until it exists, these reads simply resolve to nothing.
     */
    public function resolveForRead(WorkspaceNavigationItem $item, ?int $view_id): ?BoardView
    {
        if ($view_id !== null) {
            return $item->views()->findOrFail($view_id);
        }

        return $item->views()->where('is_primary', true)->first();
    }

    /**
     * For write endpoints (`store`). A single request has no fan-out race,
     * so lazily creating the primary tab here is safe.
     */
    public function resolveForWrite(WorkspaceNavigationItem $item, ?int $view_id): BoardView
    {
        if ($view_id !== null) {
            return $item->views()->findOrFail($view_id);
        }

        return $item->views()->where('is_primary', true)->first()
            ?? $item->views()->create([
                'label' => 'Main table',
                'position' => 0,
                'is_primary' => true,
                'row_height' => 'single',
            ]);
    }
}
