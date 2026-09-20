<?php

namespace App\Http\Resources;

use App\Models\BoardComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A comment or (when `parent_id` is set on the underlying model) a reply,
 * for the board discussion drawer. Expects `likes`, `reactions`, `views`,
 * `mentions`, `attachments`, `author` (and, for top-level comments,
 * `replies` with the same set) to already be eager-loaded by the
 * controller. Mirrors {@see BoardItemCommentResource}.
 *
 * @mixin BoardComment
 */
class BoardCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current_user_id = $request->user()?->id;

        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'parent_id' => $this->parent_id,
            'author' => $this->author ? [
                'id' => $this->author->id,
                'full_name' => $this->author->full_name,
                'profile_photo_url' => $this->author->profile_photo_url,
                'is_deactivated' => $this->author->is_deactivated,
            ] : null,
            'body' => $this->body,
            'created_at' => $this->created_at,
            'is_edited' => $this->edited_at !== null,
            'edited_at' => $this->edited_at,
            'like_count' => $this->likes->count(),
            'liked_by_me' => $this->likes->contains('user_id', $current_user_id),
            'view_count' => $this->views->count(),
            'seen_by_me' => $this->views->contains('user_id', $current_user_id),
            'seen_by' => $this->views
                ->filter(fn ($view) => $view->user !== null)
                ->map(fn ($view) => [
                    'id' => $view->user->id,
                    'full_name' => $view->user->full_name,
                    'profile_photo_url' => $view->user->profile_photo_url,
                    'is_deactivated' => $view->user->is_deactivated,
                    'seen_at' => $view->created_at,
                ])
                ->values(),
            'bookmarked_by_me' => $this->bookmarks->contains('user_id', $current_user_id),
            'scheduled_at' => $this->scheduled_at,
            'pinned' => $this->pinned,
            'is_resolved' => $this->resolved_at !== null,
            'resolved_at' => $this->resolved_at,
            'resolved_by' => $this->resolved_at !== null && $this->resolvedBy ? [
                'id' => $this->resolvedBy->id,
                'full_name' => $this->resolvedBy->full_name,
            ] : null,
            'notified_user_ids' => $this->notifiedUsers->pluck('user_id')->values(),
            'reactions' => $this->reactions
                ->groupBy('emoji')
                ->map(fn ($group, $emoji) => [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                    'reacted_by_me' => $group->contains('user_id', $current_user_id),
                    // Who reacted and when, oldest first, for the "who reacted" popover.
                    'reactors' => $group
                        ->sortBy('created_at')
                        ->map(fn ($reaction) => [
                            'id' => $reaction->user_id,
                            'full_name' => $reaction->user?->full_name ?? __('Deleted user'),
                            'profile_photo_url' => $reaction->user?->profile_photo_url,
                            'is_deactivated' => $reaction->user?->is_deactivated ?? true,
                            'reacted_at' => $reaction->created_at,
                        ])
                        ->values(),
                    'reactor_names' => $group
                        ->map(fn ($reaction) => $reaction->user_id === $current_user_id
                            ? __('You')
                            : ($reaction->user->full_name ?? __('Deleted user')))
                        ->values(),
                ])
                ->values(),
            'mentioned_user_ids' => $this->mentions->pluck('user_id')->values(),
            'attachments' => BoardCommentAttachmentResource::collection($this->attachments),
            'replies' => self::collection($this->whenLoaded('replies')),
        ];
    }
}
