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
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
