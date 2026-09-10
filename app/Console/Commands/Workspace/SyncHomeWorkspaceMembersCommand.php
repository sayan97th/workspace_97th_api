<?php

namespace App\Console\Commands\Workspace;

use App\Models\User;
use App\Services\Workspace\HomeWorkspaceEnrollmentService;
use Illuminate\Console\Command;

// php artisan workspace:sync-home-members
class SyncHomeWorkspaceMembersCommand extends Command
{
    protected $signature = 'workspace:sync-home-members';

    protected $description = "Re-syncs every user's membership role on the home workspace(s) to match their current app-level role (staff/admin => owner, everyone else => member)";

    public function __construct(private readonly HomeWorkspaceEnrollmentService $enrollment_service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $users = User::with('roles')->get();

        $this->withProgressBar($users, function (User $user) {
            $this->enrollment_service->enroll($user);
        });

        $this->newLine(2);
        $this->info("Synced home workspace membership for {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
