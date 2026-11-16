<?php

namespace App\Services\Board;

use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;

/**
 * Resolves which tab (`BoardView`) a `boards/{item}/columns|groups|items`
 * request is scoped to, from an optional `view_id` param, defaulting to the
 * board's primary tab when omitted.
 *
 * A filter view (`source_view_id` set) has no content of its own: it shows
 * its source tab's items, groups and columns. {@see resolveForRead()} and
 * {@see resolveForWrite()} therefore return the tab that stores the content,
 * so a filter view reads and writes the very same rows as the Main table and
 * every change shows in both. Use {@see resolveTab()} when the tab itself is
 * needed (its label or its own saved filter/hidden columns).
 */
class BoardViewResolver
{
    /**
     * For read endpoints (`index`). Never creates the primary tab, since the
     * frontend fires `getColumns`/`getGroups`/`getItems`/`getViews` in one
     * `Promise.all`, so if every read endpoint independently lazy-created
     * the primary tab, a brand-new board's first load would race multiple
     * concurrent creators. Only `BoardViewController::index` is allowed to
     * create it; until it exists, these reads simply resolve to nothing.
     */
    public function resolveForRead(WorkspaceNavigationItem $item, ?int $view_id): ?BoardView
    {
        $view = $this->resolveTab($item, $view_id);

        return $view === null ? null : $this->contentViewOf($item, $view);
    }

    /**
     * For write endpoints (`store`). A single request has no fan-out race,
     * so lazily creating the primary tab here is safe.
     */
    public function resolveForWrite(WorkspaceNavigationItem $item, ?int $view_id): BoardView
    {
        $view = $this->resolveTab($item, $view_id)
            ?? $item->views()->create([
                'label' => 'Main table',
                'position' => 0,
                'is_primary' => true,
                'row_height' => 'single',
            ]);

        return $this->contentViewOf($item, $view);
    }

    /**
     * The tab itself, without following a filter view to its source.
     */
    public function resolveTab(WorkspaceNavigationItem $item, ?int $view_id): ?BoardView
    {
        if ($view_id !== null) {
            return $item->views()->findOrFail($view_id);
        }

        return $item->views()->where('is_primary', true)->first();
    }

    /**
     * The tab that stores `$view`'s content. A source that no longer exists
     * falls back to the tab itself.
     */
    public function contentViewOf(WorkspaceNavigationItem $item, BoardView $view): BoardView
    {
        if (! $view->isFilterView()) {
            return $view;
        }

        return $item->views()->find($view->source_view_id) ?? $view;
    }
}
