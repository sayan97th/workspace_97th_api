<?php

namespace App\Enums;

use App\Models\BoardView;

/**
 * The kind of content a {@see BoardView} tab renders — chosen
 * once from the "Board views" picker when the tab is created (see
 * `BoardViewController::store()`) and immutable afterward, since each kind
 * drives a structurally different frontend component (table grid vs. Kanban
 * lanes, …). Mirrors the frontend's `BOARD_VIEW_TYPES` registry in
 * `src/components/board/boardViewTypes.ts` — both lists must stay in sync.
 */
enum BoardViewType: string
{
    case Table = 'table';
    case Kanban = 'kanban';
    case Gantt = 'gantt';
    case Chart = 'chart';
    case Calendar = 'calendar';
    case Canvas = 'canvas';
    case Doc = 'doc';
    case FileGallery = 'file_gallery';
    case Form = 'form';
    case Dashboard = 'dashboard';

    /**
     * Whether a new tab of this kind is created as a filter view: it shows the
     * board's Main table items, groups and columns instead of an empty data
     * set of its own, and keeps only its own filter/sort/display settings
     * (see `BoardView::$source_view_id`). Kanban is left out on purpose, it
     * provisions its own card columns the first time it opens.
     */
    public function isFilterViewByDefault(): bool
    {
        return in_array($this, [self::Table, self::Gantt, self::Calendar], true);
    }

    /**
     * Whether a tab of this kind renders items, so it can be linked to
     * another tab's data at all.
     */
    public function canShowItems(): bool
    {
        return in_array($this, [self::Table, self::Kanban, self::Gantt, self::Calendar], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
