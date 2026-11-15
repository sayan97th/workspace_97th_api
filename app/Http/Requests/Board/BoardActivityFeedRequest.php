<?php

namespace App\Http\Requests\Board;

use App\Services\Board\BoardActivityFeed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Filters of the board Activity log ("Filter log", Search, Person and
 * "Automation activity"), shared by the list and its CSV export.
 */
class BoardActivityFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $event_types = [...array_keys(BoardActivityFeed::BOARD_EVENT_TYPES), ...BoardActivityFeed::CELL_EVENT_TYPES];

        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'user_ids' => ['sometimes', 'array', 'max:50'],
            'user_ids.*' => ['integer'],
            'column_ids' => ['sometimes', 'array', 'max:100'],
            'column_ids.*' => ['integer'],
            'group_ids' => ['sometimes', 'array', 'max:100'],
            'group_ids.*' => ['integer'],
            'event_types' => ['sometimes', 'array'],
            'event_types.*' => ['string', Rule::in(array_unique($event_types))],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'automation_only' => ['sometimes', 'boolean'],
            'with_facets' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The validated filters in the shape {@see BoardActivityFeed::page()} reads.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'search' => trim((string) ($validated['search'] ?? '')),
            'user_ids' => array_map('intval', $validated['user_ids'] ?? []),
            'column_ids' => array_map('intval', $validated['column_ids'] ?? []),
            'group_ids' => array_map('intval', $validated['group_ids'] ?? []),
            'event_types' => array_values($validated['event_types'] ?? []),
            'from' => ! empty($validated['from']) ? Carbon::parse($validated['from']) : null,
            'to' => ! empty($validated['to']) ? Carbon::parse($validated['to']) : null,
            'automation_only' => $this->boolean('automation_only'),
        ];
    }
}
