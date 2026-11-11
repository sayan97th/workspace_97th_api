<?php

namespace App\Services\Board;

use App\Events\BoardAutomationsRan;
use App\Http\Middleware\AttachAutomationEffects;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardItem;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps track of what the automations of one request (or job, or scheduler run) did, so the
 * people looking at the board see it right away instead of after a page refresh.
 *
 * Two ways out, both fed from the same records:
 * - {@see responsePayload()}: the `automation_effects` key the {@see AttachAutomationEffects}
 *   middleware adds to the JSON response of the request that set the automations off, so the
 *   person who made the change sees the result with the same round trip.
 * - {@see broadcastPending()}: one {@see BoardAutomationsRan} event per board, for everyone else
 *   with the board open, and for runs nobody waits on (scheduled triggers, "wait" steps, webhooks).
 *
 * Only ids and the run log lines travel, the rows themselves are read again by the board through
 * `GET /api/boards/{id}/items?item_ids[]=`, so a viewer never receives a row they could not load.
 *
 * Test runs ({@see AutomationRunContext::isDryRun()}) are rolled back, they are never recorded.
 */
class AutomationEffectCollector
{
    /** Actions that add, remove or reorder whole groups, the board reloads its groups after them. */
    private const STRUCTURE_ACTIONS = [
        BoardAutomation::ACTION_CREATE_GROUP,
        BoardAutomation::ACTION_DUPLICATE_GROUP,
        BoardAutomation::ACTION_ARCHIVE_GROUP,
        BoardAutomation::ACTION_GROUP_ITEMS,
        BoardAutomation::ACTION_SORT_GROUP,
    ];

    /** A broadcast carries at most this many ids, past it the board reloads every loaded group instead. */
    private const MAX_BROADCAST_ITEMS = 200;

    /** The response carries at most this many run lines, the oldest ones are dropped. */
    private const MAX_RUN_LINES = 20;

    /** @var array<int, array<int, true>> board id => item ids touched on that board */
    private array $item_ids = [];

    /** @var array<int, true> boards whose groups changed */
    private array $structure_board_ids = [];

    /** @var array<int, array<string, mixed>> one line per finished step, see {@see recordRun()} */
    private array $runs = [];

    /** @var array<int, string> ids of the broadcasts already sent for this request */
    private array $broadcast_ids = [];

    /** @var array<int, array<int, true>> touched since the last broadcast, same shape as `$item_ids` */
    private array $pending_item_ids = [];

    /** @var array<int, true> */
    private array $pending_structure_board_ids = [];

    /** @var array<int, array<string, mixed>> */
    private array $pending_runs = [];

    private ?int $actor_id = null;

    public function __construct(private readonly AutomationRunContext $run_context) {}

    /**
     * An item the running automation changed, created, moved, archived or deleted.
     */
    public function recordItem(int $board_id, ?int $item_id): void
    {
        if ($this->run_context->isDryRun() || $this->run_context->current() === null) {
            return;
        }

        $this->item_ids[$board_id] ??= [];
        $this->pending_item_ids[$board_id] ??= [];
        if ($item_id !== null) {
            $this->item_ids[$board_id][$item_id] = true;
            $this->pending_item_ids[$board_id][$item_id] = true;
        }
    }

    /**
     * One finished step of a run, with the item it acted on. A step that only skipped (its
     * conditions were not met, the item was already there) changed nothing, it is left out.
     */
    public function recordRun(BoardAutomation $automation, ?BoardItem $item, ?int $actor_id, string $action_type, string $status, string $message, ?int $run_log_id, ?string $run_uuid): void
    {
        if ($this->run_context->isDryRun() || $status === BoardAutomationRunLog::STATUS_SKIPPED) {
            return;
        }

        $this->actor_id ??= $actor_id;

        if ($item !== null) {
            $this->recordItem($automation->board_id, $item->id);
            // "Move to board" leaves the item on another board, that board shows it too.
            if ($item->board_id !== $automation->board_id) {
                $this->recordItem((int) $item->board_id, $item->id);
                $this->markStructure((int) $item->board_id);
            }
        }

        if (in_array($action_type, self::STRUCTURE_ACTIONS, true)) {
            $this->markStructure($automation->board_id);
        }

        $line = [
            'run_log_id' => $run_log_id,
            'run_uuid' => $run_uuid,
            'automation_id' => $automation->exists ? $automation->id : null,
            'automation_name' => $automation->name ?: null,
            'board_id' => $automation->board_id,
            'item_id' => $item?->id,
            'item_name' => $item ? Str::limit((string) $item->name, 120) : null,
            'action_type' => $action_type,
            'status' => $status,
            'message' => $message,
            'ran_at' => now()->toIso8601String(),
        ];

        $this->runs[] = $line;
        $this->pending_runs[] = $line;
        if (count($this->runs) > self::MAX_RUN_LINES) {
            array_shift($this->runs);
        }
    }

    public function hasEffects(): bool
    {
        return $this->item_ids !== [] || $this->runs !== [];
    }

    /**
     * The `automation_effects` key of the response, null when no automation ran.
     *
     * @return array{boards: array<int, array{board_id: int, item_ids: array<int, int>, needs_structure_refresh: bool}>, runs: array<int, array<string, mixed>>, broadcast_ids: array<int, string>}|null
     */
    public function responsePayload(): ?array
    {
        if (! $this->hasEffects()) {
            return null;
        }

        return [
            'boards' => $this->boardsPayload($this->item_ids, $this->structure_board_ids),
            'runs' => array_values($this->runs),
            'broadcast_ids' => $this->broadcast_ids,
        ];
    }

    /**
     * Sends what changed since the last call to everyone with the board open. Called by
     * {@see BoardAutomationService} once the outermost automation of a chain finished, so a
     * chain of five automations is one event, not five.
     */
    public function broadcastPending(): void
    {
        if ($this->pending_item_ids === [] && $this->pending_runs === []) {
            return;
        }

        $boards = $this->boardsPayload($this->pending_item_ids, $this->pending_structure_board_ids);
        $runs = $this->pending_runs;
        $this->pending_item_ids = [];
        $this->pending_structure_board_ids = [];
        $this->pending_runs = [];

        foreach ($boards as $board) {
            $broadcast_id = (string) Str::uuid();
            $this->broadcast_ids[] = $broadcast_id;
            $is_too_big = count($board['item_ids']) > self::MAX_BROADCAST_ITEMS;

            try {
                broadcast(new BoardAutomationsRan(
                    board_id: $board['board_id'],
                    broadcast_id: $broadcast_id,
                    actor_id: $this->actor_id,
                    item_ids: $is_too_big ? [] : $board['item_ids'],
                    needs_structure_refresh: $board['needs_structure_refresh'] || $is_too_big,
                    runs: array_values(array_filter($runs, fn (array $line) => $line['board_id'] === $board['board_id'])),
                ));
            } catch (Throwable $exception) {
                // The socket is a nicety, the response already carries the effects for the actor.
                report($exception);
            }
        }
    }

    private function markStructure(int $board_id): void
    {
        $this->structure_board_ids[$board_id] = true;
        $this->pending_structure_board_ids[$board_id] = true;
        $this->item_ids[$board_id] ??= [];
        $this->pending_item_ids[$board_id] ??= [];
    }

    /**
     * Subitems are shown under their parent row, so a touched subitem is reported as its root
     * item, the board reloads that whole row with its subitems. Deleted items keep their own id,
     * the board drops a row it asked for that does not come back.
     *
     * @param  array<int, array<int, true>>  $item_ids
     * @param  array<int, true>  $structure_board_ids
     * @return array<int, array{board_id: int, item_ids: array<int, int>, needs_structure_refresh: bool}>
     */
    private function boardsPayload(array $item_ids, array $structure_board_ids): array
    {
        $board_ids = array_unique([...array_keys($item_ids), ...array_keys($structure_board_ids)]);
        $all_ids = [];
        foreach ($item_ids as $ids) {
            $all_ids = [...$all_ids, ...array_keys($ids)];
        }
        $parent_of = $this->rootIds(array_values(array_unique($all_ids)));

        $boards = [];
        foreach ($board_ids as $board_id) {
            $root_ids = [];
            foreach (array_keys($item_ids[$board_id] ?? []) as $item_id) {
                $root_ids[$parent_of[$item_id] ?? $item_id] = true;
            }
            $boards[] = [
                'board_id' => (int) $board_id,
                'item_ids' => array_map('intval', array_keys($root_ids)),
                'needs_structure_refresh' => isset($structure_board_ids[$board_id]),
            ];
        }

        return $boards;
    }

    /**
     * @param  array<int, int>  $item_ids
     * @return array<int, int> item id => id of its root item, only for subitems
     */
    private function rootIds(array $item_ids): array
    {
        if ($item_ids === []) {
            return [];
        }

        $parents = BoardItem::withTrashed()->whereIn('id', $item_ids)->whereNotNull('parent_id')->pluck('parent_id', 'id')->all();
        $roots = [];
        foreach ($parents as $item_id => $parent_id) {
            $root_id = (int) $parent_id;
            // Walks up past nested subitems, a few levels at most.
            for ($depth = 0; $depth < 5; $depth++) {
                $next = BoardItem::withTrashed()->whereKey($root_id)->value('parent_id');
                if ($next === null) {
                    break;
                }
                $root_id = (int) $next;
            }
            $roots[(int) $item_id] = $root_id;
        }

        return $roots;
    }
}
