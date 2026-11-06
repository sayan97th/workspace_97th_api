<?php

namespace App\Console\Commands\Board;

use App\Services\Board\BoardCollaboratorRepair;
use Illuminate\Console\Command;

/**
 * Gives back private board access to people who accepted a board invitation, or belong to a
 * team that joined the board, but have no `board_collaborators` row. See
 * {@see BoardCollaboratorRepair}. Safe to run any number of times.
 */
// php artisan board:sync-collaborators --board=3738320979 --dry-run
class SyncBoardCollaboratorsCommand extends Command
{
    protected $signature = 'board:sync-collaborators
        {--board=* : Only these board ids, every board when omitted}
        {--dry-run : Print what would change without writing to the database}';

    protected $description = 'Add the board collaborators that accepted invitations and team access should have created';

    public function handle(BoardCollaboratorRepair $repair): int
    {
        $board_ids = array_map('intval', (array) $this->option('board'));
        $is_dry_run = (bool) $this->option('dry-run');

        $rows = $is_dry_run ? $repair->findMissing($board_ids) : $repair->repair($board_ids);

        if ($rows->isEmpty()) {
            $this->info('Every accepted invitation and team member already has board access.');

            return self::SUCCESS;
        }

        $this->table(['Board', 'User', 'Source'], $rows->map(fn (array $row) => [$row['board_id'], $row['user_id'], $row['source']])->all());
        $this->info($is_dry_run
            ? "{$rows->count()} collaborator rows are missing. Run again without --dry-run to add them."
            : "Added {$rows->count()} collaborator rows.");

        return self::SUCCESS;
    }
}
