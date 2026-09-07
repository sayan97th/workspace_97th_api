<?php

namespace App\Console\Commands\Board;

use App\Concerns\ImportsMondayBoardFiles;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\MondayBoardImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

// php artisan board:import-monday-directory --force --updates=raw
class ImportMondayBoardTreeCommand extends Command
{
    use ImportsMondayBoardFiles;

    protected $signature = 'board:import-monday-directory
        {directory=import : Path to a directory tree of monday.com .xlsx exports}
        {--workspace=fulfillment : Slug of the workspace to import every board into}
        {--force : Replace an existing board with the same name without prompting}
        {--dry-run : Walk the directory and print a summary without writing to the database}
        {--updates-sheet=updates : Name of the sheet containing the item detail drawer\'s comment threads}
        {--updates=skip : How to handle that sheet — skip (default), redact (replace credential-looking lines), raw (import verbatim), or exclude (drop only comments that look like they contain a credential)}';

    protected $description = 'Import every monday.com board export (.xlsx) under a directory tree, mapping each subfolder to a sidebar folder and each file to a board';

    public function __construct(private readonly MondayBoardImportService $importer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $updates_mode = (string) $this->option('updates');
        if (! $this->isValidUpdatesMode($updates_mode)) {
            $this->error("Invalid --updates value \"{$updates_mode}\". Expected one of: ".implode(', ', $this->validUpdatesModes));

            return self::FAILURE;
        }

        $root = $this->resolveDirectoryPath((string) $this->argument('directory'));
        if ($root === null) {
            return self::FAILURE;
        }

        $workspace = Workspace::where('slug', $this->option('workspace'))->first();
        if ($workspace === null) {
            $available_slugs = Workspace::pluck('slug')->implode(', ');
            $this->error("No workspace found with slug \"{$this->option('workspace')}\". Available slugs: {$available_slugs}");

            return self::FAILURE;
        }

        $files = $this->discoverFiles($root);

        if ($files === []) {
            $this->warn("No .xlsx files found under {$root}.");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d spreadsheet%s under %s — importing into "%s".',
            count($files),
            count($files) === 1 ? '' : 's',
            $root,
            $workspace->name,
        ));

        $dry_run = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        /** @var array<string, int> $group_ids_by_path */
        $group_ids_by_path = [];
        $rows = [];
        $totals = [
            'created' => 0, 'replaced' => 0, 'skipped' => 0, 'failed' => 0,
            'groups' => 0, 'items' => 0, 'subitems' => 0, 'comments' => 0, 'replies' => 0,
        ];
        $unmatched_people = [];

        foreach ($files as $file) {
            $relative_label = $file['folders'] === []
                ? basename($file['path'])
                : implode(' / ', $file['folders']).' / '.basename($file['path']);

            try {
                $outcome = $this->importOneFile($file, $workspace, $updates_mode, $dry_run, $force, $group_ids_by_path);
            } catch (Throwable $e) {
                $this->error("[FAIL] {$relative_label}: {$e->getMessage()}");
                $totals['failed']++;
                $rows[] = [$relative_label, 'failed', '-', '-', '-'];

                continue;
            }

            if ($outcome['action'] === 'dry_run') {
                $this->line("[DRY RUN] {$relative_label} — \"{$outcome['title']}\" ({$outcome['groups']} groups, {$outcome['items']} items, {$outcome['subitems']} subitems)");
                $totals['groups'] += $outcome['groups'];
                $totals['items'] += $outcome['items'];
                $totals['subitems'] += $outcome['subitems'];

                continue;
            }

            if ($outcome['action'] === 'skipped') {
                $this->warn("[SKIP] {$relative_label}: a board named \"{$outcome['title']}\" already exists there (pass --force to replace it).");
                $totals['skipped']++;
                $rows[] = [$relative_label, 'skipped', '-', '-', '-'];

                continue;
            }

            $summary = $outcome['summary'];
            $updates_summary = $outcome['updates_summary'];

            $totals[$outcome['action']]++;
            $totals['groups'] += $summary['groups'];
            $totals['items'] += $summary['items'];
            $totals['subitems'] += $summary['subitems'];
            array_push($unmatched_people, ...$summary['unmatched_people']);

            if ($updates_summary !== null) {
                $totals['comments'] += $updates_summary['comments'];
                $totals['replies'] += $updates_summary['replies'];
                array_push($unmatched_people, ...$updates_summary['unmatched_authors']);
            }

            $this->info(sprintf(
                '[%s] %s — "%s" (%d groups, %d items, %d subitems%s)',
                $outcome['action'] === 'created' ? 'OK' : 'REPLACED',
                $relative_label,
                $outcome['title'],
                $summary['groups'],
                $summary['items'],
                $summary['subitems'],
                $updates_summary !== null ? ", {$updates_summary['comments']} comments" : '',
            ));

            $rows[] = [$relative_label, $outcome['action'], $summary['groups'], $summary['items'], $summary['subitems']];
        }

        $this->newLine();

        if ($dry_run) {
            $this->comment('Dry run — nothing was written to the database.');
            $this->table(['Groups', 'Items', 'Subitems'], [[$totals['groups'], $totals['items'], $totals['subitems']]]);

            return self::SUCCESS;
        }

        $this->info('Import complete.');
        $this->table(['File', 'Result', 'Groups', 'Items', 'Subitems'], $rows);
        $this->table(
            ['Created', 'Replaced', 'Skipped', 'Failed', 'Total groups', 'Total items', 'Total subitems', 'Comments', 'Replies'],
            [[
                $totals['created'], $totals['replaced'], $totals['skipped'], $totals['failed'],
                $totals['groups'], $totals['items'], $totals['subitems'], $totals['comments'], $totals['replies'],
            ]],
        );

        $unmatched_people = array_values(array_unique($unmatched_people));
        if ($unmatched_people !== []) {
            $this->warn('Names from the sheets that did not match an existing user (left unassigned):');
            foreach ($unmatched_people as $name) {
                $this->line("  - {$name}");
            }
        }

        if ($totals['failed'] > 0 && $totals['created'] === 0 && $totals['replaced'] === 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Loads, parses and (unless `$dry_run`) imports one file, isolated from every other file in
     * the batch — the caller wraps this in a try/catch so a corrupt sheet or a database error on
     * one file doesn't abort the rest of a possibly dozens-of-files-long batch.
     *
     * @param  array{path: string, folders: array<int, string>}  $file
     * @param  array<string, int>  $group_ids_by_path
     * @return array{
     *     action: 'dry_run'|'created'|'replaced'|'skipped',
     *     title: string,
     *     groups: int,
     *     items: int,
     *     subitems: int,
     *     summary: array{groups: int, items: int, subitems: int, unmatched_people: array<int, string>, item_ids_by_monday_id: array<string, int>}|null,
     *     updates_summary: array{comments: int, replies: int, skipped_no_item: int, skipped_secret: int, unmatched_authors: array<int, string>}|null,
     * }
     */
    private function importOneFile(
        array $file,
        Workspace $workspace,
        string $updates_mode,
        bool $dry_run,
        bool $force,
        array &$group_ids_by_path,
    ): array {
        $spreadsheet = IOFactory::load($file['path']);
        $sheet = $spreadsheet->getSheet(0);
        $parsed = $this->importer->parse($sheet);

        $update_rows = [];
        if ($updates_mode !== MondayBoardImportService::UPDATES_MODE_SKIP) {
            $updates_sheet = $this->findSheet($spreadsheet, (string) $this->option('updates-sheet'));

            if ($updates_sheet !== null) {
                $update_rows = $this->importer->parseUpdates($updates_sheet);
            }
        }

        if ($dry_run) {
            return [
                'action' => 'dry_run',
                'title' => $parsed['title'],
                'groups' => count($parsed['groups']),
                'items' => collect($parsed['groups'])->sum(fn (array $group) => count($group['items'])),
                'subitems' => collect($parsed['groups'])->flatMap(fn (array $group) => $group['items'])->sum(fn (array $item) => count($item['subitems'])),
                'summary' => null,
                'updates_summary' => null,
            ];
        }

        $parent_id = $this->resolveGroupPath($workspace, $file['folders'], $group_ids_by_path);

        $result = $this->importParsedBoard(
            importer: $this->importer,
            workspace: $workspace,
            parsed: $parsed,
            update_rows: $update_rows,
            updates_mode: $updates_mode,
            parent_id: $parent_id,
            force: $force,
            search_globally: false,
            interactive: false,
        );

        return [
            'action' => $result['action'],
            'title' => $parsed['title'],
            'groups' => $result['summary']['groups'] ?? 0,
            'items' => $result['summary']['items'] ?? 0,
            'subitems' => $result['summary']['subitems'] ?? 0,
            'summary' => $result['summary'],
            'updates_summary' => $result['updates_summary'],
        ];
    }

    /**
     * Recursively lists every `.xlsx` file under `$root`, alongside the chain of folder names
     * (relative to `$root`) it was found in — that chain is what {@see resolveGroupPath()} turns
     * into nested sidebar folders. Hidden entries (dotfiles) and Excel's own `~$...` lock files
     * are skipped.
     *
     * @return array<int, array{path: string, folders: array<int, string>}>
     */
    private function discoverFiles(string $root): array
    {
        $files = [];
        $this->collectFiles($root, [], $files);

        usort($files, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return $files;
    }

    /**
     * @param  array<int, string>  $folder_trail
     * @param  array<int, array{path: string, folders: array<int, string>}>  $files
     */
    private function collectFiles(string $directory, array $folder_trail, array &$files): void
    {
        $entries = scandir($directory) ?: [];
        sort($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.') || str_starts_with($entry, '~$')) {
                continue;
            }

            $full_path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($full_path)) {
                $this->collectFiles($full_path, [...$folder_trail, $entry], $files);

                continue;
            }

            if (is_file($full_path) && strtolower(pathinfo($full_path, PATHINFO_EXTENSION)) === 'xlsx') {
                $files[] = ['path' => $full_path, 'folders' => $folder_trail];
            }
        }
    }

    /**
     * Finds-or-creates the chain of navigation "group" folders matching `$folders` (e.g.
     * `["97th Floor Development", "97th Dev"]`), reusing the seeded placeholder folders already
     * in the tree when their labels line up, and returns the id of the innermost one — the
     * parent every board found in that directory gets created under. Root-level files (an empty
     * `$folders`) return `null`, placing the board at the workspace root.
     *
     * @param  array<int, string>  $folders
     * @param  array<string, int>  $group_ids_by_path  memoizes folders already resolved this run, keyed by their path
     */
    private function resolveGroupPath(Workspace $workspace, array $folders, array &$group_ids_by_path): ?int
    {
        $parent_id = null;
        $path_key = '';

        foreach ($folders as $label) {
            $path_key .= '/'.$label;

            if (array_key_exists($path_key, $group_ids_by_path)) {
                $parent_id = $group_ids_by_path[$path_key];

                continue;
            }

            $group = $workspace->navigationItems()
                ->where('type', WorkspaceNavigationItem::TYPE_GROUP)
                ->where('parent_id', $parent_id)
                ->where('label', $label)
                ->first();

            if ($group === null) {
                $next_position = (int) $workspace->navigationItems()->where('parent_id', $parent_id)->max('position') + 1;

                $group = $workspace->navigationItems()->create([
                    'parent_id' => $parent_id,
                    'type' => WorkspaceNavigationItem::TYPE_GROUP,
                    'label' => $label,
                    'slug' => Str::slug($label),
                    'is_favorite' => false,
                    'position' => $next_position,
                ]);
            }

            $group_ids_by_path[$path_key] = $group->id;
            $parent_id = $group->id;
        }

        return $parent_id;
    }
}
