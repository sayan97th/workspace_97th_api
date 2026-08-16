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
            ] : null,
            'body' => $this->body,
            'created_at' => $this->created_at,
            'is_edited' => $this->edited_at !== null,
            'like_count' => $this->likes->count(),
            'liked_by_me' => $this->likes->contains('user_id', $current_user_id),
            'view_count' => $this->views->count(),
            'seen_by_me' => $this->views->contains('user_id', $current_user_id),
            'reactions' => $this->reactions
                ->groupBy('emoji')
                ->map(fn ($group, $emoji) => [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                    'reacted_by_me' => $group->contains('user_id', $current_user_id),
                ])
                ->values(),
            'mentioned_user_ids' => $this->mentions->pluck('user_id')->values(),
            'attachments' => BoardCommentAttachmentResource::collection($this->attachments),
            'replies' => self::collection($this->whenLoaded('replies')),
        ];
    }
}
