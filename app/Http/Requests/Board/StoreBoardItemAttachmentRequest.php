<?php

namespace App\Http\Requests\Board;

use App\Rules\CommentAttachmentFile;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoardItemAttachmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => CommentAttachmentFile::rules(),
        ];
    }
}
