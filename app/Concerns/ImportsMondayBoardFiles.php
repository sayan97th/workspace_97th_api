<?php

namespace App\Concerns;

use App\Console\Commands\Board\ImportMondayBoardCommand;
use App\Console\Commands\Board\ImportMondayBoardTreeCommand;
use App\Models\BoardView;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\MondayBoardImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * File/CLI plumbing shared by the monday.com import commands — resolving paths, validating the
 * `--updates` flag, and the transactional "replace-or-create a board" flow both
 * {@see ImportMondayBoardCommand} (one file) and {@see ImportMondayBoardTreeCommand} (a whole
 * directory) drive against the same {@see MondayBoardImportService}. Only usable on an
 * `Illuminate\Console\Command` — it calls `$this->error()`/`$this->confirm()`.
 */
trait ImportsMondayBoardFiles
{
    /** @var array<int, string> */
    private array $validUpdatesModes = [
        MondayBoardImportService::UPDATES_MODE_SKIP,
        MondayBoardImportService::UPDATES_MODE_REDACT,
        MondayBoardImportService::UPDATES_MODE_RAW,
        MondayBoardImportService::UPDATES_MODE_EXCLUDE,
    ];

    private function isValidUpdatesMode(string $mode): bool
    {
        return in_array($mode, $this->validUpdatesModes, true);
    }

    private function resolveFilePath(string $file): ?string
    {
        $path = $this->resolvePath($file);

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

    private function resolveDirectoryPath(string $directory): ?string
    {
        $path = $this->resolvePath($directory);

        if (! is_dir($path)) {
            $this->error("Directory not found: {$path}");

            return null;
        }

        return $path;
    }

    private function resolvePath(string $path): string
    {
        $is_absolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $is_absolute ? $path : base_path($path);
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

    /**
     * @param  array<int, array{parent_post_id: string}>  $rows
     */
    private function countTopLevel(array $rows): int
    {
        return count(array_filter($rows, fn (array $row) => $row['parent_post_id'] === ''));
    }

    private function createBoard(Workspace $workspace, string $label, ?int $parent_id, ?string $description): WorkspaceNavigationItem
    {
        $next_position = (int) $workspace->navigationItems()->where('parent_id', $parent_id)->max('position') + 1;
        $label = mb_strlen($label) > 255 ? mb_substr($label, 0, 255) : $label;

        return $workspace->navigationItems()->create([
            'parent_id' => $parent_id,
            'type' => WorkspaceNavigationItem::TYPE_LEAF,
            'label' => $label,
            'description' => $description,
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

    /**
     * Finds-or-replaces the leaf board for a parsed spreadsheet and writes its groups/items/
     * subitems (and, when requested, its comment threads) inside one transaction.
     *
     * When `$search_globally` is true (the single-file command's behaviour), an existing board
     * is matched by label anywhere in the workspace and recreated in the same spot in the tree.
     * When false (the directory-tree command's behaviour), only a board directly under
     * `$parent_id` counts as a match, and the replacement always lands under `$parent_id` — the
     * directory layout wins regardless of where an old placeholder happened to live.
     *
     * When `$interactive` is false, a conflicting board is skipped (not replaced) unless
     * `$force` is set — there's no prompting a batch of dozens of files one at a time.
     *
     * @param  array<string, mixed>  $parsed  the array returned by {@see MondayBoardImportService::parse()}
     * @param  array<int, array{monday_item_id: string, author: string, created_at: string, body: string, post_id: string, parent_post_id: string}>  $update_rows
     * @return array{
     *     action: 'created'|'replaced'|'skipped',
     *     board: WorkspaceNavigationItem|null,
     *     summary: array{groups: int, items: int, subitems: int, unmatched_people: array<int, string>, item_ids_by_monday_id: array<string, int>}|null,
     *     updates_summary: array{comments: int, replies: int, skipped_no_item: int, skipped_secret: int, unmatched_authors: array<int, string>}|null,
     * }
     */
    private function importParsedBoard(
        MondayBoardImportService $importer,
        Workspace $workspace,
        array $parsed,
        array $update_rows,
        string $updates_mode,
        ?int $parent_id,
        bool $force,
        bool $search_globally,
        bool $interactive,
    ): array {
        $query = $workspace->navigationItems()
            ->where('type', WorkspaceNavigationItem::TYPE_LEAF)
            ->where('label', $parsed['title']);

        if (! $search_globally) {
            $query->where('parent_id', $parent_id);
        }

        $existing = $query->first();

        if ($existing !== null) {
            $replace = $force;

            if (! $replace && $interactive) {
                $replace = $this->confirm(
                    "A board named \"{$parsed['title']}\" already exists in \"{$workspace->name}\". Replace it?",
                    false,
                );
            }

            if (! $replace) {
                return ['action' => 'skipped', 'board' => null, 'summary' => null, 'updates_summary' => null];
            }
        }

        $target_parent_id = $parent_id;

        if ($search_globally && $existing !== null) {
            $target_parent_id = $existing->parent_id;
        }

        return DB::transaction(function () use ($importer, $workspace, $parsed, $update_rows, $updates_mode, $existing, $target_parent_id) {
            // A hard delete (not a soft delete) so the FK cascades actually clean up the old
            // board's views/groups/columns/items/values — a soft delete would leave them
            // orphaned under the trashed board.
            $existing?->forceDelete();

            $board = $this->createBoard($workspace, $parsed['title'], $target_parent_id, $parsed['description']);
            $view = $this->createPrimaryView($board);

            $summary = $importer->import($board, $view, $parsed);

            $updates_summary = $update_rows === []
                ? null
                : $importer->importUpdates($summary['item_ids_by_monday_id'], $update_rows, $updates_mode);

            return [
                'action' => $existing !== null ? 'replaced' : 'created',
                'board' => $board,
                'summary' => $summary,
                'updates_summary' => $updates_summary,
            ];
        });
    }
}
