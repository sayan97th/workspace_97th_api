<?php

namespace App\Services\Board\AutomationActions;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationActionOutcome;
use App\Services\Board\BoardAutomationActionRunner;
use App\Support\BoardEditGate;
use Illuminate\Support\Facades\DB;

/**
 * The actions of {@see BoardAutomationActionRunner} that reach past one cell: rename the item from
 * a template, add or remove single values of a multi value column, change the items a connect
 * boards column links to, and apply one change to every item of a group.
 */
trait RunsBulkActions
{
    /** Most linked items one "update connected items" changes. */
    private const MAX_CONNECTED_UPDATES = 50;

    /** Most items one group wide action touches, so a runaway rule cannot rewrite a whole board. */
    private const MAX_GROUP_ITEMS = 500;

    /** Column types whose value is a list that single values can be added to or removed from. */
    private const MULTI_VALUE_TYPES = [BoardColumn::TYPE_DROPDOWN, BoardColumn::TYPE_TAGS, BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_VOTE];

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function renameItem(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $name = mb_substr(trim($this->renderer->renderPlain($params['name_template'] ?? null, '', $automation, $item, $actor, $context)), 0, 255);
        if ($name === '') {
            return BoardAutomationActionOutcome::skipped('The new name came out empty.');
        }

        $old_name = (string) $item->name;
        if ($old_name === $name) {
            return BoardAutomationActionOutcome::skipped('The item already had that name.');
        }

        $item->update(['name' => $name]);
        $this->journal->renamed($item, $old_name, $name);
        $this->log($automation, $item, $actor, "renamed \"{$old_name}\" to \"{$name}\"");
        $this->automationService()->handleNameChanged($item, $old_name, $actor);

        return BoardAutomationActionOutcome::success("Renamed the item to \"{$name}\".");
    }

    /**
     * Adds or removes single values of a dropdown, tags, people or vote column, keeping the rest.
     *
     * @param  array<string, mixed>  $params
     */
    private function changeValues(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, self::MULTI_VALUE_TYPES);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $is_people = in_array($column->type, [BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_VOTE], true);
        $wanted = [];
        foreach ((array) ($params['values'] ?? []) as $entry) {
            $entry = (string) $entry;
            $resolved = match (true) {
                $is_people && $entry === '__actor__' => $actor?->id,
                $is_people && $entry === '__creator__' => $subject->created_by_id,
                default => $entry,
            };
            if ($resolved !== null && $resolved !== '') {
                $wanted[] = (string) $resolved;
            }
        }
        if ($is_people) {
            $wanted = User::whereIn('id', array_filter($wanted, 'is_numeric'))->where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
        } else {
            $option_ids = collect($column->config['options'] ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
            $wanted = array_values(array_intersect($wanted, $option_ids));
        }
        if ($wanted === []) {
            return BoardAutomationActionOutcome::skipped('There was no value to '.(($params['mode'] ?? 'add') === 'remove' ? 'remove' : 'add').'.');
        }

        $current = array_values(array_map('strval', array_filter((array) ($this->currentValue($subject, $column) ?? []), 'is_scalar')));
        $is_remove = ($params['mode'] ?? 'add') === 'remove';
        $next = $is_remove ? array_values(array_diff($current, $wanted)) : array_values(array_unique([...$current, ...$wanted]));
        if ($next === $current) {
            return BoardAutomationActionOutcome::skipped($is_remove ? "\"{$column->label}\" held none of those values." : "\"{$column->label}\" already held those values.");
        }

        $this->writeValue($subject, $column, $next, $actor);
        $changed = $this->renderer->displayValue($column, $is_remove ? array_values(array_intersect($current, $wanted)) : array_values(array_diff($wanted, $current)));
        $verb = $is_remove ? 'removed' : 'added';
        $this->log($automation, $subject, $actor, "{$verb} \"{$changed}\" in \"{$column->label}\" on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success(ucfirst($verb)." \"{$changed}\" ".($is_remove ? 'from' : 'to')." \"{$column->label}\".");
    }

    /**
     * Sets a column of the connected board on every item the connect boards column links to. The
     * writes go through the value service on the other board, so its automations react in turn.
     *
     * @param  array<string, mixed>  $params
     */
    private function updateConnectedItems(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, ['target_column_id' => $params['connect_column_id'] ?? null], $item, BoardColumn::TYPE_CONNECT_BOARD);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$connect_column, $subject] = $target;

        $linked_board = WorkspaceNavigationItem::boards()->notArchived()->find((int) ($connect_column->config['linked_board_id'] ?? 0));
        if (! $linked_board) {
            return BoardAutomationActionOutcome::skipped("\"{$connect_column->label}\" is not connected to a board any more.");
        }
        $owner = $automation->responsibleUser();
        if (! $owner || ! BoardEditGate::allowsContent($linked_board, $owner)) {
            return BoardAutomationActionOutcome::failed("The automation owner can no longer edit \"{$linked_board->label}\".");
        }

        $column = BoardColumn::where('board_id', $linked_board->id)->whereNotIn('type', BoardColumn::READ_ONLY_TYPES)->find((int) ($params['linked_column_id'] ?? 0));
        if (! $column) {
            return BoardAutomationActionOutcome::skipped('The column to update on the connected board no longer exists.');
        }

        $linked_ids = array_map('intval', array_filter((array) ($this->currentValue($subject, $connect_column) ?? []), 'is_numeric'));
        if ($linked_ids === []) {
            return BoardAutomationActionOutcome::skipped('The item is not connected to any item.');
        }

        $value = $params['value'] ?? null;
        $linked_items = BoardItem::with(['group', 'board'])
            ->where('board_id', $linked_board->id)
            ->whereIn('id', $linked_ids)
            ->where('is_archived', false)
            ->whereHas('group', fn ($query) => $query->where('board_view_id', $column->board_view_id))
            ->limit(self::MAX_CONNECTED_UPDATES)
            ->get();

        $changed = 0;
        foreach ($linked_items as $linked) {
            if ($this->valuesAreEqual($this->currentValue($linked, $column), $value)) {
                continue;
            }
            $this->writeValue($linked, $column, $value, $actor);
            $changed++;
        }

        if ($changed === 0) {
            return BoardAutomationActionOutcome::skipped("The connected items already had that \"{$column->label}\".");
        }

        $shown = $this->renderer->displayValue($column, $value);
        $this->log($automation, $subject, $actor, "set \"{$column->label}\" to \"{$shown}\" on {$changed} connected item(s) of \"{$linked_board->label}\"");

        return BoardAutomationActionOutcome::success("Set \"{$column->label}\" to \"{$shown}\" on {$changed} connected item(s).");
    }

    /**
     * One change applied to every live top-level item of a group, up to {@see self::MAX_GROUP_ITEMS}.
     *
     * @param  array<string, mixed>  $params
     */
    private function groupItems(BoardAutomation $automation, array $params, ?BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $group = $this->resolveGroupParam($automation, $params, $item, 'target_group_id');
        if (is_string($group)) {
            return BoardAutomationActionOutcome::skipped($group);
        }

        $operation = (string) ($params['operation'] ?? '');
        $column = null;
        if (in_array($operation, ['set_column_value', 'clear_column'], true)) {
            $column = BoardColumn::where('board_view_id', $automation->board_view_id)
                ->where('scope', BoardColumn::SCOPE_ITEM)
                ->whereNotIn('type', BoardColumn::READ_ONLY_TYPES)
                ->find((int) ($params['target_column_id'] ?? 0));
            if (! $column) {
                return BoardAutomationActionOutcome::skipped('The column to update no longer exists.');
            }
        }
        $destination = null;
        if ($operation === 'move_to_group') {
            $destination = BoardGroup::where('board_view_id', $automation->board_view_id)->where('is_archived', false)->find((int) ($params['destination_group_id'] ?? 0));
            if (! $destination) {
                return BoardAutomationActionOutcome::skipped('The group to move the items to no longer exists.');
            }
            if ($destination->id === $group->id) {
                return BoardAutomationActionOutcome::skipped('The items are already in that group.');
            }
        }

        $items = BoardItem::with('group')
            ->where('group_id', $group->id)
            ->whereNull('parent_id')
            ->where('is_archived', false)
            ->orderBy('position')
            ->limit(self::MAX_GROUP_ITEMS)
            ->get();

        $changed = 0;
        foreach ($items as $group_item) {
            $did_change = match ($operation) {
                'set_column_value' => $this->groupSetValue($group_item, $column, $params['value'] ?? null, $actor),
                'clear_column' => $this->groupSetValue($group_item, $column, null, $actor),
                'archive' => $this->groupArchive($group_item, $actor),
                'move_to_group' => $this->groupMove($group_item, $destination, $actor),
                default => false,
            };
            if ($did_change) {
                $changed++;
            }
        }

        if ($changed === 0) {
            return BoardAutomationActionOutcome::skipped("Nothing in \"{$group->name}\" needed changing.");
        }

        $what = match ($operation) {
            'set_column_value' => "set \"{$column->label}\" to \"{$this->renderer->displayValue($column, $params['value'] ?? null)}\" on",
            'clear_column' => "cleared \"{$column->label}\" on",
            'archive' => 'archived',
            default => "moved to \"{$destination->name}\"",
        };
        $this->log($automation, $item, $actor, "{$what} {$changed} item(s) of \"{$group->name}\"");

        return BoardAutomationActionOutcome::success(ucfirst($what)." {$changed} item(s) of \"{$group->name}\".", stops_chain: $item !== null && $item->group_id === $group->id && in_array($operation, ['archive', 'move_to_group'], true) && $item->parent_id === null);
    }

    private function groupSetValue(BoardItem $item, BoardColumn $column, mixed $value, ?User $actor): bool
    {
        if ($this->valuesAreEqual($this->currentValue($item, $column), $value)) {
            return false;
        }
        $this->writeValue($item, $column, $value, $actor);

        return true;
    }

    private function groupArchive(BoardItem $item, ?User $actor): bool
    {
        $item->update(['is_archived' => true]);
        $this->journal->archived($item);
        $this->automationService()->handleItemArchived($item, $actor);

        return true;
    }

    private function groupMove(BoardItem $item, BoardGroup $destination, ?User $actor): bool
    {
        $from_group_id = $item->group_id;
        DB::transaction(function () use ($item, $destination) {
            $position = (int) BoardItem::where('group_id', $destination->id)->whereNull('parent_id')->max('position') + 1;
            $item->update(['group_id' => $destination->id, 'position' => $position]);
            $this->cascadeGroupToDescendants($item, $destination->id);
        });
        $item->setRelation('group', $destination);
        $this->journal->moved($item, $from_group_id, $destination->id);
        $this->automationService()->handleItemMoved($item, $from_group_id, $actor);

        return true;
    }
}
