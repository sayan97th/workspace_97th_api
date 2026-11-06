<?php

namespace App\Console\Commands\Board;

use App\Models\BoardColumn;
use App\Models\BoardItemValue;
use App\Services\Board\CellImageVariantService;
use Illuminate\Console\Command;

/**
 * Gives the images uploaded to Files cells before thumbnails existed their
 * size and light WebP copies (see {@see CellImageVariantService}), so old
 * cells stop downloading full originals for their thumbnails. Entries that
 * already have a `thumb_url` are skipped, so running it twice is safe. An
 * image that cannot be processed keeps working with its original.
 */
// php artisan board:generate-cell-image-variants --board=4767811559 --dry-run
class GenerateCellImageVariantsCommand extends Command
{
    protected $signature = 'board:generate-cell-image-variants
        {--board=* : Only the Files columns of these board ids, every board when omitted}
        {--dry-run : List the images that would be processed without writing anything}';

    protected $description = 'Generate thumbnails, previews and sizes for images already stored in Files cells';

    public function handle(CellImageVariantService $image_variants): int
    {
        $is_dry_run = (bool) $this->option('dry-run');
        $board_ids = array_map('intval', (array) $this->option('board'));
        $processed = 0;
        $failed = 0;

        $column_ids = BoardColumn::query()
            ->where('type', BoardColumn::TYPE_FILES)
            ->when($board_ids !== [], fn ($query) => $query->whereIn('board_id', $board_ids))
            ->pluck('id');

        BoardItemValue::query()
            ->whereIn('column_id', $column_ids)
            ->chunkById(100, function ($values) use ($image_variants, $is_dry_run, &$processed, &$failed) {
                foreach ($values as $value) {
                    $files = is_array($value->value) ? $value->value : [];
                    $changed = false;

                    foreach ($files as $index => $file) {
                        if (! $this->needsVariants($file)) {
                            continue;
                        }

                        $this->line(($is_dry_run ? '[dry run] ' : '').$file['file_name'].' ('.$file['path'].')');
                        if ($is_dry_run) {
                            $processed++;

                            continue;
                        }

                        $variants = $image_variants->generateForStoredFile($file['path']);
                        if ($variants === null) {
                            $failed++;
                            $this->warn('  skipped, the original keeps being used');

                            continue;
                        }

                        $files[$index] = [...$file, ...$variants];
                        $changed = true;
                        $processed++;
                    }

                    // Saved without touching automations or `updated_at` logic, nothing the user sees changed.
                    if ($changed) {
                        BoardItemValue::query()->whereKey($value->getKey())->update(['value' => json_encode(array_values($files))]);
                    }
                }
            });

        $this->info(($is_dry_run ? 'Would process ' : 'Processed ').$processed.' image(s), '.$failed.' skipped.');

        return self::SUCCESS;
    }

    /**
     * @param  mixed  $file
     */
    private function needsVariants($file): bool
    {
        return is_array($file)
            && ($file['kind'] ?? 'file') === 'file'
            && ! empty($file['path'])
            && empty($file['thumb_url'])
            && CellImageVariantService::supports($file['mime_type'] ?? null);
    }
}
