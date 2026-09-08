<?php

use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `created_by` is the permanent record of who created the workspace —
     * unlike `invite_generated_by` (which tracks whoever last generated the
     * share link) this is never overwritten, so it can gate "the creator can
     * never be removed" regardless of later ownership transfers.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('invite_generated_by')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill existing workspaces: prefer whoever has held the "owner"
        // pivot row the longest, falling back to `invite_generated_by` when a
        // workspace somehow has no owner on record.
        Workspace::withTrashed()->whereNull('created_by')->each(function (Workspace $workspace) {
            $earliest_owner_id = DB::table('workspace_user')
                ->where('workspace_id', $workspace->id)
                ->where('role', 'owner')
                ->orderBy('created_at')
                ->value('user_id');

            $workspace->update(['created_by' => $earliest_owner_id ?? $workspace->invite_generated_by]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
