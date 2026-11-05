<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Writes dates into Date columns on the server, so they read like a regular,
 * editable Date column while still filling themselves:
 *
 * - A Date column with `config.auto_fill.enabled` gets the item's creation
 *   date the moment a new item lands on its tab (see {@see fillItem()}).
 * - "Fill empty cells" and a Creation log / Last updated column changed back
 *   into a Date column write each item's creation (or last update) date into
 *   the cells that are still empty (see {@see fillEmptyCells()}).
 *
 * A cell that already holds a date is never overwritten, so a value a person
 * typed, or one a client sent along with the new item, always wins.
 *
 * Dates are written in the creator's own time zone (the app's when unknown),
 * as `Y-m-d`, or `Y-m-d\TH:i` when the column's auto fill includes the time,
 * the same two shapes the Date cell's picker stores.
 */
class BoardDateAutoFiller
{
    /** Where {@see fillEmptyCells()} reads each item's date from. */
    public const SOURCE_CREATED = 'created';

    public const SOURCE_LAST_UPDATED = 'last_updated';

    /** @var array<int, string> user id => time zone, cached for one request */
    private array $timezones_by_user_id = [];

    /**
     * Fills every auto fill Date column of the tab and scope the item lives
     * in with its creation date, leaving cells that already hold a value.
     */
    public function fillItem(BoardItem $board_item, int $board_view_id, string $scope): void
    {
        $columns = $this->autoFillColumns($board_view_id, $scope);
        if ($columns->isEmpty()) {
            return;
        }

        $filled_column_ids = BoardItemValue::where('item_id', $board_item->id)
            ->whereIn('column_id', $columns->pluck('id'))
            ->get(['column_id', 'value'])
            ->filter(fn (BoardItemValue $value) => ! self::isEmptyValue($value->value))
            ->pluck('column_id')
            ->all();

        foreach ($columns as $column) {
            if (in_array($column->id, $filled_column_ids, true)) {
                continue;
            }

            BoardItemValue::updateOrCreate(
                ['item_id' => $board_item->id, 'column_id' => $column->id],
                ['value' => $this->valueFor($board_item, $column)],
            );
        }
    }

    /**
     * The tab's Date columns of `$scope` that fill themselves on new items.
     *
     * @return Collection<int, BoardColumn>
     */
    public function autoFillColumns(int $board_view_id, string $scope): Collection
    {
        return BoardColumn::where('board_view_id', $board_view_id)
            ->where('scope', $scope)
            ->where('type', BoardColumn::TYPE_DATE)
            ->get(['id', 'type', 'config'])
            ->filter(fn (BoardColumn $column) => $column->dateAutoFill()['enabled'])
            ->values();
    }

    /**
     * What an auto fill Date column stores for a brand new item: its creation date.
     */
    public function valueFor(BoardItem $board_item, BoardColumn $column): string
    {
        return $this->formatDate(
            $board_item->created_at ?? now(),
            $this->timezoneOf($board_item->created_by_id),
            $column->dateAutoFill()['include_time'],
        );
    }

    /**
     * Writes each item's creation (or last update) date into the column's
     * empty cells, subitems included for a subitem column, and returns what
     * it wrote keyed by item id, so the caller can patch its rows in place.
     *
     * @return array<int, string>
     */
    public function fillEmptyCells(BoardColumn $column, string $source = self::SOURCE_CREATED): array
    {
        if ($column->type !== BoardColumn::TYPE_DATE) {
            return [];
        }

        $include_time = $column->dateAutoFill()['include_time'];
        $group_ids = BoardGroup::where('board_view_id', $column->board_view_id)->pluck('id');
        $filled = [];

        BoardItem::query()
            ->whereIn('group_id', $group_ids)
            ->when(
                $column->scope === BoardColumn::SCOPE_SUBITEM,
                fn ($query) => $query->whereNotNull('parent_id'),
                fn ($query) => $query->whereNull('parent_id'),
            )
            // Last updated reads every cell's own timestamp, see BoardItemFilterEvaluator::lastUpdatedAt().
            ->with($source === self::SOURCE_LAST_UPDATED ? ['values'] : ['values' => fn ($query) => $query->where('column_id', $column->id)])
            ->chunkById(500, function ($board_items) use ($column, $source, $include_time, &$filled) {
                foreach ($board_items as $board_item) {
                    $current = $board_item->values->firstWhere('column_id', $column->id);
                    if ($current !== null && ! self::isEmptyValue($current->value)) {
                        continue;
                    }

                    $moment = $source === self::SOURCE_LAST_UPDATED
                        ? BoardItemFilterEvaluator::lastUpdatedAt($board_item)
                        : $board_item->created_at;
                    if ($moment === null) {
                        continue;
                    }

                    $value = $this->formatDate($moment, $this->timezoneOf($board_item->created_by_id), $include_time);
                    BoardItemValue::updateOrCreate(
                        ['item_id' => $board_item->id, 'column_id' => $column->id],
                        ['value' => $value],
                    );
                    $filled[$board_item->id] = $value;
                }
            });

        return $filled;
    }

    /**
     * Whether a stored cell value counts as empty, the only cells this class ever writes to.
     */
    public static function isEmptyValue(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function formatDate(CarbonInterface $moment, string $timezone, bool $include_time): string
    {
        return $moment->copy()->setTimezone($timezone)->format($include_time ? 'Y-m-d\TH:i' : 'Y-m-d');
    }

    /**
     * The creator's own time zone, so an item made late in the evening still
     * gets that evening's date, falling back to the app's time zone.
     */
    private function timezoneOf(?int $user_id): string
    {
        $fallback = (string) config('app.timezone', 'UTC');
        if ($user_id === null) {
            return $fallback;
        }

        if (! array_key_exists($user_id, $this->timezones_by_user_id)) {
            $timezone = User::whereKey($user_id)->value('timezone');
            $this->timezones_by_user_id[$user_id] = is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
                ? $timezone
                : $fallback;
        }

        return $this->timezones_by_user_id[$user_id];
    }
}
