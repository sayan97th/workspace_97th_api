<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's personal collapse/expand state for the folders (`WorkspaceNavigationItem`
 * of type `group`) in one workspace's sidebar navigation tree — a viewer-scoped
 * preference, mirroring {@link BoardGroupCollapseState}'s per-tab table collapse
 * state. Only the ids of the *collapsed* folders are stored (not one row/flag per
 * folder), which is what keeps this cheap to read/write even for a workspace with
 * hundreds of folders, most of which stay expanded. See
 * {@link \App\Http\Controllers\Workspace\WorkspaceNavigationItemController::updateCollapsedState()}.
 *
 * @property int $id
 * @property int $user_id
 * @property int $workspace_id
 * @property array<int, int> $collapsed_group_ids
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Workspace $workspace
 */
#[Fillable(['user_id', 'workspace_id', 'collapsed_group_ids'])]
class WorkspaceNavCollapseState extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collapsed_group_ids' => 'array',
        ];
    }
}
