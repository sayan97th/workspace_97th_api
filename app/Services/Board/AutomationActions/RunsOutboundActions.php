<?php

namespace App\Services\Board\AutomationActions;

use App\Jobs\Automations\SendAutomationWebhookJob;
use App\Models\AccountTeam;
use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\Notification;
use App\Models\User;
use App\Services\Board\BoardAutomationActionOutcome;
use App\Services\Board\BoardAutomationActionRunner;
use App\Support\OutboundWebhookUrl;
use Illuminate\Support\Carbon;

/**
 * The actions of {@see BoardAutomationActionRunner} that reach a whole team or another system:
 * notify every member of a team, and post the item as JSON to a webhook URL (Microsoft Teams,
 * Zapier, Make, Twilio or any other service that accepts one).
 */
trait RunsOutboundActions
{
    /** Most members one "notify team" reaches, so a huge team never floods the notification queue. */
    private const MAX_TEAM_RECIPIENTS = 200;

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function notifyTeam(BoardAutomation $automation, array $params, ?BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $team = AccountTeam::find((int) ($params['team_id'] ?? 0));
        if (! $team) {
            return BoardAutomationActionOutcome::skipped('The team no longer exists.');
        }

        $members = $team->members()->where('users.is_active', true)->limit(self::MAX_TEAM_RECIPIENTS)->get();
        if ($members->isEmpty()) {
            return BoardAutomationActionOutcome::skipped("The team \"{$team->name}\" has no active members.");
        }
        if ($this->run_context->isDryRun()) {
            return BoardAutomationActionOutcome::success("Would notify the {$members->count()} member(s) of \"{$team->name}\".");
        }

        $board = $item?->board ?? $automation->board;
        $automation_label = $automation->name ?: 'Automation';
        $has_message = trim((string) ($params['message'] ?? '')) !== '';
        $action_target = $has_message
            ? $this->renderer->render($params['message'], $automation, $item, $actor, $context)
            : ($item ? sprintf('ran on "%s" on the Board "%s"', $item->name, $board->label) : sprintf('ran on the Board "%s"', $board->label));

        foreach ($members as $member) {
            $this->notification_service->notify(
                recipient: $member,
                actor: $actor,
                type: Notification::TYPE_AUTOMATION,
                board: $board,
                action_label: "Automation \"{$automation_label}\"",
                action_target: $action_target,
                link: $item ? "/boards/{$item->board_id}/pulses/{$item->id}" : "/boards/{$board->id}",
                board_item: $item,
            );
        }

        $this->log($automation, $item, $actor, "notified the team \"{$team->name}\" ({$members->count()} people)");

        return BoardAutomationActionOutcome::success("Notified the {$members->count()} member(s) of \"{$team->name}\".");
    }

    /**
     * Queues the webhook, the delivery itself happens in {@see SendAutomationWebhookJob}.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function sendWebhook(BoardAutomation $automation, array $params, ?BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $url = trim((string) ($params['url'] ?? ''));
        if ($problem = OutboundWebhookUrl::problem($url)) {
            return BoardAutomationActionOutcome::failed($problem);
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($this->run_context->isDryRun()) {
            return BoardAutomationActionOutcome::success("Would send the item to {$host}.");
        }

        SendAutomationWebhookJob::dispatch(
            $automation->id,
            $url,
            $this->webhookPayload($automation, $item, $actor, $context),
            is_string($params['secret'] ?? null) && $params['secret'] !== '' ? $params['secret'] : null,
            $item?->id,
            $item?->name,
        );
        $this->log($automation, $item, $actor, "sent a webhook to {$host}");

        return BoardAutomationActionOutcome::success("Sent a webhook to {$host}.");
    }

    /**
     * The JSON a webhook carries: the automation, the board, what set it off, and the item with
     * every column as display text keyed by the column title.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function webhookPayload(BoardAutomation $automation, ?BoardItem $item, ?User $actor, array $context): array
    {
        $board = $item?->board ?? $automation->board;
        $column = $context['column'] ?? null;

        $item_payload = null;
        if ($item !== null) {
            $scope = $item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;
            $columns = BoardColumn::where('board_view_id', $automation->board_view_id)->where('scope', $scope)->orderBy('position')->get();
            $values = BoardItemValue::where('item_id', $item->id)->pluck('value', 'column_id');

            $item_payload = [
                'id' => $item->id,
                'name' => $item->name,
                'group' => $item->group?->name,
                'parent_id' => $item->parent_id,
                'url' => rtrim((string) config('app.frontend_url', config('app.url')), '/')."/boards/{$item->board_id}/pulses/{$item->id}",
                'values' => $columns->mapWithKeys(fn (BoardColumn $entry) => [$entry->label => $this->renderer->displayValue($entry, $values[$entry->id] ?? null)])->all(),
            ];
        }

        return [
            'event' => 'automation.run',
            'sent_at' => Carbon::now()->toIso8601String(),
            'automation' => ['id' => $automation->id, 'name' => $automation->name],
            'board' => ['id' => $board?->id, 'name' => $board?->label],
            'trigger' => array_filter([
                'type' => $automation->trigger_type,
                'column' => $column instanceof BoardColumn ? $column->label : null,
                'old_value' => $column instanceof BoardColumn ? $this->renderer->displayValue($column, $context['old_value'] ?? null) : null,
                'new_value' => $column instanceof BoardColumn ? $this->renderer->displayValue($column, $context['new_value'] ?? null) : null,
                'actor' => $actor?->full_name,
            ], fn ($value) => $value !== null),
            'item' => $item_payload,
            'payload' => $context['payload'] ?? null,
        ];
    }
}
