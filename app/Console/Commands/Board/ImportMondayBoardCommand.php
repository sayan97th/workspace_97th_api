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
use Throwable;

// php artisan board:import-monday
class ImportMondayBoardCommand extends Command
{
    protected $signature = 'board:import-monday
        {file=import/Palomar_Roadmap_Software_Engineering_1788743889.xlsx : Path to the monday.com .xlsx export}
        {--workspace=fulfillment : Slug of the workspace to import the board into}
        {--force : Replace an existing board with the same name without prompting}
        {--dry-run : Parse the file and print a summary without writing to the database}';

    protected $description = 'Import a monday.com board export (.xlsx) into a workspace as a real board';

    public function __construct(private readonly MondayBoardImportService $importer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
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

        $summary = DB::transaction(function () use ($workspace, $parsed, $existing, $parent_id) {
            // A hard delete (not a soft delete) so the FK cascades actually
            // clean up the old board's views/groups/columns/items/values —
            // a soft delete would leave them orphaned under the trashed board.
            $existing?->forceDelete();

            $board = $this->createBoard($workspace, $parsed['title'], $parent_id);
            $view = $this->createPrimaryView($board);

            return $this->importer->import($board, $view, $parsed);
        });

        $this->newLine();
        $this->info('Import complete.');
        $this->table(
            ['Groups', 'Items', 'Subitems'],
            [[$summary['groups'], $summary['items'], $summary['subitems']]],
        );

        if ($summary['unmatched_people'] !== []) {
            $this->warn('Names from the sheet that did not match an existing user (left unassigned):');
            foreach ($summary['unmatched_people'] as $name) {
                $this->line("  - {$name}");
            }
        }

        return self::SUCCESS;
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
