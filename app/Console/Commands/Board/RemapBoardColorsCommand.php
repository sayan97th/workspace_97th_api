<?php

namespace App\Console\Commands\Board;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardTag;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Console\Command;

/**
 * Shared engine of the color remap commands: walks every saved color a person
 * picks (Status, Label and Dropdown options, Button columns, board tags, group
 * colors and sidebar folder colors) and replaces the ones {@see remappedColor()}
 * maps, leaving every other color alone so a second run changes nothing. Each
 * command only says which palette it moves colors to.
 *
 * Options every subclass gets: `--board=*` (only these boards; folders are then
 * skipped since they belong to no board), `--only=*` (only these record types)
 * and `--dry-run` (list the changes, write nothing). Writes use `saveQuietly()`,
 * so no activity log entries or broadcasts are produced.
 */
abstract class RemapBoardColorsCommand extends Command
{
    /** Record types a run can touch, in the order they are processed and reported. */
    protected const RECORD_TYPES = ['options', 'buttons', 'tags', 'groups', 'folders'];

    /** Column types whose `config.options[].color` a person picks. */
    private const OPTION_COLUMN_TYPES = [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN];

    /** The options every remap command shares, appended to each subclass's own signature line. */
    protected const SHARED_OPTIONS = '
        {--board=* : Only these board ids, every board (and every sidebar folder) when omitted}
        {--only=* : Only these record types: options, buttons, tags, groups, folders}
        {--dry-run : Print what would change without writing to the database}';

    private bool $is_dry_run = false;

    /** @var array<int, string> */
    private array $record_types = self::RECORD_TYPES;

    /** @var array<string, int> */
    private array $changed_counts = [];

    /** The color to store instead of `$color`, or null to leave it as it is. */
    abstract protected function remappedColor(string $color): ?string;

    public function handle(): int
    {
        $this->is_dry_run = (bool) $this->option('dry-run');
        $board_ids = array_map('intval', (array) $this->option('board'));

        $only = array_values(array_filter(array_map(fn ($type) => strtolower(trim((string) $type)), (array) $this->option('only'))));
        $unknown_types = array_diff($only, self::RECORD_TYPES);
        if ($unknown_types !== []) {
            $this->error('Unknown record type: '.implode(', ', $unknown_types).'. Use '.implode(', ', self::RECORD_TYPES).'.');

            return self::INVALID;
        }
        $this->record_types = $only === [] ? self::RECORD_TYPES : $only;
        $this->changed_counts = array_fill_keys($this->record_types, 0);

        if ($this->wants('options') || $this->wants('buttons')) {
            $this->remapColumns($board_ids);
        }
        if ($this->wants('tags')) {
            $this->remapTags($board_ids);
        }
        if ($this->wants('groups')) {
            $this->remapGroups($board_ids);
        }
        if ($this->wants('folders') && $board_ids === []) {
            $this->remapFolders();
        }

        $this->table(
            ['Record', $this->is_dry_run ? 'Would change' : 'Changed'],
            collect($this->changed_counts)->map(fn (int $count, string $record) => [$record, $count])->values()->all()
        );

        if ($this->is_dry_run) {
            $this->comment('Dry run, nothing was written.');
        }

        return self::SUCCESS;
    }

    private function wants(string $record_type): bool
    {
        return in_array($record_type, $this->record_types, true);
    }

    /** @param  array<int, int>  $board_ids */
    private function remapColumns(array $board_ids): void
    {
        $types = [
            ...($this->wants('options') ? self::OPTION_COLUMN_TYPES : []),
            ...($this->wants('buttons') ? [BoardColumn::TYPE_BUTTON] : []),
        ];

        BoardColumn::query()
            ->whereIn('type', $types)
            ->when($board_ids !== [], fn ($query) => $query->whereIn('board_id', $board_ids))
            ->orderBy('id')
            ->each(function (BoardColumn $column) {
                $config = is_array($column->config) ? $column->config : [];
                $is_changed = false;

                if ($this->wants('options')) {
                    foreach ($config['options'] ?? [] as $index => $option) {
                        $next_color = is_array($option) ? $this->nextColor($option['color'] ?? null) : null;
                        if ($next_color === null) {
                            continue;
                        }
                        $this->report("column {$column->id} option \"".($option['label'] ?? '').'"', $option['color'], $next_color);
                        $config['options'][$index]['color'] = $next_color;
                        $this->changed_counts['options']++;
                        $is_changed = true;
                    }
                }

                $next_button_color = $this->wants('buttons') ? $this->nextColor($config['button_color'] ?? null) : null;
                if ($next_button_color !== null) {
                    $this->report("column {$column->id} button", $config['button_color'], $next_button_color);
                    $config['button_color'] = $next_button_color;
                    $this->changed_counts['buttons']++;
                    $is_changed = true;
                }

                if ($is_changed && ! $this->is_dry_run) {
                    $column->config = $config;
                    $column->saveQuietly();
                }
            });
    }

    /** @param  array<int, int>  $board_ids */
    private function remapTags(array $board_ids): void
    {
        BoardTag::query()
            ->when($board_ids !== [], fn ($query) => $query->whereIn('board_id', $board_ids))
            ->orderBy('id')
            ->each(function (BoardTag $tag) {
                $next_color = $this->nextColor($tag->color);
                if ($next_color === null) {
                    return;
                }
                $this->report("tag {$tag->id} \"{$tag->label}\"", $tag->color, $next_color);
                $this->changed_counts['tags']++;
                if (! $this->is_dry_run) {
                    $tag->color = $next_color;
                    $tag->saveQuietly();
                }
            });
    }

    /** @param  array<int, int>  $board_ids */
    private function remapGroups(array $board_ids): void
    {
        BoardGroup::query()
            ->when($board_ids !== [], fn ($query) => $query->whereIn('board_id', $board_ids))
            ->orderBy('id')
            ->each(function (BoardGroup $group) {
                $next_color = $this->nextColor($group->accent_color);
                if ($next_color === null) {
                    return;
                }
                $this->report("group {$group->id} \"{$group->name}\"", $group->accent_color, $next_color);
                $this->changed_counts['groups']++;
                if (! $this->is_dry_run) {
                    $group->accent_color = $next_color;
                    $group->saveQuietly();
                }
            });
    }

    private function remapFolders(): void
    {
        WorkspaceNavigationItem::query()
            ->whereNotNull('color')
            ->orderBy('id')
            ->each(function (WorkspaceNavigationItem $folder) {
                $next_color = $this->nextColor($folder->color);
                if ($next_color === null) {
                    return;
                }
                $this->report("folder {$folder->id}", $folder->color, $next_color);
                $this->changed_counts['folders']++;
                if (! $this->is_dry_run) {
                    $folder->color = $next_color;
                    $folder->saveQuietly();
                }
            });
    }

    /** {@see remappedColor()} for a stored value, null when it is not a string or would not change. */
    private function nextColor(mixed $color): ?string
    {
        if (! is_string($color) || $color === '') {
            return null;
        }
        $next_color = $this->remappedColor($color);

        return $next_color === null || strtolower($next_color) === strtolower($color) ? null : $next_color;
    }

    private function report(string $record, string $from, string $to): void
    {
        if ($this->output->isVerbose() || $this->is_dry_run) {
            $this->line("  {$record}: {$from} -> {$to}");
        }
    }
}
