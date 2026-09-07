<?php

namespace App\Console\Commands\Board;

use App\Models\BoardView;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\MondayBoardImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

// php artisan board:import-monday
class ImportMondayBoardCommand extends Command
{
    protected $signature = 'board:import-monday
        {file=import/Palomar_Roadmap_Software_Engineering_1788743889.xlsx : Path to the monday.com .xlsx export}
        {--workspace=fulfillment : Slug of the workspace to import the board into}
        {--force : Replace an existing board with the same name without prompting}
        {--dry-run : Parse the file and print a summary without writing to the database}
        {--updates-sheet=updates : Name of the sheet containing the item detail drawer\'s comment threads}
        {--updates=skip : How to handle that sheet — skip (default), redact (replace credential-looking lines), raw (import verbatim), or exclude (drop only comments that look like they contain a credential)}';

    protected $description = 'Import a monday.com board export (.xlsx) into a workspace as a real board';

    public function __construct(private readonly MondayBoardImportService $importer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $updates_mode = (string) $this->option('updates');
        $valid_updates_modes = [
            MondayBoardImportService::UPDATES_MODE_SKIP,
            MondayBoardImportService::UPDATES_MODE_REDACT,
            MondayBoardImportService::UPDATES_MODE_RAW,
            MondayBoardImportService::UPDATES_MODE_EXCLUDE,
        ];
        if (! in_array($updates_mode, $valid_updates_modes, true)) {
            $this->error("Invalid --updates value \"{$updates_mode}\". Expected one of: ".implode(', ', $valid_updates_modes));

            return self::FAILURE;
        }

        $path = $this->resolveFilePath((string) $this->argument('file'));
        if ($path === null) {
            return self::FAILURE;
        }

        $workspace = Workspace::where('slug', $this->option('workspace'))->first();
        if ($workspace === null) {
            $available_slugs = Workspace::pluck('slug')->implode(', ');
            $this->error("No workspace found with slug \"{$this->option('workspace')}\". Available slugs: {$available_slugs}");

            return self::FAILURE;
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $e) {
            $this->error("Could not read the spreadsheet: {$e->getMessage()}");

            return self::FAILURE;
        }

        $sheet = $spreadsheet->getSheet(0);
        $parsed = $this->importer->parse($sheet);

        $group_count = count($parsed['groups']);
        $item_count = collect($parsed['groups'])->sum(fn (array $group) => count($group['items']));
        $subitem_count = collect($parsed['groups'])
            ->flatMap(fn (array $group) => $group['items'])
            ->sum(fn (array $item) => count($item['subitems']));

        $this->info("Board: {$parsed['title']}");
        $this->line("Groups: {$group_count} | Items: {$item_count} | Subitems: {$subitem_count}");

        $update_rows = [];
        if ($updates_mode !== MondayBoardImportService::UPDATES_MODE_SKIP) {
            $updates_sheet = $this->findSheet($spreadsheet, (string) $this->option('updates-sheet'));

            if ($updates_sheet === null) {
                $this->warn("No sheet named \"{$this->option('updates-sheet')}\" was found — skipping comment import.");
            } else {
                $update_rows = $this->importer->parseUpdates($updates_sheet);
                $this->line("Updates: {$this->countTopLevel($update_rows)} threads, ".(count($update_rows) - $this->countTopLevel($update_rows)).' replies (mode: '.$updates_mode.')');
            }
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was written to the database.');

            return self::SUCCESS;
        }

        // Not scoped to root-level boards: the existing board may be nested
        // inside a folder (e.g. a placeholder someone already created there
        // for this exact import), and a re-run should replace it in place
        // rather than creating a second, root-level duplicate.
        $existing = $workspace->navigationItems()
            ->where('type', WorkspaceNavigationItem::TYPE_LEAF)
            ->where('label', $parsed['title'])
            ->first();

        if ($existing !== null) {
            $replace = $this->option('force') || $this->confirm(
                "A board named \"{$parsed['title']}\" already exists in \"{$workspace->name}\". Replace it?",
                false,
            );

            if (! $replace) {
                $this->comment('Import cancelled.');

                return self::SUCCESS;
            }
        }

        // Recreate in the same spot in the navigation tree the replaced board
        // was in (root, or nested inside a folder), instead of always
        // appending a fresh root-level board.
        $parent_id = $existing?->parent_id;

        [$summary, $updates_summary] = DB::transaction(function () use ($workspace, $parsed, $existing, $parent_id, $update_rows, $updates_mode) {
            // A hard delete (not a soft delete) so the FK cascades actually
            // clean up the old board's views/groups/columns/items/values —
            // a soft delete would leave them orphaned under the trashed board.
            $existing?->forceDelete();

            $board = $this->createBoard($workspace, $parsed['title'], $parent_id);
            $view = $this->createPrimaryView($board);

            $summary = $this->importer->import($board, $view, $parsed);

            $updates_summary = $update_rows === []
                ? null
                : $this->importer->importUpdates($summary['item_ids_by_monday_id'], $update_rows, $updates_mode);

            return [$summary, $updates_summary];
        });

        $this->newLine();
        $this->info('Import complete.');
        $this->table(
            ['Groups', 'Items', 'Subitems'],
            [[$summary['groups'], $summary['items'], $summary['subitems']]],
        );

        $unmatched_people = $summary['unmatched_people'];

        if ($updates_summary !== null) {
            $this->table(
                ['Comments', 'Replies', 'Skipped (no matching item)', 'Skipped (contained a credential)'],
                [[$updates_summary['comments'], $updates_summary['replies'], $updates_summary['skipped_no_item'], $updates_summary['skipped_secret']]],
            );
            $unmatched_people = array_values(array_unique([...$unmatched_people, ...$updates_summary['unmatched_authors']]));
        }

        if ($unmatched_people !== []) {
            $this->warn('Names from the sheet that did not match an existing user (left unassigned):');
            foreach ($unmatched_people as $name) {
                $this->line("  - {$name}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{parent_post_id: string}>  $rows
     */
    private function countTopLevel(array $rows): int
    {
        return count(array_filter($rows, fn (array $row) => $row['parent_post_id'] === ''));
    }

    private function findSheet(Spreadsheet $spreadsheet, string $name): ?Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (strtolower($sheet->getTitle()) === strtolower($name)) {
                return $sheet;
            }
        }

        return null;
    }

    private function resolveFilePath(string $file): ?string
    {
        $is_absolute = str_starts_with($file, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $file) === 1;
        $path = $is_absolute ? $file : base_path($file);

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return null;
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            $this->error('Only .xlsx files are supported.');

            return null;
        }

        return $path;
    }

    private function createBoard(Workspace $workspace, string $label, ?int $parent_id): WorkspaceNavigationItem
    {
        $next_position = (int) $workspace->navigationItems()->where('parent_id', $parent_id)->max('position') + 1;

        return $workspace->navigationItems()->create([
            'parent_id' => $parent_id,
            'type' => WorkspaceNavigationItem::TYPE_LEAF,
            'label' => $label,
            'slug' => Str::slug($label),
            'display_style' => 'table',
            'board_type' => WorkspaceNavigationItem::BOARD_TYPE_MAIN,
            'is_favorite' => false,
            'position' => $next_position,
        ]);
    }

    private function createPrimaryView(WorkspaceNavigationItem $board): BoardView
    {
        return $board->views()->create([
            'label' => 'Main table',
            'position' => 0,
            'is_primary' => true,
            'row_height' => 'single',
        ]);
    }
}
