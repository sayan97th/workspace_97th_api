<?php

namespace App\Http\Requests\Comment;

use App\Models\CommentDraft;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/boards/{item}/comment-drafts
 *
 * Saves the draft of one composer: the item's (or, without `item_id`, the
 * board discussion's) "Write an update" box, or the reply box of the update
 * `parent_id` points at. An empty `body` removes the draft instead.
 */
class UpsertCommentDraftRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $board = $this->route('item');
        $board_id = $board instanceof WorkspaceNavigationItem ? $board->id : null;
        $item_id = $this->filled('item_id') ? (int) $this->input('item_id') : null;

        // A reply answers a top level update of the same item, or of the board discussion.
        $parent_rule = $item_id !== null
            ? Rule::exists('board_item_comments', 'id')->where(fn ($query) => $query->where('item_id', $item_id)->whereNull('parent_id')->whereNull('deleted_at'))
            : Rule::exists('board_comments', 'id')->where(fn ($query) => $query->where('board_id', $board_id)->whereNull('parent_id')->whereNull('deleted_at'));

        return [
            'item_id' => [
                'nullable', 'integer',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query->where('board_id', $board_id)->whereNull('deleted_at')),
            ],
            'parent_id' => ['nullable', 'integer', $parent_rule],
            'body' => ['present', 'nullable', 'string', 'max:'.CommentDraft::MAX_BODY_LENGTH],
            'mentioned_user_ids' => ['sometimes', 'array', 'max:200'],
            'mentioned_user_ids.*' => ['integer'],
            'notified_user_ids' => ['sometimes', 'array', 'max:200'],
            'notified_user_ids.*' => ['integer'],
        ];
    }
}
