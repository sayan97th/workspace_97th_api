<?php

namespace App\Services\Board\AutomationActions;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\User;
use App\Services\Board\BoardAutomationActionOutcome;
use App\Services\Board\BoardAutomationActionRunner;
use App\Services\Board\BoardFormulaResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The column actions of {@see BoardAutomationActionRunner} that read one column to write another,
 * or reach another board: copy a value, start or stop a timer, connect items across boards.
 */
trait RunsColumnActions
{
    /** Most items of the connected board compared when connecting, and linked in one go. */
    private const MAX_CONNECT_CANDIDATES = 5000;

    private const MAX_CONNECTED_ITEMS = 50;

    /**
     * @param  array<string, mixed>  $params
     */
    private function copyColumnValue(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $source = $this->resolveColumnTarget($automation, $params, $item, null, 'source_column_id');
        if (is_string($source)) {
            return BoardAutomationActionOutcome::skipped($source);
        }
        [$source_column, $source_subject] = $source;

        [$read_as, $source_value] = $this->readSourceValue($source_column, $source_subject);
        if ($this->valuesAreEqual($source_value, null)) {
            return BoardAutomationActionOutcome::skipped("\"{$source_column->label}\" had no value to copy.");
        }

        $value = $this->convertValue($read_as, $source_value, $column);
        if ($value === null) {
            return BoardAutomationActionOutcome::skipped("A \"{$source_column->label}\" value cannot be copied into \"{$column->label}\".");
        }
        if ($this->valuesAreEqual($this->currentValue($subject, $column), $value)) {
            return BoardAutomationActionOutcome::skipped("\"{$column->label}\" already had that value.");
        }

        $this->writeValue($subject, $column, $value, $actor);
        $shown = $this->renderer->displayValue($column, $value);
        $this->log($automation, $subject, $actor, "copied \"{$source_column->label}\" into \"{$column->label}\" on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Copied \"{$shown}\" into \"{$column->label}\".");
    }

    /**
     * The value to copy from `$column` and the column type to read it as. A stored value reads as
     * itself. A Formula result is computed ({@see BoardFormulaResolver}) and read as a number, a
     * date, a checkbox or text. A Mirror reads as the mirrored column when one item is linked, and
     * as text listing every value when several are.
     *
     * @return array{0: BoardColumn, 1: mixed}
     */
    private function readSourceValue(BoardColumn $column, BoardItem $subject): array
    {
        if ($column->type === BoardColumn::TYPE_FORMULA) {
            $outcome = $this->formula_resolver->outcome($column, $subject);
            $result = $outcome !== null && $outcome['ok'] ? $outcome['value'] : null;
            [$type, $value] = match (true) {
                is_int($result) || is_float($result) => [BoardColumn::TYPE_NUMBER, floor((float) $result) === (float) $result ? (int) $result : round((float) $result, 6)],
                is_bool($result) => [BoardColumn::TYPE_CHECKBOX, $result],
                $result instanceof CarbonImmutable => [BoardColumn::TYPE_DATE, $outcome['text']],
                default => [BoardColumn::TYPE_TEXT, $outcome !== null && $outcome['ok'] && $outcome['text'] !== '' ? $outcome['text'] : null],
            };

            return [new BoardColumn(['type' => $type, 'label' => $column->label, 'config' => []]), $value];
        }

        if ($column->type === BoardColumn::TYPE_MIRROR) {
            $copy = (clone $subject)->setRelations([]);
            $this->mirror_resolver->attach(collect([$copy]), BoardColumn::where('board_view_id', $column->board_view_id)->where('scope', $column->scope)->get());
            $mirrored = ($copy->getAttribute('mirror_values') ?? [])[(string) $column->id] ?? null;
            $mirrored_column = BoardColumn::find((int) ($column->config['mirrored_column_id'] ?? 0));
            $source_column = BoardColumn::find((int) ($column->config['source_column_id'] ?? 0));
            $linked_count = $source_column && $mirrored_column
                ? BoardItemValue::whereIn('item_id', array_filter((array) $this->currentValue($subject, $source_column), 'is_numeric'))->where('column_id', $mirrored_column->id)->whereNotNull('value')->count()
                : 0;

            if ($mirrored === null || ! $mirrored_column) {
                return [$column, null];
            }
            if ($linked_count <= 1) {
                return [$mirrored_column, $mirrored];
            }

            $text = implode(', ', array_filter(array_map(fn ($entry) => $this->renderer->displayValue($mirrored_column, $entry), (array) $mirrored)));

            return [new BoardColumn(['type' => BoardColumn::TYPE_TEXT, 'label' => $column->label, 'config' => []]), $text === '' ? null : $text];
        }

        return [$column, $this->currentValue($subject, $column)];
    }

    /**
     * `$value` of `$from` in the shape `$to` stores, or null when the two do not fit together.
     * The same type copies as is, a text target takes the display text, and the close relatives
     * (numbers, dates and timelines, option columns by label, people and votes) convert.
     */
    private function convertValue(BoardColumn $from, mixed $value, BoardColumn $to): mixed
    {
        $text_types = [BoardColumn::TYPE_TEXT, BoardColumn::TYPE_LONG_TEXT, BoardColumn::TYPE_EMAIL, BoardColumn::TYPE_PHONE];
        $number_types = [BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_RATING, BoardColumn::TYPE_PROGRESS];
        $option_types = [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN];

        if ($from->type === $to->type && ! in_array($to->type, $option_types, true)) {
            return $value;
        }

        if (in_array($to->type, $text_types, true)) {
            $text = trim($this->renderer->displayValue($from, $value));

            return $text === '' ? null : $text;
        }

        if (in_array($to->type, $number_types, true)) {
            $number = is_numeric($value) ? (float) $value : (is_numeric($text = $this->renderer->displayValue($from, $value)) ? (float) $text : null);
            if ($number === null) {
                return null;
            }
            $number = match ($to->type) {
                BoardColumn::TYPE_RATING => max(0, min(5, (int) round($number))),
                BoardColumn::TYPE_PROGRESS => max(0, min(100, $number)),
                default => $number,
            };

            return floor((float) $number) === (float) $number ? (int) $number : $number;
        }

        if ($to->type === BoardColumn::TYPE_DATE) {
            return $this->dateEnd($value);
        }

        if ($to->type === BoardColumn::TYPE_TIMELINE) {
            $date = $this->dateString($value);

            return $date !== null ? ['start' => substr($date, 0, 10), 'end' => substr($date, 0, 10)] : $this->timelineRange($value);
        }

        if (in_array($to->type, $option_types, true)) {
            $labels = in_array($from->type, $option_types, true)
                ? array_map('trim', explode(',', $this->renderer->displayValue($from, $value)))
                : [trim($this->renderer->displayValue($from, $value))];
            $ids = collect($to->config['options'] ?? [])
                ->filter(fn (array $option) => in_array(mb_strtolower((string) ($option['label'] ?? '')), array_map('mb_strtolower', $labels), true))
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();

            if ($ids === []) {
                return null;
            }

            return $to->type === BoardColumn::TYPE_DROPDOWN ? $ids : $ids[0];
        }

        $people_types = [BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_VOTE];
        if (in_array($to->type, $people_types, true) && in_array($from->type, $people_types, true)) {
            return $this->peopleIds($value) ?: null;
        }

        return null;
    }

    /**
     * Starts or stops a time tracking timer. Stopping adds the running time to the total.
     *
     * @param  array<string, mixed>  $params
     */
    private function toggleTimeTracking(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, BoardColumn::TYPE_TIME_TRACKING);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $current = $this->currentValue($subject, $column);
        $seconds = is_array($current) ? max(0, (int) ($current['seconds'] ?? 0)) : 0;
        $running_since = is_array($current) && ! empty($current['running_since']) ? (string) $current['running_since'] : null;
        $is_start = ($params['mode'] ?? 'start') === 'start';

        if ($is_start && $running_since !== null) {
            return BoardAutomationActionOutcome::skipped("The \"{$column->label}\" timer was already running.");
        }
        if (! $is_start && $running_since === null) {
            return BoardAutomationActionOutcome::skipped("The \"{$column->label}\" timer was not running.");
        }

        $next = $is_start
            ? ['seconds' => $seconds, 'running_since' => Carbon::now()->toIso8601String()]
            : ['seconds' => $seconds + max(0, (int) Carbon::parse($running_since)->diffInSeconds(Carbon::now())), 'running_since' => null];

        $this->writeValue($subject, $column, $next, $actor);
        $verb = $is_start ? 'started' : 'stopped';
        $this->log($automation, $subject, $actor, "{$verb} the \"{$column->label}\" timer on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success(ucfirst($verb)." the \"{$column->label}\" timer.");
    }

    /**
     * Links the item, in a connect boards column, to every item of the connected board whose
     * `linked_match_column_id` (or name) reads the same as this item's `match_column_id` (or name),
     * compared without case.
     *
     * @param  array<string, mixed>  $params
     */
    private function connectItems(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, BoardColumn::TYPE_CONNECT_BOARD);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $linked_board_id = (int) ($column->config['linked_board_id'] ?? 0);
        if ($linked_board_id === 0) {
            return BoardAutomationActionOutcome::skipped("\"{$column->label}\" is not connected to a board yet.");
        }

        $match_column_id = (string) ($params['match_column_id'] ?? 'name');
        if ($match_column_id === 'name') {
            $needle = $subject->name;
        } else {
            $match = $this->resolveColumnTarget($automation, ['match_column_id' => $match_column_id], $item, null, 'match_column_id');
            if (is_string($match)) {
                return BoardAutomationActionOutcome::skipped($match);
            }
            [$match_column, $match_subject] = $match;
            $needle = $this->renderer->displayValue($match_column, $this->currentValue($match_subject, $match_column));
        }
        $needle = mb_strtolower(trim((string) $needle));
        if ($needle === '') {
            return BoardAutomationActionOutcome::skipped('The item has no value to match with.');
        }

        $candidates = BoardItem::where('board_id', $linked_board_id)->whereNull('parent_id')->where('is_archived', false)
            ->limit(self::MAX_CONNECT_CANDIDATES)->get(['id', 'name']);
        $linked_match_column_id = (string) ($params['linked_match_column_id'] ?? 'name');

        if ($linked_match_column_id === 'name') {
            $matched_ids = $candidates->filter(fn (BoardItem $candidate) => mb_strtolower(trim((string) $candidate->name)) === $needle)->pluck('id');
        } else {
            $linked_column = BoardColumn::where('board_id', $linked_board_id)->find((int) $linked_match_column_id);
            if (! $linked_column) {
                return BoardAutomationActionOutcome::skipped('The column to match on the connected board no longer exists.');
            }
            $matched_ids = BoardItemValue::where('column_id', $linked_column->id)->whereIn('item_id', $candidates->pluck('id'))->get()
                ->filter(fn (BoardItemValue $value) => mb_strtolower(trim($this->renderer->displayValue($linked_column, $value->value))) === $needle)
                ->pluck('item_id');
        }

        $matched_ids = $matched_ids->reject(fn ($id) => (int) $id === $subject->id)->map(fn ($id) => (string) $id)->take(self::MAX_CONNECTED_ITEMS)->values()->all();
        if ($matched_ids === []) {
            return BoardAutomationActionOutcome::skipped('No item on the connected board matched.');
        }

        $current_ids = array_map('strval', array_filter((array) $this->currentValue($subject, $column), 'is_scalar'));
        $next_ids = ! empty($params['replace']) ? $matched_ids : array_values(array_unique([...$current_ids, ...$matched_ids]));
        if ($this->valuesAreEqual($current_ids, $next_ids)) {
            return BoardAutomationActionOutcome::skipped('The item was already connected to the matching items.');
        }

        $this->writeValue($subject, $column, $next_ids, $actor);
        $count = count($matched_ids);
        $this->log($automation, $subject, $actor, "connected \"{$subject->name}\" to {$count} item(s) in \"{$column->label}\"");

        return BoardAutomationActionOutcome::success("Connected the item to {$count} item(s) in \"{$column->label}\".");
    }
}
