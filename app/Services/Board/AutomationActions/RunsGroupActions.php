<?php

namespace App\Services\Board\AutomationActions;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Services\Board\BoardAutomationActionOutcome;
use App\Services\Board\BoardAutomationActionRunner;
use Illuminate\Support\Facades\DB;

/**
 * The group actions of {@see BoardAutomationActionRunner}: create, duplicate and archive a group
 * (a table of the tab). Paired with "Every time period" they make weekly sprints or monthly tables.
 * Each one works without an item when it names its group, or on the item's own group with
 * `from_item_group`.
 */
trait RunsGroupActions
{
    /** Group colors a new group cycles through, the same palette the board offers. */
    private const GROUP_COLORS = ['#579bfc', '#a25ddc', '#00c875', '#fdab3d', '#e2445c', '#66ccff', '#784bd1', '#ff642e'];

    /** Most items one "duplicate group" copies, so a runaway schedule cannot fill the board. */
    private const MAX_DUPLICATED_ITEMS = 500;

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function createGroup(BoardAutomation $automation, array $params, ?BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $name = $this->renderer->renderPlain($params['group_name'] ?? null, 'New group', $automation, $item, $actor, $context);
        $is_top = ($params['position'] ?? 'top') === 'top';
        $color = is_string($params['accent_color'] ?? null) && $params['accent_color'] !== ''
            ? $params['accent_color']
            : self::GROUP_COLORS[BoardGroup::where('board_view_id', $automation->board_view_id)->count() % count(self::GROUP_COLORS)];

        $group = DB::transaction(function () use ($automation, $name, $is_top, $color) {
            if ($is_top) {
                BoardGroup::where('board_view_id', $automation->board_view_id)->increment('position');
            }

            return BoardGroup::create([
                'board_id' => $automation->board_id,
                'board_view_id' => $automation->board_view_id,
                'name' => $name,
                'accent_color' => $color,
                'position' => $is_top ? 0 : (int) BoardGroup::where('board_view_id', $automation->board_view_id)->max('position') + 1,
            ]);
        });

        $this->log($automation, $item, $actor, "created the group \"{$group->name}\"");

        return BoardAutomationActionOutcome::success("Created the group \"{$group->name}\".");
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function duplicateGroup(BoardAutomation $automation, array $params, ?BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $source = $this->resolveGroupParam($automation, $params, $item, 'source_group_id');
        if (is_string($source)) {
            return BoardAutomationActionOutcome::skipped($source);
        }

        $name = $this->renderer->renderPlain($params['group_name'] ?? null, "{$source->name} copy", $automation, $item, $actor, $context);
        $with_items = (bool) ($params['with_items'] ?? false);

        [$copy, $copied_count] = DB::transaction(function () use ($source, $name, $with_items) {
            BoardGroup::where('board_view_id', $source->board_view_id)->where('position', '>', $source->position)->increment('position');

            $copy = $source->replicate(['is_archived', 'archived_at']);
            $copy->name = mb_substr($name, 0, 255);
            $copy->position = $source->position + 1;
            $copy->save();

            $copied_count = 0;
            if ($with_items) {
                $originals = BoardItem::where('group_id', $source->id)->whereNull('parent_id')->where('is_archived', false)
                    ->orderBy('position')->limit(self::MAX_DUPLICATED_ITEMS)->get();
                foreach ($originals as $original) {
                    $this->copySubtreeIntoGroup($original, $copy, null);
                    $copied_count++;
                }
            }

            return [$copy, $copied_count];
        });

        $this->log($automation, $item, $actor, "duplicated the group \"{$source->name}\" as \"{$copy->name}\"");

        return BoardAutomationActionOutcome::success(
            $with_items ? "Duplicated \"{$source->name}\" as \"{$copy->name}\" with {$copied_count} item(s)." : "Duplicated \"{$source->name}\" as \"{$copy->name}\"."
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function archiveGroup(BoardAutomation $automation, array $params, ?BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $group = $this->resolveGroupParam($automation, $params, $item, 'target_group_id');
        if (is_string($group)) {
            return BoardAutomationActionOutcome::skipped($group);
        }
        if ($group->is_archived) {
            return BoardAutomationActionOutcome::skipped("The group \"{$group->name}\" was already archived.");
        }

        $group->update(['is_archived' => true, 'archived_at' => now()]);
        $this->log($automation, $item, $actor, "archived the group \"{$group->name}\"");

        // The item went with its group, so actions after this one have nothing left to change.
        $is_items_group = $item !== null && $item->group_id === $group->id;

        return BoardAutomationActionOutcome::success("Archived the group \"{$group->name}\".", stops_chain: $is_items_group);
    }

    /**
     * The group a group action works on: the item's own group with `from_item_group`, otherwise the
     * group id in `$param`, which must belong to the automation's tab.
     *
     * @param  array<string, mixed>  $params
     */
    private function resolveGroupParam(BoardAutomation $automation, array $params, ?BoardItem $item, string $param): BoardGroup|string
    {
        if (! empty($params['from_item_group'])) {
            if ($item === null) {
                return 'There was no item, so there was no group of the item to use.';
            }
            $group = $item->group ?? BoardGroup::find($item->group_id);

            return $group ?? 'The item has no group.';
        }

        return BoardGroup::where('board_view_id', $automation->board_view_id)->find((int) ($params[$param] ?? 0))
            ?? 'The group no longer exists.';
    }

    /**
     * Deep-copies an item and its subitems into `$group`, keeping names (the new group's name
     * already marks it as a copy), values and positions, with fresh auto numbers.
     */
    private function copySubtreeIntoGroup(BoardItem $original, BoardGroup $group, ?int $parent_id): void
    {
        $original->loadMissing(['values', 'childrenRecursive']);

        $copy = BoardItem::create([
            'board_id' => $original->board_id,
            'group_id' => $group->id,
            'parent_id' => $parent_id,
            'name' => $original->name,
            'description' => $original->description,
            'position' => $original->position,
            'is_priority' => $original->is_priority,
            'created_by_id' => $original->created_by_id,
        ]);
        $copy->setRelation('group', $group);

        $scope = $parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;
        $auto_number_column_ids = BoardColumn::where('board_view_id', $group->board_view_id)
            ->where('type', BoardColumn::TYPE_AUTO_NUMBER)
            ->pluck('id');

        foreach ($original->values as $value) {
            if (! $auto_number_column_ids->contains($value->column_id)) {
                $copy->values()->create(['column_id' => $value->column_id, 'value' => $value->value]);
            }
        }
        $this->valueService()->assignAutoNumbers($copy, $scope);

        foreach ($original->childrenRecursive->where('is_archived', false) as $child) {
            $this->copySubtreeIntoGroup($child, $group, $copy->id);
        }
    }
}
