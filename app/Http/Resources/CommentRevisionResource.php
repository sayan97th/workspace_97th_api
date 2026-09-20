<?php

namespace App\Http\Resources;

use App\Models\BoardCommentRevision;
use App\Models\BoardItemCommentRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One earlier version of an edited comment or reply, for the "(edited)"
 * popover in the item drawer and the board discussion drawer. Expects
 * `editor` to be eager-loaded.
 *
 * @mixin BoardItemCommentRevision|BoardCommentRevision
 */
class CommentRevisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            // When this version was written, then when an edit replaced it.
            'written_at' => $this->body_written_at,
            'replaced_at' => $this->created_at,
            'edited_by' => $this->editor ? [
                'id' => $this->editor->id,
                'full_name' => $this->editor->full_name,
                'profile_photo_url' => $this->editor->profile_photo_url,
                'is_deactivated' => $this->editor->is_deactivated,
            ] : null,
        ];
    }
}
