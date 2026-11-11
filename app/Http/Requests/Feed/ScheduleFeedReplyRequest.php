<?php

namespace App\Http\Requests\Feed;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * POST /api/feed/updates/{id}/schedule
 *
 * Same body and mentions as {@see StoreFeedReplyRequest}, plus the future
 * time the reply is published at.
 */
class ScheduleFeedReplyRequest extends StoreFeedReplyRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'scheduled_at' => ['required', 'date', 'after:now'],
        ];
    }
}
