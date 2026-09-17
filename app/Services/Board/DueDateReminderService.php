<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardDueDateReminderRun;
use App\Models\BoardItemValue;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Carbon;

/**
 * Scans every Date column with a reminder configured (`config.reminder =
 * {enabled, days_before}`, set from the column header's Date menu) and
 * notifies every person assigned in a People column on the same item once
 * its due date is `days_before` days away. Mirrors
 * {@see BoardAutomationService::runDueDateTriggers()}'s scan-and-dedupe shape:
 * daily scheduled command, {@see BoardDueDateReminderRun} dedupes so a second
 * run the same day never double-notifies.
 */
class DueDateReminderService
{
    public function __construct(private readonly NotificationService $notification_service) {}

    public function run(): int
    {
        $today = Carbon::today();
        $notified_count = 0;

        $reminder_columns = BoardColumn::query()
            ->where('type', BoardColumn::TYPE_DATE)
            ->whereNotNull('config')
            ->whereRaw("JSON_EXTRACT(config, '$.reminder.enabled') = true")
            ->get();

        foreach ($reminder_columns as $column) {
            $days_before = (int) ($column->config['reminder']['days_before'] ?? 0);
            $due_on = $today->copy()->addDays($days_before)->toDateString();

            $already_ran_item_ids = BoardDueDateReminderRun::where('column_id', $column->id)
                ->where('ran_on', $today->toDateString())
                ->pluck('board_item_id');

            $due_values = BoardItemValue::where('column_id', $column->id)
                ->whereRaw('JSON_UNQUOTE(`value`) = ?', [$due_on])
                ->whereNotIn('item_id', $already_ran_item_ids)
                ->with('item.board')
                ->get();

            $people_column_ids = BoardColumn::query()
                ->where('board_view_id', $column->board_view_id)
                ->where('scope', $column->scope)
                ->where('type', BoardColumn::TYPE_PEOPLE)
                ->pluck('id');

            foreach ($due_values as $due_value) {
                // `BoardItem` uses `SoftDeletes`, so this can be null despite the
                // FK cascade, which only ever fires on a hard delete.
                $item = $due_value->item;
                if (! $item) {
                    continue;
                }

                $recipient_ids = BoardItemValue::where('item_id', $item->id)
                    ->whereIn('column_id', $people_column_ids)
                    ->get()
                    ->flatMap(fn (BoardItemValue $value) => is_array($value->value) ? $value->value : [])
                    ->unique();

                foreach ($recipient_ids as $recipient_id) {
                    $recipient = User::find($recipient_id);
                    if (! $recipient) {
                        continue;
                    }

                    $this->notification_service->notify(
                        recipient: $recipient,
                        actor: null,
                        type: Notification::TYPE_DUE_DATE_REMINDER,
                        board: $item->board,
                        action_label: 'Due date reminder',
                        action_target: sprintf('"%s" is due on "%s"', $item->name, $due_on),
                        link: "/boards/{$item->board_id}/pulses/{$item->id}",
                        board_item: $item,
                    );
                    $notified_count++;
                }

                BoardDueDateReminderRun::create([
                    'column_id' => $column->id,
                    'board_item_id' => $item->id,
                    'ran_on' => $today->toDateString(),
                ]);
            }
        }

        return $notified_count;
    }
}
