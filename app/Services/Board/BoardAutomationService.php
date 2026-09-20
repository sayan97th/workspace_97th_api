<?php

namespace App\Services\Board;

use App\Http\Controllers\Board\BoardItemCommentController;
use App\Http\Controllers\Board\BoardItemController;
use App\Jobs\SendEmailJob;
use App\Mail\Automations\AutomationEmail;
use App\Models\BoardActivityLog;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationRun;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardItemValue;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Slack\SlackNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the rule-based (no AI) automation recipes a board tab can define, see
 * {@see BoardAutomation}'s own doc comment for the trigger/action vocabulary:
 *
 * - "When `trigger_column_id` changes to `trigger_value`, run `action_type`" —
 *   {@see handleValueChanged()}, called synchronously from the same choke
 *   point every column-value write already goes through,
 *   {@see BoardItemValueService::sync()}. Covers `status_changed`,
 *   `person_assigned`.
 * - "When `trigger_column_id`'s date arrives, run `action_type`" —
 *   {@see runDueDateTriggers()}, called once daily by the scheduled
 *   `automations:run-date-triggers` command.
 * - "When an item/subitem is created, run `action_type`" —
 *   {@see handleItemCreated()}, called synchronously from
 *   {@see BoardItemController::store()}.
 * - "When an update is posted on an item, run `action_type`" —
 *   {@see handleUpdatePosted()}, called synchronously from
 *   {@see BoardItemCommentController::store()}.
 *
 * Any trigger can be paired with a communication action (email, Slack channel, Slack
 * direct message), whose message is a template filled in by {@see renderMessage()}.
 */
class BoardAutomationService
{
    public function __construct(
        private readonly NotificationService $notification_service,
        private readonly BoardActivityLogger $activity_logger,
        private readonly SlackNotifier $slack_notifier,
    ) {}

    /**
     * Reacts to one column's value having just changed on `$item`. Status/label and
     * people columns first run their own specialised triggers, then every column type
     * runs the generic `column_changed` trigger, a no-op wherever no automation watches it.
     */
    public function handleValueChanged(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if (in_array($column->type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL], true)) {
            $this->handleStatusChanged($item, $column, $old_value, $new_value, $actor);
        } elseif ($column->type === BoardColumn::TYPE_PEOPLE) {
            $this->handlePersonAssigned($item, $column, $old_value, $new_value, $actor);
        }

        $this->handleColumnChanged($item, $column, $old_value, $new_value, $actor);
    }

    /**
     * Fires every enabled `column_changed` automation watching `$column` once the value
     * it holds is different from what was stored before this write.
     */
    private function handleColumnChanged(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if ($this->valuesAreEqual($old_value, $new_value)) {
            return;
        }

        $automations = BoardAutomation::query()
            ->where('board_view_id', $item->group->board_view_id)
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_COLUMN_CHANGED)
            ->where('trigger_column_id', $column->id)
            ->get();

        foreach ($automations as $automation) {
            $this->executeAction($automation, $item, $actor, [
                'column' => $column,
                'old_value' => $old_value,
                'new_value' => $new_value,
            ]);
        }
    }

    /**
     * A single-select column's value is a plain option id string — comparing
     * the raw values is enough to tell whether this write actually changed
     * which option is selected, versus an unrelated re-save of the same one.
     */
    private function handleStatusChanged(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if ($new_value === $old_value) {
            return;
        }

        $automations = BoardAutomation::query()
            ->where('board_view_id', $item->group->board_view_id)
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_STATUS_CHANGED)
            ->where('trigger_column_id', $column->id)
            ->get();

        foreach ($automations as $automation) {
            if ((string) $automation->trigger_value !== (string) $new_value) {
                continue;
            }

            $this->executeAction($automation, $item, $actor, [
                'column' => $column,
                'old_value' => $old_value,
                'new_value' => $new_value,
            ]);
        }
    }

    /**
     * Fires once for every person newly added to a `people` column's value
     * (comparing against what was stored before this write) — a
     * `trigger_value` of null watches for anyone being assigned; a specific
     * user id only fires when that exact person is the one newly added.
     */
    private function handlePersonAssigned(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        $old_ids = is_array($old_value) ? $old_value : [];
        $new_ids = is_array($new_value) ? $new_value : [];
        $newly_added_ids = array_diff($new_ids, $old_ids);

        if (empty($newly_added_ids)) {
            return;
        }

        $automations = BoardAutomation::query()
            ->where('board_view_id', $item->group->board_view_id)
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_PERSON_ASSIGNED)
            ->where('trigger_column_id', $column->id)
            ->get();

        foreach ($automations as $automation) {
            $watched_user_id = $automation->trigger_value;
            $matches = $watched_user_id === null || in_array((string) $watched_user_id, array_map('strval', $newly_added_ids), true);

            if ($matches) {
                $this->executeAction($automation, $item, $actor, [
                    'column' => $column,
                    'old_value' => $old_value,
                    'new_value' => $new_value,
                ]);
            }
        }
    }

    /**
     * Reacts to `$item` having just been created — fires every enabled
     * `item_created` (root item) or `subitem_created` (has a parent)
     * automation on the item's tab.
     */
    public function handleItemCreated(BoardItem $item, ?User $actor): void
    {
        $trigger_type = $item->parent_id === null ? BoardAutomation::TRIGGER_ITEM_CREATED : BoardAutomation::TRIGGER_SUBITEM_CREATED;

        $automations = BoardAutomation::query()
            ->where('board_view_id', $item->group->board_view_id)
            ->where('is_enabled', true)
            ->where('trigger_type', $trigger_type)
            ->get();

        foreach ($automations as $automation) {
            $this->executeAction($automation, $item, $actor);
        }
    }

    /**
     * Reacts to a top-level update having just been posted on `$item`, replies do not count.
     */
    public function handleUpdatePosted(BoardItem $item, BoardItemComment $comment, ?User $actor): void
    {
        if ($comment->parent_id !== null) {
            return;
        }

        $automations = BoardAutomation::query()
            ->where('board_view_id', $item->group->board_view_id)
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_UPDATE_POSTED)
            ->get();

        foreach ($automations as $automation) {
            $this->executeAction($automation, $item, $actor, ['update_text' => (string) $comment->body]);
        }
    }

    /**
     * Scans every enabled `date_arrived` automation for items whose trigger
     * column's stored date is today, runs the action once per (automation,
     * item) pair not already recorded in {@see BoardAutomationRun} for today.
     *
     * @return int How many (automation, item) pairs ran, for the calling command to report.
     */
    public function runDueDateTriggers(): int
    {
        $today = Carbon::today()->toDateString();
        $ran_count = 0;

        $automations = BoardAutomation::query()
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_DATE_ARRIVED)
            ->get();

        foreach ($automations as $automation) {
            $already_ran_item_ids = BoardAutomationRun::where('automation_id', $automation->id)
                ->where('ran_on', $today)
                ->pluck('board_item_id');

            $due_values = BoardItemValue::where('column_id', $automation->trigger_column_id)
                ->whereRaw('JSON_UNQUOTE(`value`) = ?', [$today])
                ->whereNotIn('item_id', $already_ran_item_ids)
                ->with('item.group')
                ->get();

            foreach ($due_values as $value) {
                // `BoardItem` uses `SoftDeletes`, so its relation's default global
                // scope filters out a soft-deleted item, this can be null despite
                // `item_id`'s FK cascade (which only ever fires on a hard delete).
                $item = $value->item;
                if (! $item) {
                    continue;
                }

                $this->executeAction($automation, $item, null, [
                    'column' => $automation->triggerColumn,
                    'new_value' => $today,
                ]);

                BoardAutomationRun::create([
                    'automation_id' => $automation->id,
                    'board_item_id' => $item->id,
                    'ran_on' => $today,
                ]);
                $ran_count++;
            }
        }

        return $ran_count;
    }

    /**
     * Runs one automation's configured action against one item, then logs it
     * through {@see BoardActivityLogger} the same way every other board
     * mutation does.
     *
     * `$context` carries whatever the trigger knows about what just happened
     * (`column`, `old_value`, `new_value`, `update_text`), used to fill in the
     * message of a communication action.
     *
     * Every call is written to {@see BoardAutomationRunLog} for the Manage tab's "Run history".
     * An exception thrown while acting is caught and recorded as a failed run, so one broken
     * automation can never break the change that triggered it.
     *
     * @param  array<string, mixed>  $context
     */
    private function executeAction(BoardAutomation $automation, BoardItem $item, ?User $actor, array $context = []): void
    {
        try {
            [$status, $message] = $this->performAction($automation, $item, $actor, $context);
        } catch (Throwable $exception) {
            report($exception);
            [$status, $message] = [BoardAutomationRunLog::STATUS_FAILED, 'The action failed unexpectedly.'];
        }

        $this->recordRun($automation, $item, $actor, $status, $message);
    }

    /**
     * @return array{0: string, 1: string} The run status and a one sentence outcome.
     */
    private function outcome(string $status, string $message): array
    {
        return [$status, $message];
    }

    private function recordRun(BoardAutomation $automation, BoardItem $item, ?User $actor, string $status, string $message): void
    {
        try {
            BoardAutomationRunLog::create([
                'automation_id' => $automation->id,
                'board_id' => $automation->board_id,
                'board_view_id' => $automation->board_view_id,
                'board_item_id' => $item->id,
                'actor_id' => $actor?->id,
                'automation_name' => $automation->name,
                'item_name' => Str::limit((string) $item->name, 250, ''),
                'trigger_type' => $automation->trigger_type,
                'action_type' => $automation->action_type,
                'status' => $status,
                'message' => $message,
            ]);
        } catch (Throwable $exception) {
            // Keeping the history is best effort, it must never break the change that triggered the automation.
            report($exception);
        }
    }

    /**
     * Does the work of one automation and reports what came of it, see {@see executeAction()}.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: string, 1: string}
     */
    private function performAction(BoardAutomation $automation, BoardItem $item, ?User $actor, array $context): array
    {
        $automation_label = $automation->name ?: 'Automation';

        if (in_array($automation->action_type, BoardAutomation::communicationActions(), true)) {
            return $this->executeCommunicationAction($automation, $item, $actor, $context);
        }

        if ($automation->action_type === BoardAutomation::ACTION_MOVE_TO_GROUP) {
            $target_group_id = (int) ($automation->action_params['target_group_id'] ?? 0);
            if (! $target_group_id) {
                return $this->outcome(BoardAutomationRunLog::STATUS_SKIPPED, 'No target table is set.');
            }
            if ($item->group_id === $target_group_id) {
                return $this->outcome(BoardAutomationRunLog::STATUS_SKIPPED, 'The item was already in the target table.');
            }

            DB::transaction(function () use ($item, $target_group_id) {
                $item->update(['group_id' => $target_group_id]);
                $this->cascadeGroupToDescendants($item, $target_group_id);
            });

            $this->activity_logger->log(
                $item->board,
                $actor,
                BoardActivityLog::ACTION_AUTOMATION_RAN,
                "Automation \"{$automation_label}\" moved \"{$item->name}\" to a different table",
                ['item_id' => $item->id]
            );

            return $this->outcome(BoardAutomationRunLog::STATUS_SUCCESS, 'Moved the item to a different table.');
        }

        if ($automation->action_type === BoardAutomation::ACTION_NOTIFY_PERSON) {
            $recipient = $this->resolveNotifyTarget($automation, $item);
            if (! $recipient) {
                return $this->outcome(BoardAutomationRunLog::STATUS_SKIPPED, 'Found nobody to notify.');
            }

            $this->notification_service->notify(
                recipient: $recipient,
                actor: $actor,
                type: Notification::TYPE_AUTOMATION,
                board: $item->board,
                action_label: "Automation \"{$automation_label}\"",
                action_target: sprintf('ran on "%s" on the Board "%s"', $item->name, $item->board->label),
                link: "/boards/{$item->board_id}/pulses/{$item->id}",
                board_item: $item,
            );

            $this->activity_logger->log(
                $item->board,
                $actor,
                BoardActivityLog::ACTION_AUTOMATION_RAN,
                "Automation \"{$automation_label}\" notified {$recipient->full_name}",
                ['item_id' => $item->id]
            );

            return $this->outcome(BoardAutomationRunLog::STATUS_SUCCESS, "Notified {$recipient->full_name}.");
        }

        if ($automation->action_type === BoardAutomation::ACTION_ARCHIVE_ITEM) {
            $item->delete();

            $this->activity_logger->log(
                $item->board,
                $actor,
                BoardActivityLog::ACTION_AUTOMATION_RAN,
                "Automation \"{$automation_label}\" archived \"{$item->name}\"",
                ['item_id' => $item->id]
            );

            return $this->outcome(BoardAutomationRunLog::STATUS_SUCCESS, 'Archived the item.');
        }

        if ($automation->action_type === BoardAutomation::ACTION_SET_COLUMN_VALUE) {
            $target_column_id = $automation->action_params['target_column_id'] ?? null;
            if (! $target_column_id) {
                return $this->outcome(BoardAutomationRunLog::STATUS_SKIPPED, 'No column to update is set.');
            }

            $item->values()->updateOrCreate(
                ['column_id' => $target_column_id],
                ['value' => $automation->action_params['value'] ?? null]
            );

            $this->activity_logger->log(
                $item->board,
                $actor,
                BoardActivityLog::ACTION_AUTOMATION_RAN,
                "Automation \"{$automation_label}\" updated a column on \"{$item->name}\"",
                ['item_id' => $item->id]
            );

            return $this->outcome(BoardAutomationRunLog::STATUS_SUCCESS, 'Updated a column on the item.');
        }

        if ($automation->action_type === BoardAutomation::ACTION_CREATE_ITEM) {
            $target_group_id = (int) ($automation->action_params['target_group_id'] ?? 0);
            if (! $target_group_id) {
                return $this->outcome(BoardAutomationRunLog::STATUS_SKIPPED, 'No target table is set.');
            }

            $position = (int) BoardItem::where('group_id', $target_group_id)->whereNull('parent_id')->max('position') + 1;

            BoardItem::create([
                'board_id' => $item->board_id,
                'group_id' => $target_group_id,
                'parent_id' => null,
                'name' => $automation->action_params['item_name'] ?? 'New item',
                'position' => $position,
                'created_by_id' => $actor?->id,
            ]);

            $this->activity_logger->log(
                $item->board,
                $actor,
                BoardActivityLog::ACTION_AUTOMATION_RAN,
                "Automation \"{$automation_label}\" created a new item",
                ['item_id' => $item->id]
            );

            return $this->outcome(BoardAutomationRunLog::STATUS_SUCCESS, 'Created a new item.');
        }

        return $this->outcome(BoardAutomationRunLog::STATUS_SKIPPED, 'This action type is not supported.');
    }

    /**
     * Delivers one email, Slack channel post or Slack direct message. Failures that are
     * the recipient's or the workspace's setup (nobody to notify, Slack not connected,
     * a member who never linked Slack) are written to the board's activity log instead of
     * being thrown, since they must not break the change that triggered the automation.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: string, 1: string} Failed when anything could not be delivered, skipped when there was nobody to reach.
     */
    private function executeCommunicationAction(BoardAutomation $automation, BoardItem $item, ?User $actor, array $context): array
    {
        $automation_label = $automation->name ?: 'Automation';
        $message = $this->renderMessage($automation, $item, $actor, $context);
        $link = "/boards/{$item->board_id}/pulses/{$item->id}";
        $board_label = $item->board->label;
        $outcomes = [];
        $delivered_count = 0;
        $failed_count = 0;

        if ($automation->action_type === BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL) {
            $channel_id = (string) ($automation->action_params['slack_channel_id'] ?? '');
            $channel_name = (string) ($automation->action_params['slack_channel_name'] ?? $channel_id);

            $was_sent = $channel_id !== '' && $this->slack_notifier->notifyChannel($channel_id, $message, $link, $board_label);
            $outcomes[] = $was_sent ? "posted to Slack channel #{$channel_name}" : 'could not post to Slack because Slack is not connected';
            $was_sent ? $delivered_count++ : $failed_count++;
        } else {
            $recipients = $this->resolveRecipients($automation, $item);

            if ($recipients->isEmpty()) {
                $outcomes[] = 'found nobody to notify';
            }

            foreach ($recipients as $recipient) {
                if ($automation->action_type === BoardAutomation::ACTION_SEND_EMAIL) {
                    SendEmailJob::dispatch(
                        new AutomationEmail($this->renderSubject($automation, $item), $message, $board_label, $link),
                        $recipient->email,
                    );
                    $outcomes[] = "emailed {$recipient->full_name}";
                    $delivered_count++;

                    continue;
                }

                $was_sent = $this->slack_notifier->notifyUser($recipient, $message, $link, $board_label);
                $outcomes[] = $was_sent
                    ? "sent a Slack message to {$recipient->full_name}"
                    : "could not reach {$recipient->full_name} on Slack because they have not connected their Slack account";
                $was_sent ? $delivered_count++ : $failed_count++;
            }
        }

        $this->activity_logger->log(
            $item->board,
            $actor,
            BoardActivityLog::ACTION_AUTOMATION_RAN,
            "Automation \"{$automation_label}\" ".implode(', ', $outcomes),
            ['item_id' => $item->id]
        );

        $status = match (true) {
            $failed_count > 0 => BoardAutomationRunLog::STATUS_FAILED,
            $delivered_count > 0 => BoardAutomationRunLog::STATUS_SUCCESS,
            default => BoardAutomationRunLog::STATUS_SKIPPED,
        };

        return $this->outcome($status, ucfirst(implode(', ', $outcomes)).'.');
    }

    /**
     * Fills in the automation's `action_params.message` template, or a default sentence
     * for its trigger when none was written. Supported tokens: `{item_name}`, `{board_name}`,
     * `{actor_name}`, `{column_name}`, `{old_value}`, `{new_value}` and `{update_text}`.
     * An unknown token is left as typed rather than dropped.
     *
     * @param  array<string, mixed>  $context
     */
    public function renderMessage(BoardAutomation $automation, BoardItem $item, ?User $actor, array $context = []): string
    {
        $template = trim((string) ($automation->action_params['message'] ?? ''));
        if ($template === '') {
            $template = $this->defaultMessageTemplate($automation->trigger_type);
        }

        $column = $context['column'] ?? null;

        return strtr($template, [
            '{item_name}' => $item->name,
            '{board_name}' => $item->board->label,
            '{actor_name}' => $actor?->full_name ?: 'Someone',
            '{column_name}' => $column instanceof BoardColumn ? $this->columnLabel($column) : 'a column',
            '{old_value}' => $column instanceof BoardColumn ? $this->displayValue($column, $context['old_value'] ?? null) : '',
            '{new_value}' => $column instanceof BoardColumn ? $this->displayValue($column, $context['new_value'] ?? null) : '',
            '{update_text}' => Str::limit(trim((string) ($context['update_text'] ?? '')), 300),
        ]);
    }

    /**
     * `$column` may have been loaded with a partial `select()`, so a missing label is
     * read from the database instead of being rendered as empty text.
     */
    private function columnLabel(BoardColumn $column): string
    {
        return (string) ($column->getAttribute('label') ?? BoardColumn::whereKey($column->id)->value('label') ?? 'a column');
    }

    private function renderSubject(BoardAutomation $automation, BoardItem $item): string
    {
        $subject = trim((string) ($automation->action_params['subject'] ?? ''));

        return $subject !== '' ? strtr($subject, ['{item_name}' => $item->name, '{board_name}' => $item->board->label]) : "Update on \"{$item->name}\"";
    }

    private function defaultMessageTemplate(string $trigger_type): string
    {
        return match ($trigger_type) {
            BoardAutomation::TRIGGER_STATUS_CHANGED, BoardAutomation::TRIGGER_COLUMN_CHANGED => '{column_name} changed to "{new_value}" on "{item_name}".',
            BoardAutomation::TRIGGER_DATE_ARRIVED => 'The date in {column_name} has arrived on "{item_name}".',
            BoardAutomation::TRIGGER_ITEM_CREATED => 'A new item "{item_name}" was created on {board_name}.',
            BoardAutomation::TRIGGER_SUBITEM_CREATED => 'A new subitem "{item_name}" was created on {board_name}.',
            BoardAutomation::TRIGGER_PERSON_ASSIGNED => '{new_value} was assigned to "{item_name}".',
            BoardAutomation::TRIGGER_UPDATE_POSTED => '{actor_name} posted an update on "{item_name}": {update_text}',
            default => 'An automation ran on "{item_name}".',
        };
    }

    /**
     * A human readable version of a raw stored value: a status/label option id becomes
     * its label, a people value becomes names, other arrays are joined with commas.
     */
    private function displayValue(BoardColumn $column, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        if (in_array($column->type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL], true)) {
            foreach ($column->config['options'] ?? [] as $option) {
                if ((string) ($option['id'] ?? '') === (string) $value) {
                    return (string) ($option['label'] ?? $value);
                }
            }
        }

        if ($column->type === BoardColumn::TYPE_PEOPLE && is_array($value)) {
            return User::whereIn('id', $value)->get()->map(fn (User $user) => $user->full_name)->implode(', ');
        }

        return is_array($value) ? collect($value)->flatten()->implode(', ') : (string) $value;
    }

    /**
     * Treats null, an empty string and an empty array as the same "no value".
     */
    private function valuesAreEqual(mixed $old_value, mixed $new_value): bool
    {
        $normalize = fn (mixed $value) => ($value === null || $value === '' || $value === []) ? null : $value;

        return json_encode($normalize($old_value)) === json_encode($normalize($new_value));
    }

    /**
     * Everyone a communication action should reach: a fixed `notify_user_id`, or every
     * person `notify_from_people_column_id` currently holds. Deactivated accounts are skipped.
     *
     * @return Collection<int, User>
     */
    private function resolveRecipients(BoardAutomation $automation, BoardItem $item): Collection
    {
        if ($user_id = $automation->action_params['notify_user_id'] ?? null) {
            return User::whereKey($user_id)->where('is_active', true)->get();
        }

        if ($people_column_id = $automation->action_params['notify_from_people_column_id'] ?? null) {
            $value = BoardItemValue::where('item_id', $item->id)->where('column_id', $people_column_id)->first();
            $person_ids = is_array($value?->value) ? $value->value : [];

            return $person_ids ? User::whereIn('id', $person_ids)->where('is_active', true)->get() : new Collection;
        }

        return new Collection;
    }

    /**
     * A fixed `notify_user_id`, or whoever `notify_from_people_column_id`
     * currently holds (the first assigned person, when more than one).
     */
    private function resolveNotifyTarget(BoardAutomation $automation, BoardItem $item): ?User
    {
        if ($user_id = $automation->action_params['notify_user_id'] ?? null) {
            return User::find($user_id);
        }

        if ($people_column_id = $automation->action_params['notify_from_people_column_id'] ?? null) {
            $value = BoardItemValue::where('item_id', $item->id)->where('column_id', $people_column_id)->first();
            $person_ids = is_array($value?->value) ? $value->value : [];

            return $person_ids ? User::find($person_ids[0]) : null;
        }

        return null;
    }

    /**
     * Propagates a new `group_id` onto every descendant of `$item` — mirrors
     * {@see BoardItemController::cascadeGroupToDescendants()},
     * duplicated here (rather than reused) since that method is private on
     * the controller and this service has no other reason to depend on it.
     */
    private function cascadeGroupToDescendants(BoardItem $item, int $group_id): void
    {
        $item->loadMissing('childrenRecursive');

        $flatten = function (iterable $items) use (&$flatten): array {
            $flat = [];
            foreach ($items as $child) {
                $flat[] = $child;
                $flat = array_merge($flat, $flatten($child->childrenRecursive));
            }

            return $flat;
        };

        foreach ($flatten($item->childrenRecursive) as $descendant) {
            $descendant->update(['group_id' => $group_id]);
        }
    }
}
