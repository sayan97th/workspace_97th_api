<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ToggleBoardItemCommentReactionRequest extends FormRequest
{
    /**
     * The emoji offered by the drawer's react/insert palette
     * (`DRAWER_EMOJI_OPTIONS` on the frontend) — kept in sync here so a
     * request can't record an arbitrary reaction string.
     *
     * @var array<int, string>
     */
    public const ALLOWED_EMOJI = ['👍', '❤️', '😄', '🎉', '😍', '😂', '🙏', '🔥', '👀', '✅', '💯', '🚀'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', Rule::in(self::ALLOWED_EMOJI)],
        ];
    }
}
