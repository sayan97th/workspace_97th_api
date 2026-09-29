<?php

namespace App\Support;

use App\Models\BoardAutomation;
use App\Models\BoardAutomationVersion;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Collection;

/**
 * The JSON file "Export" writes and "Import" reads: the automations of one table, and the columns
 * (with their labels) and groups they point at, so another table can map them by name with
 * {@see AutomationCopier}. Nothing secret is written, webhook tokens and run history stay behind.
 *
 * ```json
 * {"format": "workspace97.automations", "version": 1, "exported_at": "...",
 *  "source": {"board_id": 1, "board_name": "Marketing", "view_id": 3},
 *  "columns": [{"id": 12, "label": "Status", "type": "status", "scope": "item", "options": [{"id": "1", "label": "Done"}]}],
 *  "groups": [{"id": 5, "name": "To do"}],
 *  "automations": [{"name": "...", "trigger_type": "...", "actions": [...], ...}]}
 * ```
 */
final class AutomationTransfer
{
    public const FORMAT = 'workspace97.automations';

    public const VERSION = 1;

    /** Most automations one file may carry. */
    public const MAX_AUTOMATIONS = 100;

    /**
     * @param  Collection<int, BoardAutomation>  $automations  automations of one tab
     * @return array<string, mixed>
     */
    public static function export(Collection $automations, WorkspaceNavigationItem $board, BoardView $view): array
    {
        $columns = BoardColumn::where('board_view_id', $view->id)->orderBy('position')->get();
        $groups = BoardGroup::where('board_view_id', $view->id)->orderBy('position')->get();

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'source' => ['board_id' => $board->id, 'board_name' => $board->label, 'view_id' => $view->id],
            'columns' => $columns->map(fn (BoardColumn $column) => [
                'id' => $column->id,
                'label' => $column->label,
                'type' => $column->type,
                'scope' => $column->scope,
                'options' => collect($column->config['options'] ?? [])
                    ->filter(fn ($option) => is_array($option) && isset($option['id']))
                    ->map(fn (array $option) => ['id' => (string) $option['id'], 'label' => (string) ($option['label'] ?? '')])
                    ->values()
                    ->all(),
            ])->values()->all(),
            'groups' => $groups->map(fn (BoardGroup $group) => ['id' => $group->id, 'name' => $group->name])->values()->all(),
            'automations' => $automations->map(fn (BoardAutomation $automation) => [
                ...collect($automation->only(BoardAutomationVersion::SNAPSHOT_FIELDS))->all(),
                'actions' => $automation->resolvedActions(),
                'else_actions' => $automation->resolvedElseActions(),
            ])->values()->all(),
        ];
    }

    /**
     * The exported columns and groups as unsaved models keyed by their original id, what
     * {@see AutomationCopier} maps from.
     *
     * @param  array<string, mixed>  $file
     * @return array{columns: Collection<int, BoardColumn>, groups: Collection<int, BoardGroup>}
     */
    public static function sourceCatalog(array $file): array
    {
        $columns = collect((array) ($file['columns'] ?? []))
            ->filter(fn ($column) => is_array($column) && is_numeric($column['id'] ?? null))
            ->mapWithKeys(function (array $column) {
                $model = new BoardColumn;
                $model->forceFill([
                    'id' => (int) $column['id'],
                    'label' => (string) ($column['label'] ?? ''),
                    'type' => (string) ($column['type'] ?? ''),
                    'scope' => ($column['scope'] ?? 'item') === 'subitem' ? BoardColumn::SCOPE_SUBITEM : BoardColumn::SCOPE_ITEM,
                    'config' => ['options' => array_values(array_filter((array) ($column['options'] ?? []), 'is_array'))],
                ]);

                return [(int) $column['id'] => $model];
            });

        $groups = collect((array) ($file['groups'] ?? []))
            ->filter(fn ($group) => is_array($group) && is_numeric($group['id'] ?? null))
            ->mapWithKeys(function (array $group) {
                $model = new BoardGroup;
                $model->forceFill(['id' => (int) $group['id'], 'name' => (string) ($group['name'] ?? '')]);

                return [(int) $group['id'] => $model];
            });

        return ['columns' => $columns, 'groups' => $groups];
    }

    /**
     * One exported automation as an unsaved model of the original board, ready to be copied.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function automationFrom(array $entry, int $source_board_id): BoardAutomation
    {
        $automation = new BoardAutomation;
        $automation->forceFill([
            ...collect($entry)->only(BoardAutomationVersion::SNAPSHOT_FIELDS)->all(),
            'board_id' => $source_board_id,
        ]);

        return $automation;
    }
}
