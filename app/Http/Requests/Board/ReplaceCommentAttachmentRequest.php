<?php

namespace App\Http\Requests\Board;

use App\Rules\CommentAttachmentFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The new file for one attachment of a posted update (item drawer or board
 * discussion), same types and limits as when posting.
 */
class ReplaceCommentAttachmentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', ...CommentAttachmentFile::rules()],
        ];
    }
}
