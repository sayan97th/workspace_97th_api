<?php

namespace App\Http\Resources;

use App\Models\BoardDiscussionView;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single board resolved by its globally-unique id. Extends the plain
 * navigation-item payload with the owning workspace and the ancestor trail,
 * since a `/boards/{id}` client only ever knows the id — not the workspace
 * slug or the path of slugs leading to it.
 *
 * @mixin WorkspaceNavigationItem
 */
class BoardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $node = (new WorkspaceNavigationItemResource($this->resource))->toArray($request);

        return array_merge($node, [
            'workspace' => [
                'id' => $this->workspace->id,
                'slug' => $this->workspace->slug,
                'name' => $this->workspace->name,
            ],
            'breadcrumb' => $this->ancestors()->map(fn (WorkspaceNavigationItem $ancestor) => [
                'id' => $ancestor->id,
                'label' => $ancestor->label,
                'slug' => $ancestor->slug,
            ])->values(),
            'has_unseen_comments' => $this->hasUnseenComments($request),
        ]);
    }

    /**
     * Whether the requesting user has discussion updates they haven't seen
     * yet — comments (or replies) someone else posted since their last
     * {@see BoardDiscussionView}, or every comment when they've never opened
     * the drawer at all. Drives the "Board updates" badge's red/gray state
     * on the frontend; own comments never count as "unseen".
     */
    private function hasUnseenComments(Request $request): bool
    {
        $user_id = $request->user()?->id;
        if (! $user_id) {
            return false;
        }

        $last_viewed_at = BoardDiscussionView::where('board_id', $this->id)
            ->where('user_id', $user_id)
            ->value('last_viewed_at');

        return $this->comments()
            ->where('user_id', '!=', $user_id)
            ->when($last_viewed_at, fn ($query) => $query->where('created_at', '>', $last_viewed_at))
            ->exists();
    }
}
