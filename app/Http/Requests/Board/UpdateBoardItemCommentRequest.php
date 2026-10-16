<?php

namespace App\Http\Requests\Board;

use App\Http\Requests\Concerns\NormalizesCommentBody;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBoardItemCommentRequest extends FormRequest
{
    use NormalizesCommentBody;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // May be empty when the update keeps (or gets) at least one file,
            // the controller checks that once it knows which files remain.
            'body' => ['present', 'nullable', 'string', 'max:5000'],
            // Files added while editing, same limits as when posting.
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => [
                'file',
                'mimes:pdf,xlsx,xls,csv,docx,doc,pptx,ppt,png,jpg,jpeg,gif,webp',
                'max:51200',
            ],
            // Ids of the update's own attachments the author took off.
            'removed_attachment_ids' => ['sometimes', 'array'],
            'removed_attachment_ids.*' => ['integer'],
        ];
    }
}
