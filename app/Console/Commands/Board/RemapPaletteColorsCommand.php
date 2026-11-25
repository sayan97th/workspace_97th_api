<?php

namespace App\Console\Commands\Board;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardTag;
use App\Models\WorkspaceNavigationItem;
use App\Support\ColorPalette;
use Illuminate\Console\Command;

/**
 * Moves colors saved from the old monday.com palette onto Tonk's palette
 * ({@see ColorPalette}): Status, Label and Dropdown options, Button columns,
 * board tags, group colors and sidebar folder colors. Each color becomes the
 * palette color it looks closest to (see {@see ColorPalette::nearest()}), so a
 * "Done" green stays green and a "Stuck" red stays red. Colors already in the
 * palette are left alone, so running it twice is safe. Opt in: new records get
 * palette colors anyway, this only restyles what was saved before.
 */
// php artisan board:remap-palette-colors --board=4767811559 --dry-run
class RemapPaletteColorsCommand extends Command
{
    protected $signature = 'board:remap-palette-colors
        {--board=* : Only these board ids, every board (and every sidebar folder) when omitted}
        {--dry-run : Print what would change without writing to the database}';

    protected $description = 'Move saved status, label, tag, group and folder colors onto the Tonk palette';

    /** Column types whose `config.options[].color` a person picks. */
    private const OPTION_COLUMN_TYPES = [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN];

    private bool $is_dry_run = false;

    /** @var array<string, int> */
    private array $changed_counts = ['options' => 0, 'buttons' => 0, 'tags' => 0, 'groups' => 0, 'folders' => 0];

    public function handle(): int
    {
        $this->is_dry_run = (bool) $this->option('dry-run');
        $board_ids = array_map('intval', (array) $this->option('board'));

        $this->remapColumns($board_ids);
        $this->remapTags($board_ids);
        $this->remapGroups($board_ids);
        if ($board_ids === []) {
            $this->remapFolders();
        }

        $this->table(['Record', $this->is_dry_run ? 'Would change' : 'Changed'], collect($this->changed_counts)
            ->map(fn (int $count, string $record) => [$record, $count])
            ->values()
            ->all());

        if ($this->is_dry_run) {
            $this->comment('Dry run, nothing was written.');
        }

        return self::SUCCESS;
    }

    /** @param  array<int, int>  $board_ids */
    private function remapColumns(array $board_ids): void
    {
        BoardColumn::query()
            ->whereIn('type', [...self::OPTION_COLUMN_TYPES, BoardColumn::TYPE_BUTTON])
            ->when($board_ids !== [], fn ($query) => $query->whereIn('board_id', $board_ids))
            ->orderBy('id')
            ->each(function (BoardColumn $column) {
                $config = is_array($column->config) ? $column->config : [];
                $is_changed = false;

                foreach ($config['options'] ?? [] as $index => $option) {
                    $next_color = is_array($option) ? $this->remappedColor($option['color'] ?? null) : null;
                    if ($next_color === null) {
                        continue;
                    }
                    $this->report("column {$column->id} option \"".($option['label'] ?? '').'"', $option['color'], $next_color);
                    $config['options'][$index]['color'] = $next_color;
                    $this->changed_counts['options']++;
                    $is_changed = true;
                }

                $next_button_color = $this->remappedColor($config['button_color'] ?? null);
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
                $next_color = $this->remappedColor($tag->color);
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
                $next_color = $this->remappedColor($group->accent_color);
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
                $next_color = $this->remappedColor($folder->color);
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

    /** The palette color to store instead, or null when the value is already in the palette (or not a color). */
    private function remappedColor(mixed $color): ?string
    {
        if (! is_string($color) || ColorPalette::contains($color)) {
            return null;
        }

        return ColorPalette::nearest($color);
    }

    private function report(string $record, string $from, string $to): void
    {
        if ($this->output->isVerbose() || $this->is_dry_run) {
            $this->line("  {$record}: {$from} -> {$to}");
        }
    }
}
