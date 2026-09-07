<?php

namespace App\Services\Board;

use App\Models\BoardActivityLog;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;

/**
 * Records one entry in a board's Activity log (the "..." menu's "Activity
 * log" item) — see {@see BoardActivityLog} for what this is (and isn't)
 * meant to track.
 */
class BoardActivityLogger
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(WorkspaceNavigationItem $board, ?User $user, string $action, string $description, array $meta = []): BoardActivityLog
    {
        return BoardActivityLog::create([
            'board_id' => $board->id,
            'user_id' => $user?->id,
            'action' => $action,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);
    }
}
