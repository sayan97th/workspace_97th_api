<?php

namespace App\Services\Board\AutomationActions;

use App\Models\AccountTeam;
use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\FeedFollow;
use App\Models\Notification;
use App\Models\User;
use App\Services\Board\BoardAutomationActionOutcome;
use App\Services\Board\BoardAutomationActionRunner;
use Illuminate\Support\Collection;

/**
 * The actions of {@see BoardAutomationActionRunner} about who follows an item, monday's item
 * "subscribers": subscribe people to the item, unsubscribe them, and notify everyone subscribed.
 * A subscription is a {@see FeedFollow} of the item, the same one the Update Feed's "Following" tab
 * and auto follow use. People who follow the whole board count as subscribers of every item on it.
 */
trait RunsSubscriberActions
{
    /** Most people one subscribe or unsubscribe action changes. */
    private const MAX_SUBSCRIPTION_PEOPLE = 200;

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function subscribePeople(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $workspace_id = $item->board?->workspace_id;
        $people = $this->selectedPeople($automation, $params, $item, $actor, $context)
            ->filter(fn (User $person) => $workspace_id === null || $person->workspaces()->where('workspaces.id', $workspace_id)->exists())
            ->values();
        if ($people->isEmpty()) {
            return BoardAutomationActionOutcome::skipped('Found nobody in this workspace to subscribe.');
        }

        $already = FeedFollow::where('target_type', FeedFollow::TYPE_ITEM)->where('target_id', $item->id)->whereIn('user_id', $people->pluck('id'))->pluck('user_id')->all();
        $new_people = $people->reject(fn (User $person) => in_array($person->id, $already, true))->values();
        if ($new_people->isEmpty()) {
            return BoardAutomationActionOutcome::skipped('Everyone was already subscribed to the item.');
        }

        $names = $new_people->map(fn (User $person) => $person->full_name)->implode(', ');
        if ($this->run_context->isDryRun()) {
            return BoardAutomationActionOutcome::success("Would subscribe {$names} to the item.");
        }

        foreach ($new_people as $person) {
            FeedFollow::firstOrCreate(['user_id' => $person->id, 'target_type' => FeedFollow::TYPE_ITEM, 'target_id' => $item->id]);
        }
        $this->log($automation, $item, $actor, "subscribed {$names} to \"{$item->name}\"");

        return BoardAutomationActionOutcome::success("Subscribed {$names} to the item.");
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function unsubscribePeople(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $query = FeedFollow::where('target_type', FeedFollow::TYPE_ITEM)->where('target_id', $item->id);
        if (empty($params['everyone'])) {
            $people = $this->selectedPeople($automation, $params, $item, $actor, $context);
            if ($people->isEmpty()) {
                return BoardAutomationActionOutcome::skipped('Found nobody to unsubscribe.');
            }
            $query->whereIn('user_id', $people->pluck('id'));
        }

        $follows = $query->with('user')->get();
        if ($follows->isEmpty()) {
            return BoardAutomationActionOutcome::skipped('Nobody to unsubscribe was subscribed to the item.');
        }

        $names = $follows->map(fn (FeedFollow $follow) => $follow->user?->full_name ?? 'a person')->implode(', ');
        if ($this->run_context->isDryRun()) {
            return BoardAutomationActionOutcome::success("Would unsubscribe {$names} from the item.");
        }

        FeedFollow::whereIn('id', $follows->pluck('id'))->delete();
        $this->log($automation, $item, $actor, "unsubscribed {$names} from \"{$item->name}\"");

        return BoardAutomationActionOutcome::success("Unsubscribed {$names} from the item.");
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function notifySubscribers(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $subscribers = $this->dynamic_values->subscribers($item);
        if (empty($params['include_actor']) && $actor !== null) {
            $subscribers = $subscribers->reject(fn (User $person) => $person->id === $actor->id)->values();
        }
        if ($subscribers->isEmpty()) {
            return BoardAutomationActionOutcome::skipped('The item has no subscribers to notify.');
        }
        if ($this->run_context->isDryRun()) {
            return BoardAutomationActionOutcome::success("Would notify the {$subscribers->count()} subscriber(s) of the item.");
        }

        $board = $item->board ?? $automation->board;
        $automation_label = $automation->name ?: 'Automation';
        $action_target = trim((string) ($params['message'] ?? '')) !== ''
            ? $this->renderer->render($params['message'], $automation, $item, $actor, $context)
            : sprintf('ran on "%s" on the Board "%s"', $item->name, $board->label);

        foreach ($subscribers as $subscriber) {
            $this->notification_service->notify(
                recipient: $subscriber,
                actor: $actor,
                type: Notification::TYPE_AUTOMATION,
                board: $board,
                action_label: "Automation \"{$automation_label}\"",
                action_target: $action_target,
                link: "/boards/{$item->board_id}/pulses/{$item->id}",
                board_item: $item,
            );
        }
        $this->log($automation, $item, $actor, "notified the {$subscribers->count()} subscriber(s) of \"{$item->name}\"");

        return BoardAutomationActionOutcome::success("Notified the {$subscribers->count()} subscriber(s) of the item.");
    }

    /**
     * The active people a subscribe or unsubscribe action names: the fixed `user_ids`, whoever the
     * people column `notify_from_people_column_id` holds on the item, the members of `team_id`, and
     * the person `recipient_source` stands for on this run.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     * @return Collection<int, User>
     */
    private function selectedPeople(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor, array $context): Collection
    {
        $ids = collect((array) ($params['user_ids'] ?? []))->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id);

        if (! empty($params['notify_from_people_column_id'])) {
            $column = BoardColumn::where('board_view_id', $automation->board_view_id)->find((int) $params['notify_from_people_column_id']);
            $subject = $this->subjectForColumn($item, $column);
            if ($column && $subject) {
                $ids = $ids->merge(array_map('intval', $this->peopleIds($this->currentValue($subject, $column))));
            }
        }

        if (! empty($params['team_id']) && ($team = AccountTeam::find((int) $params['team_id']))) {
            $ids = $ids->merge($team->members()->pluck('users.id')->map(fn ($id) => (int) $id));
        }

        $source = $params['recipient_source'] ?? null;
        if (is_string($source) && $source !== 'subscribers' && in_array($source, BoardAutomation::RECIPIENT_SOURCES, true)) {
            $ids = $ids->merge(array_map('intval', $this->dynamic_values->personIds(['source' => $source], $item, $actor, $automation, $context)));
        }

        $ids = $ids->unique()->values();
        if ($ids->isEmpty()) {
            return new Collection;
        }

        return User::whereIn('id', $ids)->where('is_active', true)->limit(self::MAX_SUBSCRIPTION_PEOPLE)->get();
    }
}
