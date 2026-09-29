<?php

namespace App\Services\Board\AutomationActions;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\User;
use App\Services\Board\BoardAutomationActionOutcome;
use App\Services\Board\BoardAutomationActionRunner;
use Illuminate\Support\Facades\DB;

/**
 * The actions of {@see BoardAutomationActionRunner} that manage an item's subitems as a whole:
 * archive or delete every subitem, and turn a subitem into an item of its own. Creating subitems
 * (from names or from a list column) lives with the other create actions in the runner, this
 * trait only reads the list for it.
 */
trait RunsSubitemActions
{
    /** Most subitems one "create subitems" action makes. */
    private const MAX_CREATED_SUBITEMS = 50;

    /** Most subitems one "archive or delete every subitem" action changes. */
    private const MAX_CLEARED_SUBITEMS = 200;

    /**
     * The entries of a list column as names: the lines of a text, the tasks of a checklist, the
     * labels of tags, a dropdown or a status, the names of people.
     *
     * @return array<int, string>
     */
    private function listEntries(BoardColumn $column, mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if ($column->type === BoardColumn::TYPE_CHECKLIST && is_array($value)) {
            return array_values(array_map(fn ($task) => is_array($task) ? (string) ($task['text'] ?? '') : (string) $task, $value));
        }

        if (in_array($column->type, [BoardColumn::TYPE_TAGS, BoardColumn::TYPE_DROPDOWN, BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_PEOPLE], true)) {
            return array_values(array_map(fn ($entry) => $this->renderer->displayValue($column, $column->type === BoardColumn::TYPE_PEOPLE ? [$entry] : $entry), (array) $value));
        }

        return preg_split('/\r\n|\r|\n/', $this->renderer->displayValue($column, $value)) ?: [];
    }

    /**
     * Archives (`operation` `archive`) or deletes (`delete`) every subitem of the item.
     *
     * @param  array<string, mixed>  $params
     */
    private function clearSubitems(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $is_delete = ($params['operation'] ?? 'archive') === 'delete';
        $subitems = BoardItem::where('parent_id', $item->id)
            ->when(! $is_delete, fn ($query) => $query->where('is_archived', false))
            ->orderBy('position')
            ->limit(self::MAX_CLEARED_SUBITEMS)
            ->get();
        if ($subitems->isEmpty()) {
            return BoardAutomationActionOutcome::skipped($is_delete ? 'The item has no subitems to delete.' : 'The item has no subitems to archive.');
        }

        foreach ($subitems as $subitem) {
            if ($is_delete) {
                $subitem->loadMissing('childrenRecursive');
                $descendants = $this->flattenTree($subitem->childrenRecursive);
                foreach ($descendants as $descendant) {
                    $descendant->delete();
                }
                $subitem->delete();
                $this->journal->deleted($subitem, array_map(fn (BoardItem $descendant) => $descendant->id, $descendants));
                $this->automationService()->handleItemDeleted($subitem, $actor);
            } else {
                $subitem->update(['is_archived' => true]);
                $this->journal->archived($subitem);
                $this->automationService()->handleItemArchived($subitem, $actor);
            }
        }

        $count = $subitems->count();
        $verb = $is_delete ? 'deleted' : 'archived';
        $this->log($automation, $item, $actor, "{$verb} {$count} subitem(s) of \"{$item->name}\"");

        return BoardAutomationActionOutcome::success(ucfirst($verb)." {$count} subitem(s).");
    }

    /**
     * Turns a subitem into an item at the bottom of its parent's group, or of `target_group_id`.
     * The values of its subitem columns are copied into the item columns with the same label and
     * type, the rest stay with the old subitem columns, where no view shows them.
     *
     * @param  array<string, mixed>  $params
     */
    private function convertSubitem(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        if ($item->parent_id === null) {
            return BoardAutomationActionOutcome::skipped('Only a subitem can be turned into an item.');
        }

        $parent = BoardItem::find($item->parent_id);
        $group = ! empty($params['target_group_id'])
            ? BoardGroup::where('board_view_id', $automation->board_view_id)->find((int) $params['target_group_id'])
            : BoardGroup::find($parent?->group_id ?? $item->group_id);
        if (! $group) {
            return BoardAutomationActionOutcome::skipped('The group to put the new item in no longer exists.');
        }

        $columns = BoardColumn::where('board_view_id', $automation->board_view_id)->get();
        $item_columns = $columns->where('scope', BoardColumn::SCOPE_ITEM)->reject(fn (BoardColumn $column) => in_array($column->type, BoardColumn::READ_ONLY_TYPES, true));
        $mapped = [];
        foreach (BoardItemValue::where('item_id', $item->id)->get() as $value) {
            $source = $columns->firstWhere('id', $value->column_id);
            if (! $source || $source->scope !== BoardColumn::SCOPE_SUBITEM) {
                continue;
            }
            $target = $item_columns->first(fn (BoardColumn $column) => $column->type === $source->type && mb_strtolower(trim($column->label)) === mb_strtolower(trim($source->label)));
            if ($target) {
                $mapped[(string) $target->id] = $value->value;
            }
        }

        $parent_name = $parent?->name ?? 'its parent';
        DB::transaction(function () use ($item, $group) {
            $position = (int) BoardItem::where('group_id', $group->id)->whereNull('parent_id')->max('position') + 1;
            $item->update(['parent_id' => null, 'group_id' => $group->id, 'position' => $position]);
            $this->cascadeGroupToDescendants($item, $group->id);
        });
        $item->setRelation('group', $group);

        if ($mapped !== []) {
            $this->writeValues($item, $mapped, $actor);
        }
        $this->valueService()->assignAutoNumbers($item, BoardColumn::SCOPE_ITEM);

        $this->log($automation, $item, $actor, "turned the subitem \"{$item->name}\" of \"{$parent_name}\" into an item of \"{$group->name}\"");

        $copied = count($mapped);

        return BoardAutomationActionOutcome::success("Turned the subitem into an item of \"{$group->name}\", {$copied} value(s) copied. Undo cannot take this back.");
    }
}
