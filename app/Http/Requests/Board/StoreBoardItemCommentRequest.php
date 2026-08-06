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
            'mentioned_user_ids' => ['sometimes', 'array'],
            'mentioned_user_ids.*' => ['integer', 'exists:users,id'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => [
                'file',
                'mimes:pdf,xlsx,xls,csv,docx,doc,pptx,ppt,png,jpg,jpeg,gif,webp',
                'max:51200',
            ],
        ];
    }
}
