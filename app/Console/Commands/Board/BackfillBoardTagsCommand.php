<?php

namespace App\Console\Commands\Board;

use App\Models\BoardColumn;
use App\Models\BoardItemValue;
use App\Models\BoardTag;
use App\Services\Search\TagCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turns legacy Tags cells into real board tags. Older seeded boards stored the
 * tags as per column `config.options` and kept their option ids ("design") or
 * raw labels in the cells, which the Tags picker, its usage counts and Search
 * Everything cannot see. Each such entry becomes a {@see BoardTag} on the
 * column's board (reusing a tag with the same label) and the cell keeps its id.
 * Cells that already hold tag ids are left alone, so running it twice is safe.
 */
// php artisan board:backfill-tags --board=4767811559 --dry-run
class BackfillBoardTagsCommand extends Command
{
    protected $signature = 'board:backfill-tags
        {--board=* : Only the Tags columns of these board ids, every board when omitted}
        {--dry-run : Print what would change without writing to the database}';

    protected $description = 'Convert legacy Tags cell values (option ids or raw labels) into board tags';

    private const FALLBACK_COLORS = ['#579bfc', '#00c875', '#fdab3d', '#e2445c', '#a25ddc', '#ff642e', '#037f4c', '#9cd326'];

    public function handle(): int
    {
        $is_dry_run = (bool) $this->option('dry-run');
        $tag_ids = BoardTag::query()->pluck('id')->flip()->all();
        $created_tags = 0;
        $updated_cells = 0;

        $board_ids = array_map('intval', (array) $this->option('board'));

        BoardColumn::query()
            ->where('type', BoardColumn::TYPE_TAGS)
            ->when($board_ids !== [], fn ($query) => $query->whereIn('board_id', $board_ids))
            ->orderBy('id')
            ->each(function (BoardColumn $column) use ($is_dry_run, $tag_ids, &$created_tags, &$updated_cells) {
                // Legacy per column options, keyed by the id the cells stored.
                $options = [];
                foreach ($column->config['options'] ?? [] as $option) {
                    if (is_array($option) && isset($option['id'])) {
                        $options[(string) $option['id']] = $option;
                    }
                }
                $board_tags = BoardTag::query()->where('board_id', $column->board_id)->orderBy('position')->get();
                $tag_id_by_key = $board_tags->mapWithKeys(fn (BoardTag $tag) => [TagCatalog::normalizeLabel($tag->label) => $tag->id])->all();
                $next_position = (int) $board_tags->max('position') + 1;

                BoardItemValue::query()->where('column_id', $column->id)->orderBy('id')->each(function (BoardItemValue $cell) use ($is_dry_run, $tag_ids, $options, $column, &$tag_id_by_key, &$next_position, &$created_tags, &$updated_cells) {
                    $entries = is_array($cell->value) ? $cell->value : [];
                    $next_value = [];
                    $has_legacy_entry = false;

                    foreach ($entries as $entry) {
                        if (! is_scalar($entry) || trim((string) $entry) === '') {
                            continue;
                        }
                        $entry = (string) $entry;
                        if (ctype_digit($entry) && isset($tag_ids[(int) $entry])) {
                            $next_value[] = $entry;

                            continue;
                        }

                        $has_legacy_entry = true;
                        $option = $options[$entry] ?? null;
                        $label = '#'.ltrim(trim((string) ($option['label'] ?? $entry)), '#');
                        $key = TagCatalog::normalizeLabel($label);

                        if (! isset($tag_id_by_key[$key])) {
                            $color = (string) ($option['color'] ?? self::FALLBACK_COLORS[$next_position % count(self::FALLBACK_COLORS)]);
                            $tag_id_by_key[$key] = $is_dry_run
                                ? "new:{$key}"
                                : BoardTag::create(['board_id' => $column->board_id, 'label' => $label, 'color' => $color, 'position' => $next_position])->id;
                            $next_position++;
                            $created_tags++;
                            $this->line("Board {$column->board_id}: tag {$label}");
                        }

                        $next_value[] = (string) $tag_id_by_key[$key];
                    }

                    if (! $has_legacy_entry) {
                        return;
                    }

                    $updated_cells++;
                    if (! $is_dry_run) {
                        // A plain query, so a data fix never fires item change automations or activity entries.
                        DB::table('board_item_values')->where('id', $cell->id)->update(['value' => json_encode(array_values(array_unique($next_value)))]);
                    }
                });
            });

        $this->info($is_dry_run
            ? "Dry run, would create {$created_tags} tags and update {$updated_cells} cells."
            : "Created {$created_tags} tags and updated {$updated_cells} cells.");

        return self::SUCCESS;
    }
}
