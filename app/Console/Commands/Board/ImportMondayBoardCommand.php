<?php

namespace App\Console\Commands\Board;

use App\Concerns\ImportsMondayBoardFiles;
use App\Models\Workspace;
use App\Services\Board\MondayBoardImportService;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

// php artisan board:import-monday --force --updates=raw
class ImportMondayBoardCommand extends Command
{
    use ImportsMondayBoardFiles;

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
        if (! $this->isValidUpdatesMode($updates_mode)) {
            $this->error("Invalid --updates value \"{$updates_mode}\". Expected one of: ".implode(', ', $this->validUpdatesModes));

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
        $result = $this->importParsedBoard(
            importer: $this->importer,
            workspace: $workspace,
            parsed: $parsed,
            update_rows: $update_rows,
            updates_mode: $updates_mode,
            parent_id: null,
            force: (bool) $this->option('force'),
            search_globally: true,
            interactive: true,
        );

        if ($result['action'] === 'skipped') {
            $this->comment('Import cancelled.');

            return self::SUCCESS;
        }

        $summary = $result['summary'];
        $updates_summary = $result['updates_summary'];

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
}
