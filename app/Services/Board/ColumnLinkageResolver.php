<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Support\VisibleBoards;

/**
 * What a Connect boards or Mirror column is linked to, for the column header's info popover
 * ("Linkage"), monday.com style: the boards a Connect boards column shows items from, and for
 * a Mirror column also the column and board it mirrors. Boards the user may not open are only
 * counted, never named.
 */
class ColumnLinkageResolver
{
    /**
     * @return array{boards: array<int, array{id: int, name: string}>, hidden_board_count: int, mirrored_column: array{id: int, label: string, board_id: int, board_name: string}|null}|null
     */
    public function resolve(BoardColumn $column, ?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        if ($column->type === BoardColumn::TYPE_CONNECT_BOARD) {
            return [...$this->boards($column, $user), 'mirrored_column' => null];
        }

        if ($column->type !== BoardColumn::TYPE_MIRROR) {
            return null;
        }

        $source = BoardColumn::where('type', BoardColumn::TYPE_CONNECT_BOARD)->find((int) ($column->config['source_column_id'] ?? 0));
        if (! $source) {
            return null;
        }

        $mirrored = BoardColumn::find((int) ($column->config['mirrored_column_id'] ?? 0));
        $mirrored_board = $mirrored ? VisibleBoards::query($user)->find($mirrored->board_id) : null;

        return [
            ...$this->boards($source, $user),
            'mirrored_column' => $mirrored && $mirrored_board ? [
                'id' => $mirrored->id,
                'label' => $mirrored->label,
                'board_id' => $mirrored_board->id,
                'board_name' => $mirrored_board->label,
            ] : null,
        ];
    }

    /**
     * @return array{boards: array<int, array{id: int, name: string}>, hidden_board_count: int}
     */
    private function boards(BoardColumn $column, User $user): array
    {
        $board_ids = ConnectBoardColumnService::linkedBoardIds($column);
        $visible = VisibleBoards::query($user)->whereIn('id', $board_ids)->get(['id', 'label'])->keyBy('id');
        $existing_count = WorkspaceNavigationItem::whereIn('id', $board_ids)->count();

        return [
            'boards' => collect($board_ids)
                ->map(fn (int $id) => $visible->get($id))
                ->filter()
                ->map(fn (WorkspaceNavigationItem $board) => ['id' => $board->id, 'name' => $board->label])
                ->values()
                ->all(),
            'hidden_board_count' => max(0, $existing_count - $visible->count()),
        ];
    }
}
