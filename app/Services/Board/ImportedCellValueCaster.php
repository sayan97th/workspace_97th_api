<?php

namespace App\Services\Board;

use App\Concerns\InfersBoardColumnTypes;
use App\Http\Controllers\Board\BoardItemCellFileController;
use App\Models\BoardColumn;
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
 */
class ImportedCellValueCaster
{
    use InfersBoardColumnTypes;

    /** @var array<string, string>|null lower-cased tag label => tag id, loaded on first use */
    private ?array $tag_ids_by_label = null;

    /** @var array<int, string> */
    private array $unmatched_people = [];

    /**
     * @param  Collection<int, User>  $users
     */
    public function __construct(
        private readonly WorkspaceNavigationItem $board,
        private readonly Collection $users,
    ) {}

    /**
     * @param  string|null  $end_raw  a Timeline column's second source cell ("- End"), when it was split in two
     */
    public function cast(BoardColumn $column, string $raw, ?string $end_raw = null): mixed
    {
        $raw = trim($raw);

        if ($raw === '' && trim((string) $end_raw) === '') {
            return null;
        }

        return match ($column->type) {
            BoardColumn::TYPE_TEXT => Str::limit($raw, 2000, ''),
            BoardColumn::TYPE_LONG_TEXT => $raw,
            BoardColumn::TYPE_NUMBER => $this->castNumber($raw),
            BoardColumn::TYPE_PROGRESS => $this->parsePercent($raw),
            BoardColumn::TYPE_RATING => $this->castRating($raw),
            BoardColumn::TYPE_CHECKBOX => $this->isUncheckedToken($raw) ? null : true,
            BoardColumn::TYPE_DATE => $this->parseDate($raw),
            BoardColumn::TYPE_TIMELINE => $this->castTimeline($raw, $end_raw),
            BoardColumn::TYPE_PEOPLE => $this->castPeople($raw),
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
            // Vote (who voted), Dependency/Connect boards (ids on this or another board) and the
            // computed types can't be reconstructed from an exported cell's display text.
            default => null,
        };
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
     * @return array{start: string, end: string}|null
     */
    private function castTimeline(string $start_raw, ?string $end_raw): ?array
    {
        if ($end_raw === null) {
            return $this->parseDateRange($start_raw) ?? $this->singleDayRange($this->parseDate($start_raw));
        }

        $start = $this->parseDate($start_raw);
        $end = $this->parseDate($end_raw);

        if ($start === null || $end === null) {
            // monday.com lets a Timeline hold just one side — keep that day rather than losing it.
            return $this->singleDayRange($start ?? $end);
        }

        [$start, $end] = [substr($start, 0, 10), substr($end, 0, 10)];

        return $start <= $end ? ['start' => $start, 'end' => $end] : ['start' => $end, 'end' => $start];
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
        if (preg_match('/^(\d+):([0-5]\d)(?::([0-5]\d))?$/', $raw, $matches) === 1) {
            $seconds = ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) ($matches[3] ?? 0);

            return ['seconds' => $seconds, 'running_since' => null];
        }

        $hours = $this->parseNumber($raw);

        return $hours === null ? null : ['seconds' => (int) round($hours * 3600), 'running_since' => null];
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
