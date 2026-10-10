<?php

namespace App\Services\Board\AutomationActions;

use App\Jobs\SyncCalendarEventJob;
use App\Models\BoardAutomation;
use App\Models\BoardItem;
use App\Models\ExternalAccount;
use App\Models\User;
use App\Services\Board\BoardAutomationActionOutcome;
use App\Services\ExternalAccounts\CalendarEventSyncer;

/**
 * Actions that go through a member's own Google account: keeping a Google Calendar event for the
 * item. Like webhooks and emails the call itself happens in a queued job, a test run only says
 * what would be sent.
 */
trait RunsIntegrationActions
{
    /**
     * @param  array<string, mixed>  $params
     */
    private function syncCalendarEvent(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $account = ExternalAccount::query()->find((int) ($params['external_account_id'] ?? 0));
        if (! $account) {
            return BoardAutomationActionOutcome::failed('The Google account this automation used was disconnected. Edit the automation and choose an account again.');
        }

        $calendar_name = (string) ($params['calendar_name'] ?? 'Google Calendar');
        $has_date = app(CalendarEventSyncer::class)->eventPayload($automation, $params, $item) !== null;

        if ($this->run_context->isDryRun()) {
            return $has_date
                ? BoardAutomationActionOutcome::success("Would create or update the event of \"{$item->name}\" in {$calendar_name}.")
                : BoardAutomationActionOutcome::skipped("\"{$item->name}\" has no date, no event would be created.");
        }

        SyncCalendarEventJob::dispatch($automation->id, $item->id, $params);
        $this->log($automation, $item, $actor, "synced \"{$item->name}\" to {$calendar_name}");

        return BoardAutomationActionOutcome::success($has_date
            ? "Syncing the event of \"{$item->name}\" to {$calendar_name}."
            : "\"{$item->name}\" has no date, its event in {$calendar_name} is removed if it had one.");
    }
}
