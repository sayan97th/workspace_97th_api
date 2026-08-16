<?php

use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Workspaces created before the invite-link columns existed have a null
     * `invite_code`; only {@see Workspace}'s `creating()` hook generates one,
     * so already-seeded rows need a one-time backfill here.
     */
    public function up(): void
    {
        Workspace::withTrashed()
            ->whereNull('invite_code')
            ->each(function (Workspace $workspace) {
                $workspace->update(['invite_code' => Str::random(48)]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nothing to reverse, regenerated codes replace nulls one-way.
    }
};
