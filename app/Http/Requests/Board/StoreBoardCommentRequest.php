<?php

namespace App\Http\Requests\Board;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoardCommentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * A reply's `parent_id` must point to an existing, top-level (not itself
     * a reply) comment on the same board — one level of nesting only,
     * mirroring {@see StoreBoardItemCommentRequest}.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $board = $this->route('item');
        $board_id = $board instanceof WorkspaceNavigationItem ? $board->id : null;

        return [
            // A comment needs either body text or at least one attachment — not
            // necessarily both, so an update can be attached a file directly
            // (no written update required), mirroring the item drawer.
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:5000'],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('board_comments', 'id')->where(
                    fn ($query) => $query->where('board_id', $board_id)->whereNull('parent_id')
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
