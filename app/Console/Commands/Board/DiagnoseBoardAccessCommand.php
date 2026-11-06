<?php

namespace App\Console\Commands\Board;

use App\Models\BoardInvitation;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Support\VisibleBoards;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Explains why someone can or cannot open a board, for support requests like "I accepted the
 * invitation but the board says it is private". Prints every account that shares the email or
 * the name (the usual cause is a second account), the board invitations sent to that email,
 * the teams that link the person to the board, and the final {@see VisibleBoards::canView()}.
 */
// php artisan board:diagnose-access 3738320979 rachel@97thfloor.com
class DiagnoseBoardAccessCommand extends Command
{
    protected $signature = 'board:diagnose-access
        {board : The board id, as in /boards/{id}}
        {email : The email of the person who cannot open the board}';

    protected $description = 'Explain why a person can or cannot open a board';

    public function handle(): int
    {
        $board = WorkspaceNavigationItem::withoutGlobalScopes()->withTrashed()->with(['creator', 'owner', 'workspace'])->find((int) $this->argument('board'));

        if ($board === null) {
            $this->error('No board with that id.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));

        $this->line("Board <info>{$board->label}</info> ({$board->id}), workspace ".($board->workspace->name ?? 'missing').", type <info>{$board->board_type}</info>"
            .($board->trashed() ? ', DELETED' : '').($board->is_archived ? ', ARCHIVED' : ''));
        $this->line('Creator: '.$this->describeUser($board->creator).' | Owner: '.$this->describeUser($board->owner));
        $this->newLine();

        $accounts = User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->get();
        if ($accounts->isEmpty()) {
            $this->warn("No account uses {$email}. The person must accept the invitation (or sign up) with exactly this email.");
        }

        foreach ($accounts as $account) {
            $this->line('Account '.$this->describeUser($account));
            $this->line('  Collaborator row: '.($this->isCollaborator($board, $account) ? '<info>yes</info>' : '<error>no</error>'));
            $this->line('  Workspace role: '.(DB::table('workspace_user')->where('workspace_id', $board->workspace_id)->where('user_id', $account->id)->value('role') ?? 'not a member'));
            $this->line('  Teams on this board: '.($this->sharedTeams($board, $account) ?: 'none'));
            $this->line('  Can open the board: '.(VisibleBoards::canView($account, $board) ? '<info>yes</info>' : '<error>no</error>'));

            $namesakes = User::withTrashed()
                ->where('id', '!=', $account->id)
                ->where('first_name', $account->first_name)
                ->where('last_name', $account->last_name)
                ->get();
            foreach ($namesakes as $namesake) {
                $this->warn('  Another account with the same name: '.$this->describeUser($namesake)
                    .', collaborator: '.($this->isCollaborator($board, $namesake) ? 'yes' : 'no'));
            }
        }

        $invitations = BoardInvitation::query()->where('board_id', $board->id)->whereRaw('LOWER(email) = ?', [$email])->get();
        $this->newLine();
        $this->line('Board invitations for this email: '.($invitations->isEmpty() ? 'none' : ''));
        foreach ($invitations as $invitation) {
            $status = $invitation->isAccepted() ? 'accepted '.$invitation->accepted_at : ($invitation->isExpired() ? 'expired' : 'pending');
            $this->line("  #{$invitation->id} {$invitation->email}, {$status}");
        }

        return self::SUCCESS;
    }

    private function describeUser(?User $user): string
    {
        if ($user === null) {
            return 'none';
        }

        return "#{$user->id} {$user->full_name} <{$user->email}>".($user->trashed() ? ' (deactivated)' : '');
    }

    private function isCollaborator(WorkspaceNavigationItem $board, User $user): bool
    {
        return DB::table('board_collaborators')->where('board_id', $board->id)->where('user_id', $user->id)->exists();
    }

    private function sharedTeams(WorkspaceNavigationItem $board, User $user): string
    {
        return DB::table('account_team_boards')
            ->join('account_team_user', 'account_team_user.account_team_id', '=', 'account_team_boards.account_team_id')
            ->join('account_teams', 'account_teams.id', '=', 'account_team_boards.account_team_id')
            ->where('account_team_boards.board_id', $board->id)
            ->where('account_team_user.user_id', $user->id)
            ->pluck('account_teams.name')
            ->join(', ');
    }
}
