<?php

namespace App\Services\Board\AutomationActions;

use App\Jobs\SendEmailJob;
use App\Mail\Automations\AutomationDigestEmail;
use App\Models\AccountTeam;
use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\User;
use App\Services\Board\AutomationConditionEvaluator;
use App\Services\Board\BoardAutomationActionOutcome;
use App\Services\Board\BoardAutomationActionRunner;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The digest action of {@see BoardAutomationActionRunner}: one email with a table of the tab's items
 * that pass a filter, such as "every Monday at 09:00, email the team every item whose Status is not
 * Done". The filter uses the same rules as the board's Advanced filters and the automation's own
 * conditions. It needs no triggering item, so a recurring trigger can send it on a schedule.
 */
trait RunsDigestActions
{
    /** Most rows one digest shows, the rest are counted. */
    private const MAX_DIGEST_ITEMS = 200;

    private const DEFAULT_DIGEST_ITEMS = 50;

    /** Most items one digest looks at, so a huge board never stalls the scheduler. */
    private const MAX_DIGEST_SCAN = 5000;

    private const MAX_DIGEST_COLUMNS = 8;

    private const MAX_DIGEST_RECIPIENTS = 100;

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function sendDigest(BoardAutomation $automation, array $params, ?BoardItem $item, ?User $actor, array $context): BoardAutomationActionOutcome
    {
        $recipients = $this->digestRecipients($params);
        if ($recipients->isEmpty()) {
            return BoardAutomationActionOutcome::skipped('Found nobody with an email address to send the digest to.');
        }

        $digest = $this->digestRows($automation, $params, $actor);
        if ($digest['total'] === 0 && empty($params['send_when_empty'])) {
            return BoardAutomationActionOutcome::skipped('No item matched the digest filter, so nothing was sent.');
        }

        $names = $recipients->map(fn (User $user) => $user->full_name)->implode(', ');
        if ($this->run_context->isDryRun()) {
            return BoardAutomationActionOutcome::success("Would email a digest of {$digest['total']} item(s) to {$names}.");
        }

        $board = $automation->board;
        $subject = $this->renderer->renderPlain($params['subject'] ?? null, 'Digest of '.($board?->label ?? 'your board'), $automation, $item, $actor, $context);
        $intro = trim((string) ($params['message'] ?? '')) === '' ? '' : $this->renderer->render($params['message'], $automation, $item, $actor, $context);

        foreach ($recipients as $recipient) {
            SendEmailJob::dispatch(
                new AutomationDigestEmail($subject, $intro, $board?->label, "/boards/{$automation->board_id}", $digest['columns'], $digest['rows'], $digest['total']),
                $recipient->email,
            );
        }
        $this->log($automation, $item, $actor, "emailed a digest of {$digest['total']} item(s) to {$names}");

        return BoardAutomationActionOutcome::success("Emailed a digest of {$digest['total']} item(s) to {$names}.");
    }

    /**
     * The active people with an email address the digest goes to: `user_ids` and the members of `team_id`.
     *
     * @param  array<string, mixed>  $params
     * @return Collection<int, User>
     */
    private function digestRecipients(array $params): Collection
    {
        $ids = collect((array) ($params['user_ids'] ?? []))->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id);
        if (! empty($params['team_id']) && ($team = AccountTeam::find((int) $params['team_id']))) {
            $ids = $ids->merge($team->members()->pluck('users.id')->map(fn ($id) => (int) $id));
        }
        $ids = $ids->unique()->values();

        return $ids->isEmpty()
            ? new Collection
            : User::whereIn('id', $ids)->where('is_active', true)->whereNotNull('email')->limit(self::MAX_DIGEST_RECIPIENTS)->get();
    }

    /**
     * The rows of the digest: top-level, not archived items of the tab (of `target_group_id` when
     * set) that pass `digest_rules`, oldest first, with the display value of every `column_ids`.
     *
     * @param  array<string, mixed>  $params
     * @return array{columns: array<int, string>, rows: array<int, array{name: string, group: string, url: string, values: array<int, string>}>, total: int}
     */
    private function digestRows(BoardAutomation $automation, array $params, ?User $actor): array
    {
        $columns = AutomationConditionEvaluator::tabColumns($automation);
        $shown = collect((array) ($params['column_ids'] ?? []))
            ->map(fn ($id) => $columns->get((string) $id))
            ->filter(fn (?BoardColumn $column) => $column !== null && $column->scope === BoardColumn::SCOPE_ITEM)
            ->take(self::MAX_DIGEST_COLUMNS)
            ->values();

        $rules = array_values(array_filter((array) ($params['digest_rules'] ?? []), 'is_array'));
        $filter_state = [
            'advanced_filter_rows' => $rules,
            'advanced_filter_groups' => [],
            'advanced_filter_operator' => ($params['digest_operator'] ?? 'and') === 'or' ? 'or' : 'and',
        ];
        $limit = max(1, min(self::MAX_DIGEST_ITEMS, (int) ($params['max_items'] ?? self::DEFAULT_DIGEST_ITEMS)));
        $frontend_url = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $rows = [];
        $total = 0;
        $scanned = 0;
        BoardItem::query()
            ->whereNull('parent_id')
            ->where('is_archived', false)
            ->whereHas('group', fn ($query) => $query->where('board_view_id', $automation->board_view_id)->where('is_archived', false))
            ->when(! empty($params['target_group_id']), fn ($query) => $query->where('group_id', (int) $params['target_group_id']))
            ->with(['values', 'group'])
            ->orderBy('id')
            ->chunkById(200, function (EloquentCollection $items) use ($automation, $actor, $columns, $shown, $filter_state, $rules, $limit, $frontend_url, &$rows, &$total, &$scanned) {
                foreach ($items as $candidate) {
                    if (++$scanned > self::MAX_DIGEST_SCAN) {
                        return false;
                    }
                    if ($rules !== [] && ! $this->condition_evaluator->matches($automation, $candidate, $actor, $filter_state, $columns)) {
                        continue;
                    }

                    $total++;
                    if (count($rows) < $limit) {
                        $rows[] = [
                            'name' => (string) $candidate->name,
                            'group' => (string) ($candidate->group?->name ?? ''),
                            'url' => "{$frontend_url}/boards/{$candidate->board_id}/pulses/{$candidate->id}",
                            'values' => $shown->map(fn (BoardColumn $column) => $this->renderer->displayValue($column, $candidate->values->firstWhere('column_id', $column->id)?->value))->all(),
                        ];
                    }
                }

                return true;
            });

        return ['columns' => $shown->map(fn (BoardColumn $column) => (string) $column->label)->all(), 'rows' => $rows, 'total' => $total];
    }
}
