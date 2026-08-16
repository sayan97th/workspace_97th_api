<?php

namespace App\Http\Requests\Board;

use App\Rules\ValidEmojiReaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ToggleBoardItemCommentReactionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', new ValidEmojiReaction],
        ];
    }
}
