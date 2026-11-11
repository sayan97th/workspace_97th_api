<?php

namespace App\Services\Board\Export;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Services\Board\BoardFormulaResolver;
use App\Services\Board\BoardItemFilterEvaluator;
use App\Support\CountryCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Turns one board cell into an {@see ExportCell} that reads in Excel the way
 * the Table view shows it, while keeping real Excel types wherever the
 * column has one, so the file still sorts, filters and sums correctly:
 *
 * - Date: real dates in the column's own date format, Week: "Week 41, Oct 5 - Oct 11, 2026".
 * - Number, Rating, Vote, Item ID, Item number: numbers.
 * - Progress, Progress tracking: percentages.
 * - Time tracking: a duration (`[h]:mm:ss`), Hour: a time of day.
 * - Status, Label, color picker: a pill filled with the label's color.
 * - Link, Email, Location, Files: clickable hyperlinks.
 * - People, Tags, Dropdown, Connect boards, Dependency: names, comma separated.
 * - Formula and Mirror: their computed result, never the raw expression or ids.
 *
 * Button columns hold no data and are left out by {@see isExportable()}.
 */
class BoardExportCellFormatter
{
    /** The Table view's own default when a column has no `date_display.format`. */
    private const DATE_FORMATS = [
        'auto' => ['excel' => 'mmm d, yyyy', 'php' => 'M j, Y'],
        'month_day_year' => ['excel' => 'mmm d, yyyy', 'php' => 'M j, Y'],
        'day_month_year' => ['excel' => 'd mmm yyyy', 'php' => 'j M Y'],
        'weekday_month_day' => ['excel' => 'ddd, mmm d, yyyy', 'php' => 'D, M j, Y'],
        'month_day' => ['excel' => 'mmm d', 'php' => 'M j'],
        'us_numeric' => ['excel' => 'mm/dd/yyyy', 'php' => 'm/d/Y'],
        'eu_numeric' => ['excel' => 'dd/mm/yyyy', 'php' => 'd/m/Y'],
        'iso' => ['excel' => 'yyyy-mm-dd', 'php' => 'Y-m-d'],
    ];

    /** Column types whose stored value is itself a list. */
    private const LIST_VALUED_TYPES = [
        BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN, BoardColumn::TYPE_FILES,
        BoardColumn::TYPE_CHECKLIST, BoardColumn::TYPE_CONNECT_BOARD, BoardColumn::TYPE_DEPENDENCY, BoardColumn::TYPE_VOTE,
    ];

    /** A Status label counts as done when it reads "Done", like the frontend's `isDoneStatusLabel()`. */
    private const DONE_LABEL_PATTERN = '/^done$/i';

    private readonly string $time_zone;

    private ?int $values_item_key = null;

    private ?BoardItem $values_item = null;

    /** @var array<int, mixed> column id => stored value, of {@see} */
    private array $values_by_column = [];

    /** @var array<int, array{by_id: array<string, array{label: string, color: string|null}>, by_label: array<string, array{label: string, color: string|null}>}> column id => its options */
    private array $options_cache = [];

    /** @var array<int, Collection<string, BoardColumn>> scope collection id => its Status columns by id */
    private array $status_columns_cache = [];

    public function __construct(
        private readonly BoardExportLookups $lookups,
        private readonly BoardFormulaResolver $formula_resolver,
        string $time_zone = 'UTC',
    ) {
        $this->time_zone = self::isValidTimeZone($time_zone) ? $time_zone : 'UTC';
    }

    public static function isValidTimeZone(string $time_zone): bool
    {
        return in_array($time_zone, timezone_identifiers_list(), true);
    }

    /** Whether a column carries anything worth a spreadsheet column. */
    public static function isExportable(BoardColumn $column): bool
    {
        return $column->type !== BoardColumn::TYPE_BUTTON;
    }

    /** A sensible starting width (in Excel character units) for a column of this type. */
    public static function columnWidth(BoardColumn $column): float
    {
        return match ($column->type) {
            BoardColumn::TYPE_LONG_TEXT, BoardColumn::TYPE_CHECKLIST => 48,
            BoardColumn::TYPE_FILES, BoardColumn::TYPE_LINK, BoardColumn::TYPE_LOCATION, BoardColumn::TYPE_CONNECT_BOARD,
            BoardColumn::TYPE_MIRROR, BoardColumn::TYPE_DEPENDENCY, BoardColumn::TYPE_CREATION_LOG, BoardColumn::TYPE_LAST_UPDATED => 32,
            BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN, BoardColumn::TYPE_EMAIL,
            BoardColumn::TYPE_TIMELINE, BoardColumn::TYPE_TEXT, BoardColumn::TYPE_FORMULA, BoardColumn::TYPE_DOC => 26,
            BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DATE, BoardColumn::TYPE_PHONE,
            BoardColumn::TYPE_WEEK, BoardColumn::TYPE_COUNTRY, BoardColumn::TYPE_WORLD_CLOCK, BoardColumn::TYPE_AUTO_NUMBER => 18,
            default => 14,
        };
    }

    /**
     * @param  int  $item_number  the item's 1 based position in its group, for Item number columns
     * @param  Collection<int, BoardColumn>  $scope_columns  every column of the item's table, for Progress tracking
     */
    public function format(BoardColumn $column, BoardItem $item, int $item_number, Collection $scope_columns): ExportCell
    {
        try {
            return $this->formatCell($column, $item, $item_number, $scope_columns);
        } catch (Throwable) {
            // One malformed value (an import gone wrong, a column whose type was changed) must never
            // fail a 50,000 row export, the cell is just left empty.
            return ExportCell::empty();
        }
    }

    /**
     * @param  Collection<int, BoardColumn>  $scope_columns
     */
    private function formatCell(BoardColumn $column, BoardItem $item, int $item_number, Collection $scope_columns): ExportCell
    {
        // Columns computed from the item rather than from a stored value.
        switch ($column->type) {
            case BoardColumn::TYPE_ITEM_NUMBER:
                return ExportCell::number($item_number, '0');
            case BoardColumn::TYPE_AUTO_NUMBER:
                return ExportCell::number((int) $item->id, '0');
            case BoardColumn::TYPE_CREATION_LOG:
                return ExportCell::text($this->personAndMoment($this->lookups->personName($item->created_by_id), $item->created_at, $column));
            case BoardColumn::TYPE_LAST_UPDATED:
                $user_id = $item->relationLoaded('latestActivity') && $item->latestActivity?->user_id ? $item->latestActivity->user_id : $item->created_by_id;

                return ExportCell::text($this->personAndMoment($this->lookups->personName($user_id), BoardItemFilterEvaluator::lastUpdatedAt($item), $column));
            case BoardColumn::TYPE_FORMULA:
                return $this->formula($column, $item);
            case BoardColumn::TYPE_MIRROR:
                return ExportCell::text($this->mirrorText($column, $item));
            case BoardColumn::TYPE_PROGRESS_TRACKING:
                return $this->progressTracking($column, $item, $scope_columns);
        }

        return $this->formatValue($column, $this->storedValue($item, $column));
    }

    /**
     * A stored value as a cell. Also used for the values a Mirror column reads off another board.
     */
    private function formatValue(BoardColumn $column, mixed $value): ExportCell
    {
        if ($column->type === BoardColumn::TYPE_CHECKBOX) {
            return ExportCell::text(self::isTruthy($value) ? 'Yes' : 'No');
        }

        if ($value === null || $value === '' || $value === []) {
            return ExportCell::empty();
        }

        return match ($column->type) {
            BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL => $this->optionPill($column, is_array($value) ? ($value[0] ?? null) : $value),
            BoardColumn::TYPE_DROPDOWN => ExportCell::text($this->optionLabels($column, (array) $value)),
            BoardColumn::TYPE_TAGS => ExportCell::text($this->tagLabels((array) $value)),
            BoardColumn::TYPE_PEOPLE => ExportCell::text($this->peopleNames((array) $value)),
            BoardColumn::TYPE_VOTE => ExportCell::number(count(array_filter((array) $value, fn ($id) => $id !== null && $id !== '')), '0'),
            BoardColumn::TYPE_DATE => $this->date($column, $value),
            BoardColumn::TYPE_TIMELINE => ExportCell::text($this->timelineText($value)),
            BoardColumn::TYPE_WEEK => $this->week($value),
            BoardColumn::TYPE_HOUR => $this->hour($column, $value),
            BoardColumn::TYPE_NUMBER => is_numeric($value) ? ExportCell::number($value + 0) : ExportCell::text(self::scalarText($value)),
            BoardColumn::TYPE_PROGRESS => is_numeric($value) ? ExportCell::number(max(0, min(100, (float) $value)) / 100, '0%') : ExportCell::empty(),
            BoardColumn::TYPE_RATING => $this->rating($column, $value),
            BoardColumn::TYPE_TIME_TRACKING => $this->timeTracking($value),
            BoardColumn::TYPE_LINK => $this->link($value),
            BoardColumn::TYPE_EMAIL => ExportCell::text(self::scalarText($value), filter_var($value, FILTER_VALIDATE_EMAIL) ? 'mailto:'.$value : null),
            BoardColumn::TYPE_PHONE => ExportCell::text(self::scalarText($value)),
            BoardColumn::TYPE_LOCATION => ExportCell::text(self::scalarText($value), 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(self::scalarText($value))),
            BoardColumn::TYPE_FILES => $this->files($value),
            BoardColumn::TYPE_CHECKLIST => ExportCell::text($this->checklistText($value)),
            BoardColumn::TYPE_CONNECT_BOARD, BoardColumn::TYPE_DEPENDENCY => ExportCell::text($this->itemNames((array) $value)),
            BoardColumn::TYPE_DOC => ExportCell::text(is_array($value) ? trim((string) ($value['title'] ?? '')) ?: 'Untitled doc' : self::scalarText($value)),
            BoardColumn::TYPE_WORLD_CLOCK => ExportCell::text(str_replace('_', ' ', self::scalarText($value))),
            BoardColumn::TYPE_COUNTRY => ExportCell::text(CountryCatalog::NAMES[strtoupper(self::scalarText($value))] ?? self::scalarText($value)),
            BoardColumn::TYPE_COLOR_PICKER => ExportCell::pill(self::scalarText($value), self::scalarText($value)),
            default => ExportCell::text($this->genericText($value)),
        };
    }

    /**
     * The item's stored value of a column. The item's values are indexed by column once, then
     * reused for every cell of its row: a `firstWhere()` per cell made a 64 column board five
     * times slower to export.
     */
    private function storedValue(BoardItem $item, BoardColumn $column): mixed
    {
        $item_key = spl_object_id($item);
        if ($this->values_item_key !== $item_key || $this->values_item !== $item) {
            $this->values_item_key = $item_key;
            $this->values_item = $item;
            $this->values_by_column = [];
            foreach ($item->values as $value) {
                $this->values_by_column[(int) $value->column_id] = $value->value;
            }
        }

        return $this->values_by_column[(int) $column->id] ?? null;
    }

    // ── Options, tags and people ────────────────────────────────────────

    /**
     * @return array{label: string, color: string|null}|null
     */
    private function option(BoardColumn $column, mixed $option_id): ?array
    {
        if (! is_scalar($option_id) || $option_id === '') {
            return null;
        }

        $index = $this->options_cache[(int) $column->id] ??= $this->indexOptions($column);

        // Older cells kept the label itself rather than the option id.
        return $index['by_id'][(string) $option_id] ?? $index['by_label'][(string) $option_id] ?? null;
    }

    /**
     * @return array{by_id: array<string, array{label: string, color: string|null}>, by_label: array<string, array{label: string, color: string|null}>}
     */
    private function indexOptions(BoardColumn $column): array
    {
        $index = ['by_id' => [], 'by_label' => []];
        foreach ((array) ($column->config['options'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }
            $entry = ['label' => (string) ($option['label'] ?? ''), 'color' => $option['color'] ?? null];
            $index['by_id'][(string) ($option['id'] ?? '')] ??= $entry;
            $index['by_label'][$entry['label']] ??= $entry;
        }

        return $index;
    }

    private function optionPill(BoardColumn $column, mixed $option_id): ExportCell
    {
        $option = $this->option($column, $option_id);
        if ($option === null) {
            return is_scalar($option_id) ? ExportCell::text((string) $option_id) : ExportCell::empty();
        }

        return $option['label'] === '' ? ExportCell::empty() : ExportCell::pill($option['label'], $option['color']);
    }

    /**
     * @param  array<int, mixed>  $option_ids
     */
    private function optionLabels(BoardColumn $column, array $option_ids): string
    {
        return collect($option_ids)
            ->map(fn ($option_id) => $this->option($column, $option_id)['label'] ?? (is_scalar($option_id) ? (string) $option_id : ''))
            ->filter(fn (string $label) => $label !== '')
            ->implode(', ');
    }

    /**
     * @param  array<int, mixed>  $tag_ids
     */
    private function tagLabels(array $tag_ids): string
    {
        return collect($tag_ids)
            ->map(function ($tag_id) {
                $label = $this->lookups->tag($tag_id)['label'] ?? (is_scalar($tag_id) ? (string) $tag_id : '');
                $label = ltrim(trim($label), '#');

                return $label !== '' ? '#'.$label : '';
            })
            ->filter(fn (string $label) => $label !== '')
            ->implode(', ');
    }

    /**
     * @param  array<int, mixed>  $user_ids
     */
    private function peopleNames(array $user_ids): string
    {
        return collect($user_ids)
            ->map(fn ($user_id) => $this->lookups->personName($user_id) ?: '')
            ->filter(fn (string $name) => $name !== '')
            ->implode(', ');
    }

    /**
     * @param  array<int, mixed>  $item_ids
     */
    private function itemNames(array $item_ids): string
    {
        return collect($item_ids)
            ->map(fn ($item_id) => $this->lookups->itemName($item_id) ?? '')
            ->filter(fn (string $name) => $name !== '')
            ->implode(', ');
    }

    // ── Dates and times ─────────────────────────────────────────────────

    /**
     * @return array{excel: string, php: string}
     */
    private function dateFormat(BoardColumn $column, bool $with_time): array
    {
        $display = (array) ($column->config['date_display'] ?? []);
        $format = self::DATE_FORMATS[(string) ($display['format'] ?? 'auto')] ?? self::DATE_FORMATS['auto'];

        if ($with_time) {
            $is_24_hour = (string) ($display['time_format'] ?? '12') === '24';
            $format['excel'] .= $is_24_hour ? ' hh:mm' : ' h:mm AM/PM';
            $format['php'] .= $is_24_hour ? ' H:i' : ' g:i A';
        }

        return $format;
    }

    private function date(BoardColumn $column, mixed $value): ExportCell
    {
        $text = self::scalarText($value);
        $has_time = (bool) preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $text);
        $date = self::parseDate($text);

        return $date === null
            ? ExportCell::text($text)
            : ExportCell::date($date, $this->dateFormat($column, $has_time)['excel']);
    }

    /** "Sep 1, 2026 - Nov 21, 2026", from `start..end` or a `{start, end}` / `{from, to}` value. */
    private function timelineText(mixed $value): string
    {
        if (is_string($value) && str_contains($value, '..')) {
            [$start, $end] = array_pad(explode('..', $value, 2), 2, '');
        } elseif (is_array($value)) {
            $start = (string) ($value['start'] ?? $value['from'] ?? '');
            $end = (string) ($value['end'] ?? $value['to'] ?? '');
        } else {
            return self::scalarText($value);
        }

        $labels = array_filter([self::parseDate($start)?->format('M j, Y'), self::parseDate($end)?->format('M j, Y')]);

        return implode(' - ', array_unique($labels));
    }

    /** "Week 41, Oct 5 - Oct 11, 2026", from the stored Monday. */
    private function week(mixed $value): ExportCell
    {
        $monday = self::parseDate(self::scalarText($value));
        if ($monday === null) {
            return ExportCell::text(self::scalarText($value));
        }

        $sunday = $monday->modify('+6 days');

        return ExportCell::text(sprintf('Week %d, %s - %s', (int) $monday->format('W'), $monday->format('M j'), $sunday->format('M j, Y')));
    }

    /** A time of day as an Excel time (a fraction of a day), so it sorts and adds up. */
    private function hour(BoardColumn $column, mixed $value): ExportCell
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})/', self::scalarText($value), $match)) {
            return ExportCell::text(self::scalarText($value));
        }

        $fraction = ((int) $match[1] * 60 + (int) $match[2]) / 1440;

        return ExportCell::number($fraction, ($column->config['hour_format'] ?? '12') === '24' ? 'hh:mm' : 'h:mm AM/PM');
    }

    /** Tracked time as an Excel duration, including the running stretch of a timer that is still on. */
    private function timeTracking(mixed $value): ExportCell
    {
        if (! is_array($value)) {
            return is_numeric($value) ? ExportCell::number((float) $value / 86400, '[h]:mm:ss') : ExportCell::empty();
        }

        $seconds = (int) ($value['seconds'] ?? 0);
        if (! empty($value['running_since'])) {
            try {
                $seconds += max(0, time() - CarbonImmutable::parse((string) $value['running_since'])->getTimestamp());
            } catch (Throwable) {
                // An unreadable start time only drops the running stretch.
            }
        }

        return ExportCell::number($seconds / 86400, '[h]:mm:ss');
    }

    private function personAndMoment(?string $name, ?CarbonInterface $moment, BoardColumn $column): string
    {
        $when = $moment?->copy()->setTimezone($this->time_zone)->format($this->dateFormat($column, true)['php']);

        return implode(', ', array_filter([$name ?: null, $when]));
    }

    // ── Numbers ─────────────────────────────────────────────────────────

    private function rating(BoardColumn $column, mixed $value): ExportCell
    {
        if (! is_numeric($value)) {
            return ExportCell::empty();
        }

        $max = (int) ($column->config['rating_max'] ?? BoardColumn::RATING_DEFAULT_MAX);

        return ExportCell::number((int) $value, '0" / '.max(1, $max).'"');
    }

    /**
     * The share of the item's tracked Status columns that read "Done", weighted like the
     * frontend's `rowProgressOf()`.
     *
     * @param  Collection<int, BoardColumn>  $scope_columns
     */
    private function progressTracking(BoardColumn $column, BoardItem $item, Collection $scope_columns): ExportCell
    {
        $status_columns = $this->status_columns_cache[spl_object_id($scope_columns)]
            ??= $scope_columns->where('type', BoardColumn::TYPE_STATUS)->keyBy(fn (BoardColumn $status) => (string) $status->id);
        if ($status_columns->isEmpty()) {
            return ExportCell::empty();
        }

        $tracked = collect((array) ($column->config['progress_columns'] ?? []))
            ->filter(fn ($entry) => is_array($entry) && $status_columns->has((string) ($entry['column_id'] ?? '')))
            ->map(fn (array $entry) => ['column' => $status_columns->get((string) $entry['column_id']), 'weight' => max(0, (float) ($entry['weight'] ?? 0))]);

        if ($tracked->isEmpty()) {
            $tracked = $status_columns->values()->map(fn (BoardColumn $status) => ['column' => $status, 'weight' => 1.0]);
        }

        $total_weight = $tracked->sum('weight');
        $done_weight = $tracked->sum(function (array $entry) use ($item) {
            $label = $this->option($entry['column'], $this->storedValue($item, $entry['column']))['label'] ?? '';

            return preg_match(self::DONE_LABEL_PATTERN, trim($label)) ? $entry['weight'] : 0;
        });

        $share = $total_weight > 0 ? $done_weight / $total_weight : 0;

        return ExportCell::number(round($share, 2), '0%');
    }

    private function formula(BoardColumn $column, BoardItem $item): ExportCell
    {
        $outcome = $this->formula_resolver->outcome($column, $item);
        if ($outcome === null || ! $outcome['ok']) {
            return ExportCell::empty();
        }

        $value = $outcome['value'];
        if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
            return ExportCell::number($value);
        }

        return ExportCell::text((string) $outcome['text']);
    }

    // ── Links, files and structured values ──────────────────────────────

    private function link(mixed $value): ExportCell
    {
        if (! is_array($value)) {
            $url = self::scalarText($value);

            return ExportCell::text($url, self::isWebUrl($url) ? $url : null);
        }

        $url = trim((string) ($value['url'] ?? ''));
        $text = trim((string) ($value['text'] ?? '')) ?: $url;

        return ExportCell::text($text, self::isWebUrl($url) ? $url : null);
    }

    /** One file per line. A single file links straight to itself. */
    private function files(mixed $value): ExportCell
    {
        $files = collect(is_array($value) ? (array_is_list($value) ? $value : [$value]) : [])
            ->filter(fn ($file) => is_array($file))
            ->map(fn (array $file) => [
                'name' => trim((string) ($file['file_name'] ?? $file['name'] ?? $file['url'] ?? '')),
                'url' => trim((string) ($file['url'] ?? '')),
            ])
            ->filter(fn (array $file) => $file['name'] !== '' || $file['url'] !== '')
            ->values();

        if ($files->isEmpty()) {
            return ExportCell::empty();
        }

        if ($files->count() === 1) {
            $file = $files->first();

            return ExportCell::text($file['name'] ?: $file['url'], self::isWebUrl($file['url']) ? $file['url'] : null);
        }

        return ExportCell::text($files->map(fn (array $file) => $file['url'] !== '' && $file['url'] !== $file['name'] ? "{$file['name']} ({$file['url']})" : $file['name'])->implode("\n"));
    }

    /** "☑ Send contract" / "☐ Kickoff call", one sub task per line. */
    private function checklistText(mixed $value): string
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn ($entry) => is_array($entry) && trim((string) ($entry['text'] ?? '')) !== '')
            ->map(fn (array $entry) => (! empty($entry['is_done']) ? '☑ ' : '☐ ').trim((string) $entry['text']))
            ->implode("\n");
    }

    private function mirrorText(BoardColumn $column, BoardItem $item): string
    {
        $mirror_values = (array) ($item->getAttribute('mirror_values') ?? []);
        $raw = $mirror_values[(string) $column->id] ?? null;
        if ($raw === null || $raw === []) {
            return '';
        }

        $mirrored_column = $this->lookups->mirroredColumn($column);

        // One linked item mirrors its value as is, several mirror a list of values (see
        // MirrorColumnResolver). For columns whose own value is already a list, only a list
        // of lists means several linked items.
        $is_list_valued = $mirrored_column !== null && in_array($mirrored_column->type, self::LIST_VALUED_TYPES, true);
        $is_several = is_array($raw) && array_is_list($raw)
            && (! $is_list_valued || (is_array($raw[0] ?? null) && array_is_list($raw[0])));
        $values = $is_several ? $raw : [$raw];

        return collect($values)
            ->map(fn ($entry) => $mirrored_column !== null ? $this->cellText($this->formatValue($mirrored_column, $entry), $mirrored_column) : $this->genericText($entry))
            ->filter(fn (string $text) => $text !== '')
            ->implode(', ');
    }

    /** A cell's value as plain text, for values shown inside another cell (a Mirror). */
    private function cellText(ExportCell $cell, BoardColumn $column): string
    {
        $value = $cell->value;

        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format($this->dateFormat($column, false)['php']),
            is_bool($value) => $value ? 'Yes' : 'No',
            $column->type === BoardColumn::TYPE_PROGRESS && is_numeric($value) => round($value * 100).'%',
            default => (string) $value,
        };
    }

    /** Text for a value whose column type has no dedicated rendering. */
    private function genericText(mixed $value): string
    {
        if (! is_array($value)) {
            return self::scalarText($value);
        }

        if (array_is_list($value)) {
            return collect($value)->map(fn ($entry) => $this->genericText($entry))->filter(fn (string $text) => $text !== '')->implode(', ');
        }

        foreach (['text', 'label', 'name', 'title', 'url', 'value', 'date'] as $key) {
            if (isset($value[$key]) && is_scalar($value[$key]) && $value[$key] !== '') {
                return (string) $value[$key];
            }
        }

        return '';
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private static function scalarText(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function isTruthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    private static function isWebUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url) && mb_strlen($url) <= 2000;
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        try {
            // Dates are stored as wall clock values, not instants: keep them exactly as typed.
            $parsed = CarbonImmutable::parse(str_replace('T', ' ', substr($value, 0, 16)), 'UTC');

            return new DateTimeImmutable($parsed->format('Y-m-d H:i:s'));
        } catch (Throwable) {
            return null;
        }
    }
}
