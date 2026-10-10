<?php

namespace App\Services\ExternalAccounts;

use App\Jobs\DeleteCalendarEventsJob;
use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemCalendarEvent;
use App\Models\BoardItemValue;
use App\Models\ExternalAccount;
use App\Services\Board\BoardAutomationMessageRenderer;
use Carbon\CarbonImmutable;

/**
 * Keeps one Google Calendar event per item and "create an event in Google Calendar" automation,
 * like monday.com's Google Calendar sync: the item's date (or timeline) becomes the event's day,
 * its name the title, and later changes update the same event. An item without a date, archived
 * or deleted, loses its event.
 */
class CalendarEventSyncer
{
    /** Length of an event made from a date with a time of day. */
    private const TIMED_EVENT_MINUTES = 60;

    public function __construct(
        private readonly GoogleCalendarClient $calendar,
        private readonly BoardAutomationMessageRenderer $renderer,
    ) {}

    /**
     * Creates or updates the item's event. Returns what happened, for the run history.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws ExternalAccountException
     */
    public function sync(BoardAutomation $automation, array $params, BoardItem $item): string
    {
        $account = ExternalAccount::query()->find((int) ($params['external_account_id'] ?? 0));
        if (! $account) {
            throw new ExternalAccountException(ExternalAccountException::CODE_TOKEN_REVOKED, 'The Google account this automation used was disconnected.');
        }

        $calendar_id = (string) ($params['calendar_id'] ?? '');
        $existing = BoardItemCalendarEvent::query()->where('board_automation_id', $automation->id)->where('board_item_id', $item->id)->first();
        $event = $this->eventPayload($automation, $params, $item);

        if ($event === null) {
            if ($existing) {
                $this->calendar->deleteEvent($account, $existing->calendar_id, $existing->event_id);
                $existing->delete();

                return "Removed the Google Calendar event of \"{$item->name}\", it has no date any more.";
            }

            return "\"{$item->name}\" has no date, so no Google Calendar event was created.";
        }

        // The calendar was changed on the automation, the old event goes away with the old calendar.
        if ($existing && ($existing->calendar_id !== $calendar_id || $existing->external_account_id !== $account->id)) {
            $this->deleteQuietly($existing);
            $existing->delete();
            $existing = null;
        }

        $event_id = $this->calendar->saveEvent($account, $calendar_id, $existing?->event_id, $event);
        BoardItemCalendarEvent::updateOrCreate(
            ['board_automation_id' => $automation->id, 'board_item_id' => $item->id],
            ['external_account_id' => $account->id, 'calendar_id' => $calendar_id, 'event_id' => $event_id, 'synced_at' => now()],
        );
        $account->forceFill(['last_used_at' => now()])->save();

        $calendar_name = (string) ($params['calendar_name'] ?? 'Google Calendar');

        return $existing ? "Updated the event of \"{$item->name}\" in {$calendar_name}." : "Created an event for \"{$item->name}\" in {$calendar_name}.";
    }

    /**
     * The event the item should have, null when its date column is empty.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    public function eventPayload(BoardAutomation $automation, array $params, BoardItem $item): ?array
    {
        $column = BoardColumn::query()->find((int) ($params['date_column_id'] ?? 0));
        if (! $column) {
            return null;
        }

        $value = BoardItemValue::query()->where('item_id', $item->id)->where('column_id', $column->id)->value('value');
        [$start, $end] = $column->type === BoardColumn::TYPE_TIMELINE
            ? [is_array($value) ? ($value['start'] ?? null) : null, is_array($value) ? ($value['end'] ?? $value['start'] ?? null) : null]
            : [$value, $value];

        $start_day = $this->day($start);
        $end_day = $this->day($end) ?? $start_day;
        if ($start_day === null) {
            return null;
        }

        $title = trim($this->renderer->renderPlain((string) ($params['title_template'] ?? ''), '', $automation, $item, null, []));
        $board = $item->board;
        $link = rtrim((string) config('app.frontend_url'), '/')."/boards/{$item->board_id}/pulses/{$item->id}";
        $time = $column->type === BoardColumn::TYPE_DATE ? $this->time($start) : null;
        $timezone = $automation->responsibleUser()?->timezone ?: (string) config('app.timezone', 'UTC');

        if ($time !== null) {
            $starts_at = CarbonImmutable::parse("{$start_day} {$time}", $timezone);
            $when = [
                'start' => ['dateTime' => $starts_at->toIso8601String(), 'timeZone' => $timezone],
                'end' => ['dateTime' => $starts_at->addMinutes(self::TIMED_EVENT_MINUTES)->toIso8601String(), 'timeZone' => $timezone],
            ];
        } else {
            // An all day event ends on the day after its last day.
            $when = [
                'start' => ['date' => $start_day],
                'end' => ['date' => CarbonImmutable::parse(max($start_day, $end_day))->addDay()->toDateString()],
            ];
        }

        return [
            'summary' => mb_substr($title !== '' ? $title : (string) $item->name, 0, 250),
            'description' => "{$item->name} on the board \"{$board?->label}\".\n{$link}",
            'source' => ['title' => (string) ($board?->label ?: config('app.name')), 'url' => $link],
            ...$when,
        ];
    }

    /**
     * Removes every event kept for the item, in the background, when it is archived or deleted.
     */
    public function forgetItem(BoardItem $item): void
    {
        $events = BoardItemCalendarEvent::query()->where('board_item_id', $item->id)->get();
        if ($events->isEmpty()) {
            return;
        }

        DeleteCalendarEventsJob::dispatch($events->map(fn (BoardItemCalendarEvent $event) => [
            'external_account_id' => $event->external_account_id,
            'calendar_id' => $event->calendar_id,
            'event_id' => $event->event_id,
        ])->all());

        BoardItemCalendarEvent::query()->whereKey($events->modelKeys())->delete();
    }

    private function deleteQuietly(BoardItemCalendarEvent $event): void
    {
        try {
            $this->calendar->deleteEvent($event->account, $event->calendar_id, $event->event_id);
        } catch (ExternalAccountException) {
            // The old calendar may not be reachable any more, the new event is what matters.
        }
    }

    private function day(mixed $value): ?string
    {
        $day = is_string($value) ? substr($value, 0, 10) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : null;
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ](\d{2}:\d{2})/', $value, $matches) === 1 ? $matches[1] : null;
    }
}
