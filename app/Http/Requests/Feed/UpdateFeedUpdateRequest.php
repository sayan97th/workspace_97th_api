<?php

namespace App\Http\Requests\Feed;

use App\Http\Requests\Concerns\NormalizesCommentBody;
use App\Rules\MarkdownHasContent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/feed/updates/{id}
 *
 * "Edit update" from a feed card's menu, a new Markdown body for the update
 * or reply. Normalizing line breaks keeps an untouched edit equal to the
 * stored body, so it is not recorded as a revision.
 */
class UpdateFeedUpdateRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:'.StoreFeedReplyRequest::BODY_MAX_LENGTH, new MarkdownHasContent],
        ];
    }

    /** The Markdown body without the blank lines and spaces around it. */
    public function body(): string
    {
        return trim((string) $this->validated('body'));
    }
}
