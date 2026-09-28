<?php

namespace App\Services\Board;

use App\Concerns\CombinesSplitImportColumns;
use App\Concerns\InfersBoardColumnTypes;
use App\Http\Controllers\Board\BoardItemCellFileController;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\BoardTag;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns one raw spreadsheet cell into the value shape its {@see BoardColumn}
 * type actually stores — the write-side counterpart of
 * {@see InfersBoardColumnTypes::inferColumnType()}, shared by the
 * `board:import-monday*` commands and the "Import items" wizard so both
 * write, say, a Tags cell as board-wide {@see BoardTag} ids and a Link cell as
 * `{url, text}`, exactly like an edit made in the table would.
 *
 * One instance per import run: it caches the board's tags and remembers
 * every sheet name that didn't match a user, for the caller's summary.
 * Status/Label/Dropdown labels a column doesn't have yet (possible when the
 * wizard maps a file onto an *existing* column) are appended to the column's
 * options rather than silently dropped.
 *
 * Dependency and Connect boards cells name other items, which may not exist
 * yet while rows are still being written (row 3 can depend on row 40). The
 * caller hands those cells to {@see deferLinkedItems()} once the item exists,
 * and {@see resolveLinkedItems()} matches every name to an item id after the
 * last row is in.
 */
class ImportedCellValueCaster
{
    use InfersBoardColumnTypes;

    /** @var array<string, string>|null lower-cased tag label => tag id, loaded on first use */
    private ?array $tag_ids_by_label = null;

    /** @var array<int, string> */
    private array $unmatched_people = [];

    /** @var array<int, array{item_id: int, column: BoardColumn, raw: string}> */
    private array $deferred_links = [];

    /** @var array<int, string> */
    private array $unmatched_links = [];

    /**
     * @param  Collection<int, User>  $users
     */
    public function __construct(
        private readonly WorkspaceNavigationItem $board,
        private readonly Collection $users,
    ) {}

    /**
     * The value `$raw` stores as under `$column`, or null when there's nothing
     * to store. Always null for a type {@see defersLinkedItems()} covers.
     */
    public function cast(BoardColumn $column, string $raw): mixed
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        return match ($column->type) {
            BoardColumn::TYPE_TEXT => Str::limit($raw, 2000, ''),
            BoardColumn::TYPE_LONG_TEXT => $raw,
            BoardColumn::TYPE_NUMBER => $this->castNumber($raw),
            BoardColumn::TYPE_PROGRESS => $this->parsePercent($raw),
            BoardColumn::TYPE_RATING => $this->castRating($raw),
            BoardColumn::TYPE_CHECKBOX => $this->isUncheckedToken($raw) ? null : true,
            BoardColumn::TYPE_DATE => $this->parseDate($raw) ?? $this->parseActivityLogDate($raw),
            BoardColumn::TYPE_TIMELINE => $this->castTimeline($raw),
            BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_VOTE => $this->castPeople($raw),
            BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL => $this->resolveOptionId($column, $raw),
            BoardColumn::TYPE_DROPDOWN => $this->nonEmpty(array_values(array_unique(array_map(
                fn (string $label) => $this->resolveOptionId($column, $label),
                $this->splitList($raw),
            )))),
            BoardColumn::TYPE_TAGS => $this->nonEmpty(array_values(array_unique(array_map(
                fn (string $label) => $this->resolveTagId($label),
                $this->splitList($raw),
            )))),
            BoardColumn::TYPE_EMAIL => $this->extractEmails($raw)[0] ?? $raw,
            BoardColumn::TYPE_PHONE => $raw,
            BoardColumn::TYPE_LINK => $this->castLink($raw),
            BoardColumn::TYPE_FILES => $this->castFiles($raw),
            BoardColumn::TYPE_TIME_TRACKING => $this->castTimeTracking($raw),
            BoardColumn::TYPE_CHECKLIST => $this->castChecklist($raw),
            // Dependency/Connect boards are resolved later (see `deferLinkedItems()`), and the
            // computed types (Formula, Mirror, Auto-number) never store an imported value.
            default => null,
        };
    }

    /** True for the column types whose cells name other items and are resolved after every row is written. */
    public function defersLinkedItems(BoardColumn $column): bool
    {
        return in_array($column->type, [BoardColumn::TYPE_DEPENDENCY, BoardColumn::TYPE_CONNECT_BOARD], true);
    }

    /** Queues a Dependency/Connect boards cell for {@see resolveLinkedItems()}. */
    public function deferLinkedItems(int $item_id, BoardColumn $column, string $raw): void
    {
        $raw = trim($raw);

        if ($raw !== '' && $this->defersLinkedItems($column)) {
            $this->deferred_links[] = ['item_id' => $item_id, 'column' => $column, 'raw' => $raw];
        }
    }

    /**
     * Matches every queued cell's item names (case-insensitive) against the
     * items it may link to, and stores the matched ids: any item or subitem on
     * the column's own tab for a Dependency (never the item itself), any item
     * on the linked board for Connect boards. Names that match nothing are
     * remembered for {@see unmatchedLinkedItems()}.
     *
     * @return int how many cells got at least one link
     */
    public function resolveLinkedItems(): int
    {
        $linked_cells = 0;
        /** @var array<int, array<string, array<int, string>>> $ids_by_name_by_column */
        $ids_by_name_by_column = [];

        foreach ($this->deferred_links as $entry) {
            $column = $entry['column'];
            $ids_by_name = $ids_by_name_by_column[$column->id] ??= $this->linkCandidatesFor($column);

            $ids = [];
            foreach ($this->linkedItemNames($entry['raw'], $ids_by_name) as $name) {
                $candidates = array_values(array_filter(
                    $ids_by_name[mb_strtolower($name)] ?? [],
                    fn (string $id) => $id !== (string) $entry['item_id'],
                ));

                if ($candidates === []) {
                    $this->unmatched_links[] = $name;

                    continue;
                }

                $ids[] = $candidates[0];
            }

            $ids = array_values(array_unique($ids));

            if ($ids === []) {
                continue;
            }

            BoardItemValue::updateOrCreate(
                ['item_id' => $entry['item_id'], 'column_id' => $column->id],
                ['value' => $ids],
            );
            $linked_cells++;
        }

        $this->deferred_links = [];

        return $linked_cells;
    }

    /**
     * Every item name a Dependency/Connect boards cell referenced that didn't match an item, deduplicated.
     *
     * @return array<int, string>
     */
    public function unmatchedLinkedItems(): array
    {
        return array_values(array_unique($this->unmatched_links));
    }

    /**
     * Lower-cased item name => ids of every item carrying that name, among the items `$column` may link to.
     *
     * @return array<string, array<int, string>>
     */
    private function linkCandidatesFor(BoardColumn $column): array
    {
        $query = BoardItem::query()->where('is_archived', false);

        if ($column->type === BoardColumn::TYPE_DEPENDENCY) {
            $query->whereIn('group_id', BoardGroup::where('board_view_id', $column->board_view_id)->select('id'));
        } else {
            $linked_board_id = data_get($column->config, 'linked_board_id');

            if (! is_numeric($linked_board_id)) {
                return [];
            }

            $query->where('board_id', (int) $linked_board_id)->whereNull('parent_id');
        }

        $ids_by_name = [];

        foreach ($query->orderBy('position')->get(['id', 'name']) as $item) {
            $ids_by_name[mb_strtolower(trim($item->name))][] = (string) $item->id;
        }

        return $ids_by_name;
    }

    /**
     * The item names one cell references: the whole cell when it's a single
     * name (item names may contain commas themselves), otherwise each
     * comma-separated token.
     *
     * @param  array<string, array<int, string>>  $ids_by_name
     * @return array<int, string>
     */
    private function linkedItemNames(string $raw, array $ids_by_name): array
    {
        return isset($ids_by_name[mb_strtolower($raw)]) ? [$raw] : $this->splitList($raw);
    }

    /**
     * Every name seen so far that didn't match an existing user, deduplicated.
     *
     * @return array<int, string>
     */
    public function unmatchedPeople(): array
    {
        return array_values(array_unique($this->unmatched_people));
    }

    private function castNumber(string $raw): int|float|null
    {
        $number = $this->parseNumber($raw);

        if ($number === null) {
            return null;
        }

        return floor($number) === $number && abs($number) < PHP_INT_MAX ? (int) $number : $number;
    }

    private function castRating(string $raw): ?int
    {
        $number = $this->parseNumber($raw);

        return $number === null ? null : (int) max(0, min(5, round($number)));
    }

    /**
     * Reads a "start - end" range (see {@see CombinesSplitImportColumns} for how a
     * split "- Start"/"- End" pair becomes one) or a single date, kept as a one-day range.
     *
     * @return array{start: string, end: string}|null
     */
    private function castTimeline(string $raw): ?array
    {
        $range = $this->parseDateRange($raw);

        if ($range === null) {
            return $this->singleDayRange($this->parseDate($raw));
        }

        return $range['start'] <= $range['end'] ? $range : ['start' => $range['end'], 'end' => $range['start']];
    }

    /**
     * @return array{start: string, end: string}|null
     */
    private function singleDayRange(?string $date): ?array
    {
        return $date === null ? null : ['start' => substr($date, 0, 10), 'end' => substr($date, 0, 10)];
    }

    /**
     * @return array<int, string>|null
     */
    private function castPeople(string $raw): ?array
    {
        $resolved = $this->resolvePersonIds($raw, $this->users);
        array_push($this->unmatched_people, ...$resolved['unmatched']);

        return $this->nonEmpty($resolved['ids']);
    }

    /**
     * @return array{url: string, text: string}|null
     */
    private function castLink(string $raw): ?array
    {
        return $this->parseLinks($raw)[0] ?? null;
    }

    /**
     * Each link becomes an external-link entry (`kind: link`), the same shape
     * a Files cell's own "From Link" action writes — monday.com's export only
     * carries each file's URL, never the file itself.
     *
     * @return array<int, array{id: string, kind: string, file_name: string, path: null, url: string, mime_type: string, size_bytes: int}>|null
     */
    private function castFiles(string $raw): ?array
    {
        $files = array_map(fn (array $link) => [
            'id' => (string) Str::uuid(),
            'kind' => BoardItemCellFileController::KIND_LINK,
            'file_name' => Str::limit($link['text'] === $link['url'] ? $this->fileNameFromUrl($link['url']) : $link['text'], 255, ''),
            'path' => null,
            'url' => $link['url'],
            'mime_type' => 'text/uri-list',
            'size_bytes' => 0,
        ], $this->parseLinks($raw) ?? []);

        return $this->nonEmpty($files);
    }

    private function fileNameFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base_name = rawurldecode(basename($path));

        return $base_name !== '' && $base_name !== '/' ? $base_name : $url;
    }

    /**
     * Reads "HH:MM:SS" (monday.com's Time Tracking export) or a plain number
     * of hours into the column's stopped-timer `{seconds, running_since}`.
     *
     * @return array{seconds: int, running_since: null}|null
     */
    private function castTimeTracking(string $raw): ?array
    {
        $seconds = $this->parseDuration($raw) ?? $this->parseDuration("{$raw}:00");

        if ($seconds !== null) {
            return ['seconds' => $seconds, 'running_since' => null];
        }

        $hours = $this->parseNumber($raw);

        return $hours === null ? null : ['seconds' => (int) round($hours * 3600), 'running_since' => null];
    }

    /**
     * Each sub-task becomes an entry of the Checklist column's own
     * `{id, text, is_done}` shape, see {@see parseChecklistEntries()}.
     *
     * @return array<int, array{id: string, text: string, is_done: bool}>|null
     */
    private function castChecklist(string $raw): ?array
    {
        $entries = array_map(fn (array $entry) => [
            'id' => (string) Str::uuid(),
            'text' => Str::limit($entry['text'], 500, ''),
            'is_done' => $entry['is_done'],
        ], $this->parseChecklistEntries($raw));

        return $this->nonEmpty($entries);
    }

    /**
     * The id of the column's option labeled `$label` (case-insensitive),
     * appending a new option first when the column doesn't have one yet.
     */
    private function resolveOptionId(BoardColumn $column, string $label): ?string
    {
        $label = trim($label);

        if ($label === '') {
            return null;
        }

        $options = data_get($column->config, 'options', []);

        foreach ($options as $option) {
            if (mb_strtolower((string) $option['label']) === mb_strtolower($label)) {
                return (string) $option['id'];
            }
        }

        $option = [
            'id' => (string) Str::uuid(),
            'label' => Str::limit($label, 255, ''),
            'color' => $this->optionColor($label, count($options)),
            'is_active' => true,
        ];

        $column->config = [...($column->config ?? []), 'options' => [...$options, $option]];
        $column->save();

        return $option['id'];
    }

    /**
     * The id of the board-wide tag labeled `$label`, creating it on first use.
     */
    private function resolveTagId(string $label): ?string
    {
        $label = Str::limit(trim($label), 255, '');

        if ($label === '') {
            return null;
        }

        $this->tag_ids_by_label ??= $this->board->tags()
            ->get(['id', 'label'])
            ->mapWithKeys(fn (BoardTag $tag) => [mb_strtolower($tag->label) => (string) $tag->id])
            ->all();

        $key = mb_strtolower($label);

        if (! isset($this->tag_ids_by_label[$key])) {
            $position = count($this->tag_ids_by_label);

            $tag = $this->board->tags()->create([
                'label' => $label,
                'color' => $this->optionColor($label, $position),
                'position' => $position,
            ]);

            $this->tag_ids_by_label[$key] = (string) $tag->id;
        }

        return $this->tag_ids_by_label[$key];
    }

    /**
     * @template T
     *
     * @param  array<int, T|null>  $values
     * @return array<int, T>|null
     */
    private function nonEmpty(array $values): ?array
    {
        $values = array_values(array_filter($values, fn ($value) => $value !== null));

        return $values === [] ? null : $values;
    }
}
