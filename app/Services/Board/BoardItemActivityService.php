<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemActivity;
use App\Models\BoardItemComment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Records and reads the per-item change history the Update Feed weaves
 * between an item's updates. Only a real change to a cell is recorded, and
 * the old and new values are stored as display text (a status label, a
 * person's name, a formatted date) so the entry stays readable even after the
 * column is renamed or its options are edited.
 */
class BoardItemActivityService
{
    /** Longest display text stored per side of a change. */
    private const MAX_DISPLAY_LENGTH = 120;

    /** Most changes shown under one update. */
    public const MAX_PER_UPDATE = 10;

    /** Column types whose values are computed or too bulky to describe as a change. */
    private const SKIPPED_TYPES = [
        BoardColumn::TYPE_FILES,
        BoardColumn::TYPE_CHECKLIST,
        BoardColumn::TYPE_TIME_TRACKING,
        BoardColumn::TYPE_FORMULA,
        BoardColumn::TYPE_MIRROR,
        BoardColumn::TYPE_AUTO_NUMBER,
    ];

    /**
     * Stores one entry when `$old_value` and `$new_value` really differ.
     */
    public function record(BoardItem $board_item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if (in_array($column->type, self::SKIPPED_TYPES, true)) {
            return;
        }

        if ($this->normalize($old_value) === $this->normalize($new_value)) {
            return;
        }

        BoardItemActivity::create([
            'item_id' => $board_item->id,
            'user_id' => $actor?->id,
            'column_id' => $column->id,
            'column_label' => Str::limit((string) $column->label, 100, ''),
            'column_type' => $column->type,
            'old_display' => $this->display($column, $old_value),
            'new_display' => $this->display($column, $new_value),
        ]);
    }

    /**
     * The changes made to each item between its previous update and the given
     * update, newest first and capped at {@see MAX_PER_UPDATE}, keyed by the
     * update's id. Resolved for a whole page in three queries so the feed
     * never asks one question per card.
     *
     * @param  Collection<int, BoardItemComment>  $comments
     * @return array<int, array{total: int, entries: Collection<int, BoardItemActivity>}>
     */
    public function forUpdates(Collection $comments): array
    {
        $top_level = $comments->filter(fn ($comment) => $comment->parent_id === null && $comment->created_at !== null);
        if ($top_level->isEmpty()) {
            return [];
        }

        $item_ids = $top_level->pluck('item_id')->unique()->values();

        $timeline = BoardItemComment::query()
            ->whereIn('item_id', $item_ids)
            ->whereNull('parent_id')
            ->visibleNow()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'item_id', 'created_at'])
            ->groupBy('item_id');

        $activities = BoardItemActivity::query()
            ->whereIn('item_id', $item_ids)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('item_id');

        $result = [];
        foreach ($top_level as $comment) {
            $previous_at = null;
            foreach ($timeline->get($comment->item_id, collect()) as $sibling) {
                if ($sibling->id === $comment->id) {
                    break;
                }
                $previous_at = $sibling->created_at;
            }

            $window = $activities->get($comment->item_id, collect())->filter(
                fn (BoardItemActivity $activity) => $activity->created_at <= $comment->created_at
                    && ($previous_at === null || $activity->created_at > $previous_at)
            )->values();

            $result[$comment->id] = ['total' => $window->count(), 'entries' => $window->take(self::MAX_PER_UPDATE)->values()];
        }

        return $result;
    }

    /**
     * Human readable text for one side of a change, or null when it is empty.
     */
    private function display(BoardColumn $column, mixed $value): ?string
    {
        if ($this->normalize($value) === null) {
            return null;
        }

        $text = match ($column->type) {
            BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL => $this->optionLabels($column, [$value]),
            BoardColumn::TYPE_DROPDOWN => $this->optionLabels($column, (array) $value),
            BoardColumn::TYPE_TAGS => implode(', ', array_map(fn ($tag) => (string) (is_array($tag) ? ($tag['label'] ?? $tag['name'] ?? '') : $tag), (array) $value)),
            BoardColumn::TYPE_PEOPLE => $this->peopleNames((array) $value),
            BoardColumn::TYPE_DATE => $this->dateText($value),
            BoardColumn::TYPE_TIMELINE => $this->timelineText($value),
            BoardColumn::TYPE_CHECKBOX => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Checked' : 'Unchecked',
            BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_RATING, BoardColumn::TYPE_PROGRESS, BoardColumn::TYPE_TEXT,
            BoardColumn::TYPE_LONG_TEXT, BoardColumn::TYPE_PHONE, BoardColumn::TYPE_EMAIL => is_scalar($value) ? (string) $value : null,
            default => null,
        };

        $text = $text === null ? 'Updated' : trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return $text === '' ? null : Str::limit($text, self::MAX_DISPLAY_LENGTH);
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function optionLabels(BoardColumn $column, array $ids): string
    {
        $options = collect($column->config['options'] ?? [])->keyBy(fn ($option) => (string) ($option['id'] ?? ''));

        return collect($ids)
            ->map(fn ($id) => $options->get((string) $id)['label'] ?? (string) $id)
            ->filter(fn ($label) => $label !== '')
            ->implode(', ');
    }

    /**
     * @param  array<int, mixed>  $user_ids
     */
    private function peopleNames(array $user_ids): string
    {
        return User::query()
            ->whereIn('id', array_filter($user_ids, 'is_numeric'))
            ->get()
            ->map(fn (User $user) => $user->full_name)
            ->implode(', ');
    }

    private function dateText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('M j, Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function timelineText(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $start = $this->dateText($value['start'] ?? $value[0] ?? null);
        $end = $this->dateText($value['end'] ?? $value[1] ?? null);

        return $start !== null && $end !== null ? "{$start} to {$end}" : ($start ?? $end);
    }

    /**
     * Canonical form used to decide whether a value changed: empty values
     * collapse to null and lists are compared regardless of key order.
     */
    private function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === [] || $value === false) {
            return $value === false ? 'false' : null;
        }

        if (is_array($value)) {
            ksort($value);
        }

        return json_encode($value);
    }
}
