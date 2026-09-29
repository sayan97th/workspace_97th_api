<?php

namespace App\Services\Board;

use App\Jobs\SendEmailJob;
use App\Mail\Automations\AutomationEmail;
use App\Models\BoardActivityLog;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationSetting;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardItemValue;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\AutomationActions\RunsBulkActions;
use App\Services\Board\AutomationActions\RunsColumnActions;
use App\Services\Board\AutomationActions\RunsDateActions;
use App\Services\Board\AutomationActions\RunsDigestActions;
use App\Services\Board\AutomationActions\RunsFlowActions;
use App\Services\Board\AutomationActions\RunsGroupActions;
use App\Services\Board\AutomationActions\RunsOutboundActions;
use App\Services\Board\AutomationActions\RunsSubitemActions;
use App\Services\Board\AutomationActions\RunsSubscriberActions;
use App\Services\Notification\NotificationService;
use App\Services\Slack\SlackNotifier;
use App\Support\BoardEditGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Performs one action of an automation against one item and reports what came of it, see
 * {@see BoardAutomation}'s constants for what each action type does with its params.
 *
 * Column writes go through {@see BoardItemValueService::sync()}, the same path an inline cell
 * edit takes, so they get the item activity entry and set off the automations that watch the
 * column in turn ({@see BoardAutomationService} stops a chain that runs too deep). Moving,
 * archiving, deleting and creating items, and posting updates, report back to
 * {@see BoardAutomationService} the same way.
 *
 * A column action on a subitem whose column belongs to items (not subitems) acts on the parent
 * item instead, which is how "when a subitem's status changes, change the parent's status" works.
 *
 * During a test run ({@see AutomationRunContext::isDryRun()}) the changes inside the app still
 * happen, in a transaction the caller rolls back, but nothing leaves the app: notifications,
 * emails, Slack messages and webhooks only report who they would have reached.
 *
 * Date, group, column, flow (round robin, cascades, checklists, dependents), bulk (rename, add or
 * remove values, connected items, whole groups) and outbound actions live in the traits under
 * `AutomationActions`. The "wait" action is handled by {@see BoardAutomationService} itself, since
 * it stops the branch.
 *
 * Every change inside the app is written down in the run's journal ({@see BoardAutomationRunJournal}),
 * cell values by {@see BoardItemValueService::sync()} and the rest here, so the run can be undone.
 */
class BoardAutomationActionRunner
{
    use RunsBulkActions, RunsColumnActions, RunsDateActions, RunsDigestActions, RunsFlowActions, RunsGroupActions, RunsOutboundActions, RunsSubitemActions, RunsSubscriberActions;

    public function __construct(
        private readonly NotificationService $notification_service,
        private readonly BoardActivityLogger $activity_logger,
        private readonly SlackNotifier $slack_notifier,
        private readonly BoardAutomationMessageRenderer $renderer,
        private readonly BoardItemTransferService $transfer_service,
        private readonly AutomationRunContext $run_context,
        private readonly BoardAutomationRunJournal $journal,
        private readonly BoardFormulaResolver $formula_resolver,
        private readonly MirrorColumnResolver $mirror_resolver,
        private readonly AutomationDynamicValueResolver $dynamic_values,
        private readonly AutomationConditionEvaluator $condition_evaluator,
    ) {}

    /**
     * @param  array{type: string, params: array<string, mixed>}  $action
     * @param  array<string, mixed>  $context  what the trigger knows, see {@see BoardAutomationMessageRenderer::render()}
     * @param  string  $action_key  `then.0`, `else.1`: where the action sits, for what it remembers between runs
     */
    public function run(BoardAutomation $automation, array $action, ?BoardItem $item, ?User $actor, array $context = [], string $action_key = 'then.0'): BoardAutomationActionOutcome
    {
        $params = $action['params'];
        $type = $action['type'];

        if ($item === null && ! in_array($type, BoardAutomation::itemlessActions(), true)) {
            return BoardAutomationActionOutcome::skipped('There was no item to act on. Add a "create an item" action before this one.');
        }

        if (in_array($type, BoardAutomation::communicationActions(), true)) {
            return $this->runCommunicationAction($automation, $type, $params, $item, $actor, $context);
        }

        return match ($type) {
            BoardAutomation::ACTION_MOVE_TO_GROUP => $this->moveToGroup($automation, $params, $item, $actor),
            BoardAutomation::ACTION_MOVE_TO_BOARD => $this->moveToBoard($automation, $params, $item, $actor),
            BoardAutomation::ACTION_NOTIFY_PERSON => $this->notifyPerson($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_ARCHIVE_ITEM => $this->archiveItem($automation, $item, $actor),
            BoardAutomation::ACTION_DELETE_ITEM => $this->deleteItem($automation, $item, $actor),
            BoardAutomation::ACTION_DUPLICATE_ITEM => $this->duplicateItem($automation, $params, $item, $actor),
            BoardAutomation::ACTION_SET_COLUMN_VALUE => $this->setColumnValue($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_CLEAR_COLUMN => $this->clearColumn($automation, $params, $item, $actor),
            BoardAutomation::ACTION_ASSIGN_PERSON => $this->assignPerson($automation, $params, $item, $actor),
            BoardAutomation::ACTION_UNASSIGN_PEOPLE => $this->unassignPeople($automation, $params, $item, $actor),
            BoardAutomation::ACTION_SET_DATE => $this->setDate($automation, $params, $item, $actor),
            BoardAutomation::ACTION_ADJUST_NUMBER => $this->adjustNumber($automation, $params, $item, $actor),
            BoardAutomation::ACTION_CREATE_ITEM => $this->createItem($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_CREATE_SUBITEM => $this->createSubitems($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_POST_UPDATE => $this->postUpdate($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_SHIFT_DATE => $this->shiftDate($automation, $params, $item, $actor),
            BoardAutomation::ACTION_SET_DATE_FROM_COLUMN => $this->setDateFromColumn($automation, $params, $item, $actor),
            BoardAutomation::ACTION_ENSURE_DATE_AFTER => $this->ensureDateAfter($automation, $params, $item, $actor),
            BoardAutomation::ACTION_SET_TIMELINE => $this->setTimeline($automation, $params, $item, $actor),
            BoardAutomation::ACTION_CREATE_GROUP => $this->createGroup($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_DUPLICATE_GROUP => $this->duplicateGroup($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_ARCHIVE_GROUP => $this->archiveGroup($automation, $params, $item, $actor),
            BoardAutomation::ACTION_COPY_COLUMN_VALUE => $this->copyColumnValue($automation, $params, $item, $actor),
            BoardAutomation::ACTION_TIME_TRACKING => $this->toggleTimeTracking($automation, $params, $item, $actor),
            BoardAutomation::ACTION_CONNECT_ITEMS => $this->connectItems($automation, $params, $item, $actor),
            BoardAutomation::ACTION_NOTIFY_TEAM => $this->notifyTeam($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_SEND_WEBHOOK => $this->sendWebhook($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_ASSIGN_ROUND_ROBIN => $this->assignRoundRobin($automation, $params, $item, $actor, $action_key),
            BoardAutomation::ACTION_SET_SUBITEMS_VALUE => $this->setSubitemsValue($automation, $params, $item, $actor),
            BoardAutomation::ACTION_SET_PARENT_VALUE => $this->setParentValue($automation, $params, $item, $actor),
            BoardAutomation::ACTION_ADD_CHECKLIST_ITEMS => $this->addChecklistItems($automation, $params, $item, $actor),
            BoardAutomation::ACTION_SHIFT_DEPENDENTS => $this->shiftDependents($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_RENAME_ITEM => $this->renameItem($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_CHANGE_VALUES => $this->changeValues($automation, $params, $item, $actor),
            BoardAutomation::ACTION_UPDATE_CONNECTED_ITEMS => $this->updateConnectedItems($automation, $params, $item, $actor),
            BoardAutomation::ACTION_GROUP_ITEMS => $this->groupItems($automation, $params, $item, $actor),
            BoardAutomation::ACTION_SUBSCRIBE_PEOPLE => $this->subscribePeople($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_UNSUBSCRIBE_PEOPLE => $this->unsubscribePeople($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_NOTIFY_SUBSCRIBERS => $this->notifySubscribers($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_CLEAR_SUBITEMS => $this->clearSubitems($automation, $params, $item, $actor),
            BoardAutomation::ACTION_CONVERT_SUBITEM => $this->convertSubitem($automation, $params, $item, $actor),
            BoardAutomation::ACTION_SEND_DIGEST => $this->sendDigest($automation, $params, $item, $actor, $context),
            BoardAutomation::ACTION_WAIT => BoardAutomationActionOutcome::skipped('A wait is handled by the automation itself.'),
            default => BoardAutomationActionOutcome::skipped('This action type is not supported.'),
        };
    }

    // ── Moving, archiving, deleting, duplicating ──────────────────────────────

    /**
     * @param  array<string, mixed>  $params
     */
    private function moveToGroup(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target_group = BoardGroup::where('board_view_id', $automation->board_view_id)->find((int) ($params['target_group_id'] ?? 0));
        if (! $target_group) {
            return BoardAutomationActionOutcome::skipped('The target group no longer exists.');
        }
        if ($item->parent_id !== null) {
            return BoardAutomationActionOutcome::skipped('Subitems move with their parent item.');
        }

        $from_group_id = $item->group_id;
        if ($from_group_id === $target_group->id) {
            return BoardAutomationActionOutcome::skipped('The item was already in the target group.');
        }

        DB::transaction(function () use ($item, $target_group) {
            $position = (int) BoardItem::where('group_id', $target_group->id)->whereNull('parent_id')->max('position') + 1;
            $item->update(['group_id' => $target_group->id, 'position' => $position]);
            $this->cascadeGroupToDescendants($item, $target_group->id);
        });
        $item->setRelation('group', $target_group);
        $this->journal->moved($item, $from_group_id, $target_group->id);

        $this->log($automation, $item, $actor, "moved \"{$item->name}\" to \"{$target_group->name}\"");
        $this->automationService()->handleItemMoved($item, $from_group_id, $actor);

        return BoardAutomationActionOutcome::success("Moved the item to \"{$target_group->name}\".");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function moveToBoard(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        if ($item->parent_id !== null) {
            return BoardAutomationActionOutcome::skipped('Only top-level items can be moved to another board.');
        }

        $target_group = $this->resolveCrossBoardGroup($automation, $params);
        if (is_string($target_group)) {
            return BoardAutomationActionOutcome::failed($target_group);
        }

        $item_name = $item->name;
        $from_board_id = $item->board_id;
        $moved = $this->transfer_service->moveToBoard($item, $target_group);
        $this->log($automation, null, $actor, "moved \"{$item_name}\" to the board \"{$target_group->board->label}\"");
        $this->automationService()->handleItemMovedToBoard($moved->load('group'), $from_board_id, $actor);

        return BoardAutomationActionOutcome::success("Moved the item to \"{$target_group->name}\" on \"{$target_group->board->label}\".", stops_chain: true);
    }

    private function archiveItem(BoardAutomation $automation, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        if ($item->is_archived) {
            return BoardAutomationActionOutcome::skipped('The item was already archived.');
        }

        $item->update(['is_archived' => true]);
        $this->journal->archived($item);
        $this->log($automation, $item, $actor, "archived \"{$item->name}\"");
        $this->automationService()->handleItemArchived($item, $actor);

        return BoardAutomationActionOutcome::success('Archived the item.', stops_chain: true);
    }

    private function deleteItem(BoardAutomation $automation, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $item->loadMissing('childrenRecursive');
        $descendants = $this->flattenTree($item->childrenRecursive);
        foreach ($descendants as $descendant) {
            $descendant->delete();
        }
        $item->delete();
        $this->journal->deleted($item, array_map(fn (BoardItem $descendant) => $descendant->id, $descendants));

        $this->log($automation, $item, $actor, "deleted \"{$item->name}\"");
        $this->automationService()->handleItemDeleted($item, $actor);

        return BoardAutomationActionOutcome::success('Deleted the item.', stops_chain: true);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function duplicateItem(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $with_subitems = (bool) ($params['with_subitems'] ?? true);

        $copy = DB::transaction(function () use ($item, $with_subitems) {
            $position = (int) BoardItem::where('group_id', $item->group_id)->where('parent_id', $item->parent_id)->max('position') + 1;

            return $this->copySubtree($item, $item->parent_id, $position, $with_subitems, true);
        });

        $this->journal->created($copy);
        $this->log($automation, $item, $actor, "duplicated \"{$item->name}\"");

        return BoardAutomationActionOutcome::success("Duplicated the item as \"{$copy->name}\".");
    }

    // ── Column values ─────────────────────────────────────────────────────────

    /**
     * Writes `value`, or with `dynamic_value` what it stands for on this run, see
     * {@see AutomationDynamicValueResolver}.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function setColumnValue(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor, array $context = []): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $value = $params['value'] ?? null;
        if (AutomationDynamicValueResolver::isDynamic($params['dynamic_value'] ?? null)) {
            [$is_resolved, $value] = $this->dynamic_values->forColumn($params['dynamic_value'], $column, $subject, $actor, $automation, $context);
            if (! $is_resolved) {
                return BoardAutomationActionOutcome::skipped("Found no value to write into \"{$column->label}\" on this run.");
            }
        }
        if ($this->valuesAreEqual($this->currentValue($subject, $column), $value)) {
            return BoardAutomationActionOutcome::skipped("\"{$column->label}\" already had that value.");
        }

        $this->writeValue($subject, $column, $value, $actor);
        $shown = $this->renderer->displayValue($column, $value);
        $this->log($automation, $subject, $actor, "set \"{$column->label}\" to \"{$shown}\" on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Set \"{$column->label}\" to \"{$shown}\".");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function clearColumn(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        if ($this->valuesAreEqual($this->currentValue($subject, $column), null)) {
            return BoardAutomationActionOutcome::skipped("\"{$column->label}\" was already empty.");
        }

        $this->writeValue($subject, $column, null, $actor);
        $this->log($automation, $subject, $actor, "cleared \"{$column->label}\" on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Cleared \"{$column->label}\".");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function assignPerson(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, BoardColumn::TYPE_PEOPLE);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $user_id = match ($params['assign_mode'] ?? 'user') {
            'creator' => $subject->created_by_id,
            'actor' => $actor?->id,
            default => $params['user_id'] ?? null,
        };
        $person = $user_id ? User::where('is_active', true)->find($user_id) : null;
        if (! $person) {
            return BoardAutomationActionOutcome::skipped('Found nobody to assign.');
        }

        $current_ids = $this->peopleIds($this->currentValue($subject, $column));
        if (in_array((string) $person->id, $current_ids, true) && (empty($params['replace']) || count($current_ids) === 1)) {
            return BoardAutomationActionOutcome::skipped("{$person->full_name} was already assigned.");
        }

        $next_ids = ! empty($params['replace']) ? [(string) $person->id] : array_values(array_unique([...$current_ids, (string) $person->id]));
        $this->writeValue($subject, $column, $next_ids, $actor);
        $this->log($automation, $subject, $actor, "assigned {$person->full_name} to \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Assigned {$person->full_name} in \"{$column->label}\".");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function unassignPeople(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, BoardColumn::TYPE_PEOPLE);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $current_ids = $this->peopleIds($this->currentValue($subject, $column));
        $user_id = isset($params['user_id']) ? (string) $params['user_id'] : null;
        $next_ids = $user_id === null ? [] : array_values(array_filter($current_ids, fn (string $id) => $id !== $user_id));

        if (count($next_ids) === count($current_ids)) {
            return BoardAutomationActionOutcome::skipped('There was nobody to remove.');
        }

        $this->writeValue($subject, $column, $next_ids, $actor);
        $this->log($automation, $subject, $actor, "removed people from \"{$column->label}\" on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Removed people from \"{$column->label}\".");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function setDate(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, BoardColumn::TYPE_DATE);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $date = empty($params['use_working_days'])
            ? Carbon::today()->addDays((int) ($params['offset_days'] ?? 0))->toDateString()
            : BoardAutomationSetting::forBoard($automation->board_id)->calendar()->addWorkingDays(CarbonImmutable::today(), (int) ($params['offset_days'] ?? 0))->toDateString();
        if ($this->valuesAreEqual($this->currentValue($subject, $column), $date)) {
            return BoardAutomationActionOutcome::skipped("\"{$column->label}\" was already {$date}.");
        }

        $this->writeValue($subject, $column, $date, $actor);
        $this->log($automation, $subject, $actor, "set \"{$column->label}\" to {$date} on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Set \"{$column->label}\" to {$date}.");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function adjustNumber(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $target = $this->resolveColumnTarget($automation, $params, $item, BoardColumn::TYPE_NUMBER);
        if (is_string($target)) {
            return BoardAutomationActionOutcome::skipped($target);
        }
        [$column, $subject] = $target;

        $amount = (float) ($params['amount'] ?? 0);
        if ($amount == 0.0) {
            return BoardAutomationActionOutcome::skipped('The amount to add is zero.');
        }

        $current = $this->currentValue($subject, $column);
        $next = (is_numeric($current) ? (float) $current : 0.0) + $amount;
        $next = floor($next) === $next ? (int) $next : round($next, 6);

        $this->writeValue($subject, $column, $next, $actor);
        $this->log($automation, $subject, $actor, "changed \"{$column->label}\" to {$next} on \"{$subject->name}\"");

        return BoardAutomationActionOutcome::success("Changed \"{$column->label}\" to {$next}.");
    }

    // ── Creating items and updates ────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function createItem(BoardAutomation $automation, array $params, ?BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $is_cross_board = ! empty($params['target_board_id']) && (int) $params['target_board_id'] !== $automation->board_id;
        $target_group = $is_cross_board
            ? $this->resolveCrossBoardGroup($automation, $params)
            : BoardGroup::where('board_view_id', $automation->board_view_id)->find((int) ($params['target_group_id'] ?? 0));

        if (is_string($target_group)) {
            return BoardAutomationActionOutcome::failed($target_group);
        }
        if (! $target_group) {
            return BoardAutomationActionOutcome::skipped('The target group no longer exists.');
        }

        $name = $this->renderer->renderItemName($params['item_name'] ?? null, $automation, $item, $actor, $context);
        $created_by_id = $actor?->id ?? $automation->responsibleUser()?->id;
        $created = $this->transfer_service->createCopyInGroup($item, $target_group, $name, (bool) ($params['copy_values'] ?? false), $created_by_id);

        $this->journal->created($created);

        if (! $is_cross_board && ! empty($params['field_mappings'])) {
            $this->applyFieldMappings($automation, (array) $params['field_mappings'], $created, $item, $actor, $context);
        }
        $linked_note = $is_cross_board && $item !== null && ! empty($params['link_column_id']) ? $this->linkCreatedItem($automation, (int) $params['link_column_id'], $item, $created, $actor) : '';

        $where = $is_cross_board ? "\"{$target_group->name}\" on \"{$target_group->board->label}\"" : "\"{$target_group->name}\"";
        $this->log($automation, $item, $actor, "created \"{$created->name}\" in {$where}");
        $this->automationService()->handleItemCreated($created, $actor);

        return BoardAutomationActionOutcome::success("Created \"{$created->name}\" in {$where}.{$linked_note}", created_item: $created);
    }

    /**
     * Adds an item just created on another board to the triggering item's connect boards column,
     * when that column links to the board the item was created on.
     */
    private function linkCreatedItem(BoardAutomation $automation, int $link_column_id, BoardItem $item, BoardItem $created, ?User $actor): string
    {
        $target = $this->resolveColumnTarget($automation, ['target_column_id' => $link_column_id], $item, BoardColumn::TYPE_CONNECT_BOARD);
        if (is_string($target)) {
            return ' It was not connected: '.lcfirst($target);
        }
        [$column, $subject] = $target;
        if ((int) ($column->config['linked_board_id'] ?? 0) !== $created->board_id) {
            return " It was not connected, \"{$column->label}\" links to another board.";
        }

        $current_ids = array_map('strval', array_filter((array) $this->currentValue($subject, $column), 'is_scalar'));
        $this->writeValue($subject, $column, array_values(array_unique([...$current_ids, (string) $created->id])), $actor);

        return " Connected it in \"{$column->label}\".";
    }

    /**
     * Fills the columns of an item a "create an item" action just made from text templates, such as
     * `{payload.email}` for a webhook. Each rendered text is read the way an imported spreadsheet
     * cell is, so "Done" becomes the Done label and "2026-10-05" a date.
     *
     * @param  array<int, mixed>  $mappings  `[{column_id, source}]`
     * @param  array<string, mixed>  $context
     */
    private function applyFieldMappings(BoardAutomation $automation, array $mappings, BoardItem $created, ?BoardItem $item, ?User $actor, array $context): void
    {
        $columns = BoardColumn::where('board_view_id', $automation->board_view_id)
            ->where('scope', BoardColumn::SCOPE_ITEM)
            ->whereNotIn('type', BoardColumn::READ_ONLY_TYPES)
            ->get()
            ->keyBy('id');
        $caster = new ImportedCellValueCaster($created->board, User::where('is_active', true)->get());

        $values = [];
        foreach ($mappings as $mapping) {
            $column = $columns->get((int) ($mapping['column_id'] ?? 0));
            if (! $column || ! is_array($mapping) || $caster->defersLinkedItems($column)) {
                continue;
            }

            $text = $this->renderer->renderPlain((string) ($mapping['source'] ?? ''), '', $automation, $item, $actor, $context);
            $value = $text === '' ? null : $caster->cast($column, $text);
            if ($value !== null) {
                $values[(string) $column->id] = $value;
            }
        }

        if ($values !== []) {
            $this->writeValues($created, $values, $actor);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function writeValues(BoardItem $item, array $values, ?User $actor): void
    {
        $this->valueService()->sync($item->board, $item, $values, $this->run_context->isDryRun() ? null : $actor);
    }

    /**
     * One subitem per name of `subitem_names` (tokens such as `{item_name}` and `{column:12}` filled
     * in), and with `source_column_id` one per entry of that column on the item: a line of a text,
     * a task of a checklist, a label of tags or a dropdown.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function createSubitems(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor, array $context = []): BoardAutomationActionOutcome
    {
        $names = array_map(fn ($name) => $this->renderer->renderPlain((string) $name, '', $automation, $item, $actor, $context), (array) ($params['subitem_names'] ?? []));
        if (! empty($params['source_column_id'])) {
            $source = $this->resolveColumnTarget($automation, $params, $item, null, 'source_column_id');
            if (is_string($source)) {
                return BoardAutomationActionOutcome::skipped($source);
            }
            array_push($names, ...$this->listEntries($source[0], $this->currentValue($source[1], $source[0])));
        }

        $names = array_slice(array_values(array_filter(array_map('trim', $names), fn (string $name) => $name !== '')), 0, self::MAX_CREATED_SUBITEMS);
        if ($names === []) {
            return BoardAutomationActionOutcome::skipped(empty($params['source_column_id']) ? 'No subitem names are set.' : 'The list column was empty, so no subitem was created.');
        }

        $position = (int) BoardItem::where('parent_id', $item->id)->max('position') + 1;
        $created = [];
        foreach ($names as $offset => $name) {
            $created[] = BoardItem::create([
                'board_id' => $item->board_id,
                'group_id' => $item->group_id,
                'parent_id' => $item->id,
                'name' => mb_substr($name, 0, 250),
                'position' => $position + $offset,
                'created_by_id' => $actor?->id ?? $automation->responsibleUser()?->id,
            ]);
        }

        foreach ($created as $subitem) {
            $this->journal->created($subitem);
            $this->valueService()->assignAutoNumbers($subitem, BoardColumn::SCOPE_SUBITEM);
            $this->automationService()->handleItemCreated($subitem, $actor);
        }

        $count = count($created);
        $this->log($automation, $item, $actor, "created {$count} subitem(s) under \"{$item->name}\"");

        return BoardAutomationActionOutcome::success("Created {$count} subitem(s).");
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function postUpdate(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $author = $automation->responsibleUser() ?? $actor;
        if (! $author) {
            return BoardAutomationActionOutcome::skipped('The automation has no owner to post the update as.');
        }

        $body = trim($this->renderer->render($params['message'] ?? null, $automation, $item, $actor, $context));
        if ($body === '') {
            return BoardAutomationActionOutcome::skipped('The update is empty.');
        }

        $comment = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => $body]);
        $this->log($automation, $item, $actor, "posted an update on \"{$item->name}\"");
        $this->automationService()->handleUpdatePosted($item, $comment, $author);

        return BoardAutomationActionOutcome::success('Posted an update on the item.');
    }

    // ── Notifications and communication ───────────────────────────────────────

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function notifyPerson(BoardAutomation $automation, array $params, ?BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $recipients = $this->resolveRecipients($automation, $params, $item, $actor, $context);
        if ($recipients->isEmpty()) {
            return BoardAutomationActionOutcome::skipped('Found nobody to notify.');
        }
        if ($this->run_context->isDryRun()) {
            return BoardAutomationActionOutcome::success('Would notify '.$recipients->map(fn (User $user) => $user->full_name)->implode(', ').'.');
        }

        $board = $item?->board ?? $automation->board;
        $automation_label = $automation->name ?: 'Automation';
        $has_message = trim((string) ($params['message'] ?? '')) !== '';
        $action_target = $has_message
            ? $this->renderer->render($params['message'], $automation, $item, $actor, $context)
            : ($item ? sprintf('ran on "%s" on the Board "%s"', $item->name, $board->label) : sprintf('ran on the Board "%s"', $board->label));

        $names = [];
        foreach ($recipients as $recipient) {
            $this->notification_service->notify(
                recipient: $recipient,
                actor: $actor,
                type: Notification::TYPE_AUTOMATION,
                board: $board,
                action_label: "Automation \"{$automation_label}\"",
                action_target: $action_target,
                link: $item ? "/boards/{$item->board_id}/pulses/{$item->id}" : "/boards/{$board->id}",
                board_item: $item,
            );
            $names[] = $recipient->full_name;
        }

        $list = implode(', ', $names);
        $this->log($automation, $item, $actor, "notified {$list}");

        return BoardAutomationActionOutcome::success("Notified {$list}.");
    }

    /**
     * Delivers one email, Slack channel post or Slack direct message. Failures that are the
     * recipient's or the workspace's setup (Slack not connected, a member who never linked Slack)
     * are reported as a failed run instead of being thrown.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function runCommunicationAction(BoardAutomation $automation, string $type, array $params, ?BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $message = $this->renderer->render($params['message'] ?? null, $automation, $item, $actor, $context);
        $board = $item?->board ?? $automation->board;
        $link = $item ? "/boards/{$item->board_id}/pulses/{$item->id}" : "/boards/{$board->id}";

        if ($this->run_context->isDryRun()) {
            return $this->describeCommunication($automation, $type, $params, $item, $actor, $context);
        }

        $outcomes = [];
        $delivered_count = 0;
        $failed_count = 0;

        if ($type === BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL) {
            $channel_id = (string) ($params['slack_channel_id'] ?? '');
            $channel_name = (string) ($params['slack_channel_name'] ?? $channel_id);

            $was_sent = $channel_id !== '' && $this->slack_notifier->notifyChannel($channel_id, $message, $link, $board->label);
            $outcomes[] = $was_sent ? "posted to Slack channel #{$channel_name}" : 'could not post to Slack because Slack is not connected';
            $was_sent ? $delivered_count++ : $failed_count++;
        } else {
            $recipients = $this->resolveRecipients($automation, $params, $item, $actor, $context);
            $addresses = $type === BoardAutomation::ACTION_SEND_EMAIL ? $this->resolveEmailAddresses($params, $item, $recipients) : [];
            if ($recipients->isEmpty() && $addresses === []) {
                $outcomes[] = 'found nobody to notify';
            }

            foreach ($addresses as $address) {
                SendEmailJob::dispatch(
                    new AutomationEmail($this->renderer->renderSubject($params['subject'] ?? null, $automation, $item, $actor, $context), $message, $board->label, $link),
                    $address,
                );
                $outcomes[] = "emailed {$address}";
                $delivered_count++;
            }

            foreach ($recipients as $recipient) {
                if ($type === BoardAutomation::ACTION_SEND_EMAIL) {
                    SendEmailJob::dispatch(
                        new AutomationEmail($this->renderer->renderSubject($params['subject'] ?? null, $automation, $item, $actor, $context), $message, $board->label, $link),
                        $recipient->email,
                    );
                    $outcomes[] = "emailed {$recipient->full_name}";
                    $delivered_count++;

                    continue;
                }

                $was_sent = $this->slack_notifier->notifyUser($recipient, $message, $link, $board->label);
                $outcomes[] = $was_sent
                    ? "sent a Slack message to {$recipient->full_name}"
                    : "could not reach {$recipient->full_name} on Slack because they have not connected their Slack account";
                $was_sent ? $delivered_count++ : $failed_count++;
            }
        }

        $this->log($automation, $item, $actor, implode(', ', $outcomes));
        $sentence = ucfirst(implode(', ', $outcomes)).'.';

        return match (true) {
            $failed_count > 0 => BoardAutomationActionOutcome::failed($sentence),
            $delivered_count > 0 => BoardAutomationActionOutcome::success($sentence),
            default => BoardAutomationActionOutcome::skipped($sentence),
        };
    }

    /**
     * What a communication action would have done, for a test run.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function describeCommunication(BoardAutomation $automation, string $type, array $params, ?BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        if ($type === BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL) {
            return BoardAutomationActionOutcome::success('Would post to Slack channel #'.($params['slack_channel_name'] ?? $params['slack_channel_id'] ?? 'channel').'.');
        }

        $recipients = $this->resolveRecipients($automation, $params, $item, $actor, $context);
        $names = $recipients->map(fn (User $user) => $user->full_name)
            ->merge($type === BoardAutomation::ACTION_SEND_EMAIL ? $this->resolveEmailAddresses($params, $item, $recipients) : [])
            ->implode(', ');
        if ($names === '') {
            return BoardAutomationActionOutcome::skipped('Found nobody to reach.');
        }

        return BoardAutomationActionOutcome::success($type === BoardAutomation::ACTION_SEND_EMAIL ? "Would email {$names}." : "Would send a Slack message to {$names}.");
    }

    /**
     * Everyone a notify or communication action should reach: a fixed `notify_user_id`, the person
     * `recipient_source` stands for on this run (`actor`, `creator`, `owner`, `mentioned`, or every
     * `subscribers` of the item), or every person `notify_from_people_column_id` currently holds.
     * Deactivated accounts are skipped.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     * @return Collection<int, User>
     */
    private function resolveRecipients(BoardAutomation $automation, array $params, ?BoardItem $item, ?User $actor = null, array $context = []): Collection
    {
        if ($user_id = $params['notify_user_id'] ?? null) {
            return User::whereKey($user_id)->where('is_active', true)->get();
        }

        $source = $params['recipient_source'] ?? null;
        if ($source === 'subscribers') {
            return $item ? $this->dynamic_values->subscribers($item) : new Collection;
        }
        if (is_string($source) && in_array($source, BoardAutomation::RECIPIENT_SOURCES, true)) {
            $person_ids = $this->dynamic_values->personIds(['source' => $source], $item, $actor, $automation, $context);

            return $person_ids ? User::whereIn('id', $person_ids)->where('is_active', true)->get() : new Collection;
        }

        if ($item && ($people_column_id = $params['notify_from_people_column_id'] ?? null)) {
            $subject = $this->subjectForColumn($item, BoardColumn::find($people_column_id));
            $value = $subject ? BoardItemValue::where('item_id', $subject->id)->where('column_id', $people_column_id)->first() : null;
            $person_ids = $this->peopleIds($value?->value);

            return $person_ids ? User::whereIn('id', $person_ids)->where('is_active', true)->get() : new Collection;
        }

        return new Collection;
    }

    /**
     * The outside email addresses a "send an email" action reaches: the addresses typed into
     * `email_addresses`, and whatever the Email column `email_column_id` holds on the item. Anyone
     * already emailed as a member is left out, so nobody gets the same email twice.
     *
     * @param  array<string, mixed>  $params
     * @param  Collection<int, User>  $members
     * @return array<int, string>
     */
    private function resolveEmailAddresses(array $params, ?BoardItem $item, Collection $members): array
    {
        $addresses = array_map('strval', (array) ($params['email_addresses'] ?? []));

        if ($item && ($email_column_id = $params['email_column_id'] ?? null)) {
            $column = BoardColumn::find((int) $email_column_id);
            $subject = $this->subjectForColumn($item, $column);
            $value = $subject ? $this->currentValue($subject, $column) : null;
            foreach (preg_split('/[\s,;]+/', is_string($value) ? $value : '') ?: [] as $address) {
                $addresses[] = $address;
            }
        }

        $taken = $members->map(fn (User $user) => mb_strtolower((string) $user->email))->all();
        $unique = [];
        foreach ($addresses as $address) {
            $address = trim($address);
            $key = mb_strtolower($address);
            if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL) || in_array($key, $taken, true)) {
                continue;
            }
            $taken[] = $key;
            $unique[] = $address;
        }

        return array_slice($unique, 0, 20);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The target column of a column action and the item it applies to, or why it cannot run.
     *
     * @param  array<string, mixed>  $params
     * @param  string|array<int, string>|null  $required_type  one type or a list of accepted types
     * @return array{0: BoardColumn, 1: BoardItem}|string
     */
    private function resolveColumnTarget(BoardAutomation $automation, array $params, BoardItem $item, string|array|null $required_type = null, string $param = 'target_column_id'): array|string
    {
        $column = BoardColumn::where('board_view_id', $automation->board_view_id)->find((int) ($params[$param] ?? 0));
        if (! $column) {
            return $param === 'target_column_id' ? 'The column to update no longer exists.' : 'The column to read from no longer exists.';
        }
        if ($param === 'target_column_id' && in_array($column->type, BoardColumn::READ_ONLY_TYPES, true)) {
            return "\"{$column->label}\" is calculated and cannot be changed.";
        }

        $accepted_types = $required_type === null ? null : (array) $required_type;
        if ($accepted_types === [BoardColumn::TYPE_NUMBER]) {
            $accepted_types = [BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_RATING, BoardColumn::TYPE_PROGRESS];
        }
        if ($accepted_types !== null && ! in_array($column->type, $accepted_types, true)) {
            return "\"{$column->label}\" is not the right kind of column for this action.";
        }

        $subject = $this->subjectForColumn($item, $column);
        if (! $subject) {
            return "\"{$column->label}\" belongs to subitems, and this item has none of its own.";
        }

        return [$column, $subject];
    }

    /**
     * The item a column's value lives on: the item itself when the scopes match, the top-level
     * parent when a subitem acts on an item column, null when an item acts on a subitem column.
     */
    private function subjectForColumn(BoardItem $item, ?BoardColumn $column): ?BoardItem
    {
        if (! $column) {
            return null;
        }

        $item_scope = $item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;
        if ($column->scope === $item_scope) {
            return $item;
        }
        if ($column->scope === BoardColumn::SCOPE_SUBITEM) {
            return null;
        }

        $root = $item;
        while ($root->parent_id !== null && ($parent = BoardItem::find($root->parent_id))) {
            $root = $parent;
        }

        return $root->parent_id === null ? $root : null;
    }

    private function currentValue(BoardItem $item, BoardColumn $column): mixed
    {
        return BoardItemValue::where('item_id', $item->id)->where('column_id', $column->id)->first()?->value;
    }

    /**
     * A test run writes without an actor, so nobody gets an "Assigned you" notification for it.
     */
    private function writeValue(BoardItem $item, BoardColumn $column, mixed $value, ?User $actor): void
    {
        $this->valueService()->sync($item->board, $item, [(string) $column->id => $value], $this->run_context->isDryRun() ? null : $actor);
    }

    /**
     * @return array<int, string>
     */
    private function peopleIds(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
    }

    private function valuesAreEqual(mixed $old_value, mixed $new_value): bool
    {
        return $this->automationService()->valuesAreEqual($old_value, $new_value);
    }

    /**
     * The target group of a cross-board action, or why it cannot be used: it must be a table of
     * the other board's primary tab, and the automation's owner must still be allowed to edit it.
     *
     * @param  array<string, mixed>  $params
     */
    private function resolveCrossBoardGroup(BoardAutomation $automation, array $params): BoardGroup|string
    {
        $target_board = WorkspaceNavigationItem::boards()->notArchived()->find((int) ($params['target_board_id'] ?? 0));
        if (! $target_board) {
            return 'The target board no longer exists.';
        }

        $target_group = BoardGroup::where('board_id', $target_board->id)
            ->whereHas('boardView', fn ($query) => $query->where('is_primary', true))
            ->find((int) ($params['target_group_id'] ?? 0));
        if (! $target_group) {
            return 'The target group no longer exists on the other board.';
        }

        $owner = $automation->responsibleUser();
        if (! $owner || ! BoardEditGate::allowsContent($target_board, $owner)) {
            return 'The automation owner can no longer edit the target board.';
        }

        $target_group->setRelation('board', $target_board);

        return $target_group;
    }

    /**
     * Deep-copies an item and its subtree (values included, auto-numbers assigned fresh), the
     * automation twin of `BoardItemController::copySubtree()`.
     */
    private function copySubtree(BoardItem $original, ?int $parent_id, int $position, bool $with_children, bool $is_top): BoardItem
    {
        $original->loadMissing(['values', 'childrenRecursive', 'group']);

        $copy = BoardItem::create([
            'board_id' => $original->board_id,
            'group_id' => $original->group_id,
            'parent_id' => $parent_id,
            'name' => $is_top ? mb_substr("{$original->name} (copy)", 0, 255) : $original->name,
            'description' => $original->description,
            'position' => $position,
            'is_priority' => $original->is_priority,
            'created_by_id' => $original->created_by_id,
        ]);

        $scope = $parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;
        $auto_number_column_ids = BoardColumn::where('board_view_id', $original->group->board_view_id)
            ->where('scope', $scope)
            ->where('type', BoardColumn::TYPE_AUTO_NUMBER)
            ->pluck('id');

        foreach ($original->values as $value) {
            if (! $auto_number_column_ids->contains($value->column_id)) {
                $copy->values()->create(['column_id' => $value->column_id, 'value' => $value->value]);
            }
        }
        $this->valueService()->assignAutoNumbers($copy, $scope);

        if ($with_children) {
            foreach ($original->childrenRecursive->where('is_archived', false) as $child) {
                $this->copySubtree($child, $copy->id, $child->position, true, false);
            }
        }

        return $copy;
    }

    private function cascadeGroupToDescendants(BoardItem $item, int $group_id): void
    {
        $item->loadMissing('childrenRecursive');

        foreach ($this->flattenTree($item->childrenRecursive) as $descendant) {
            $descendant->update(['group_id' => $group_id]);
        }
    }

    /**
     * @param  iterable<BoardItem>  $items
     * @return array<int, BoardItem>
     */
    private function flattenTree(iterable $items): array
    {
        $flat = [];
        foreach ($items as $child) {
            $flat[] = $child;
            $flat = array_merge($flat, $this->flattenTree($child->childrenRecursive));
        }

        return $flat;
    }

    /**
     * Writes the board activity entry every automation action leaves.
     */
    private function log(BoardAutomation $automation, ?BoardItem $item, ?User $actor, string $what): void
    {
        $automation_label = $automation->name ?: 'Automation';

        $this->activity_logger->log(
            $item?->board ?? $automation->board,
            $actor,
            BoardActivityLog::ACTION_AUTOMATION_RAN,
            "Automation \"{$automation_label}\" {$what}",
            array_filter(['item_id' => $item?->id, 'automation_id' => $automation->id])
        );
    }

    /**
     * Resolved lazily: the value service depends on the automation service, which depends on
     * this runner, so injecting it in the constructor would be a cycle.
     */
    private function valueService(): BoardItemValueService
    {
        return app(BoardItemValueService::class);
    }

    private function automationService(): BoardAutomationService
    {
        return app(BoardAutomationService::class);
    }
}
