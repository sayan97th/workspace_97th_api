<?php

namespace App\Http\Requests\Feed;

use App\Http\Requests\Concerns\NormalizesCommentBody;
use App\Rules\MarkdownHasContent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/feed/updates/{id}/reply
 *
 * A reply written in the Update feed's rich text box. The body is the
 * editor's Markdown, stored as typed (line breaks normalized to LF) and
 * rendered by the frontend's `RichTextContent`, the same as a reply written
 * in the item drawer (see `StoreBoardItemCommentRequest`).
 */
class StoreFeedReplyRequest extends FormRequest
{
    use NormalizesCommentBody;

    /** Same cap as a comment posted from the item drawer. */
    public const BODY_MAX_LENGTH = 5000;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::BODY_MAX_LENGTH, new MarkdownHasContent],
            // Capped so a group mention such as `@Everyone` can't fan out into an unbounded number of notifications.
            'mentioned_user_ids' => ['sometimes', 'array', 'max:200'],
            'mentioned_user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }

    /** The Markdown body without the blank lines and spaces around it. */
    public function body(): string
    {
        return trim((string) $this->validated('body'));
    }
}
