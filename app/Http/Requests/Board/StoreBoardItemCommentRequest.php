<?php

namespace App\Http\Requests\Board;

use App\Models\BoardItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoardItemCommentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * A reply's `parent_id` must point to an existing, top-level (not itself
     * a reply) comment on the same item — one level of nesting only, mirroring
     * `base_clients_api`'s `OrderSessionComment::store()` guard.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $board_item = $this->route('board_item');
        $item_id = $board_item instanceof BoardItem ? $board_item->id : null;

        return [
            // A comment needs either body text or at least one attachment — not
            // necessarily both, so a card can be attached a file directly (no
            // written update required), mirroring Trello's "Attachment" button.
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:5000'],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('board_item_comments', 'id')->where(
                    fn ($query) => $query->where('item_id', $item_id)->whereNull('parent_id')
                ),
            ],
            // Capped so a group mention such as `@Everyone` (expanded to its members' ids by the
            // client) can't fan out into an unbounded number of notifications.
            'mentioned_user_ids' => ['sometimes', 'array', 'max:200'],
            'mentioned_user_ids.*' => ['integer', 'exists:users,id'],
            // People explicitly flagged via the composer's "Notify" action —
            // distinct from `mentioned_user_ids` (never shown inline in the body).
            'notified_user_ids' => ['sometimes', 'array'],
            'notified_user_ids.*' => ['integer', 'exists:users,id'],
            // Held back and published by `feed:publish-scheduled` once due, see ScheduledCommentService.
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            // The composer's "Assign" action, a comment turned into a task. Not combinable with a
            // schedule, since the assignment happens the moment the comment is posted.
            'assign_user_ids' => ['sometimes', 'array', 'max:20', 'prohibits:scheduled_at'],
            'assign_user_ids.*' => ['integer', 'exists:users,id'],
            'assign_due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'prohibits:scheduled_at'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => [
                'file',
                'mimes:pdf,xlsx,xls,csv,docx,doc,pptx,ppt,png,jpg,jpeg,gif,webp',
                'max:51200',
            ],
        ];
    }
}
