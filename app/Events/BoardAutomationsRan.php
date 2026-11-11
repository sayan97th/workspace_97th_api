<?php

namespace App\Events;

use App\Services\Board\AutomationEffectCollector;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once the automations of a chain finished on a board, see {@see AutomationEffectCollector}.
 * Every open view of that board reloads the listed rows (and its groups when
 * `needs_structure_refresh`), so the result shows without a page refresh.
 *
 * Sent right away rather than through the queue: the whole point is that it is instant, and a
 * busy or stopped queue worker must not delay it. Carries ids and the run log lines only.
 */
class BoardAutomationsRan implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<int, int>  $item_ids  root item ids to reload
     * @param  array<int, array<string, mixed>>  $runs  the run log lines, see {@see AutomationEffectCollector::recordRun()}
     */
    public function __construct(
        public int $board_id,
        public string $broadcast_id,
        public ?int $actor_id,
        public array $item_ids,
        public bool $needs_structure_refresh,
        public array $runs,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('board-automations.'.$this->board_id)];
    }

    public function broadcastAs(): string
    {
        return 'board_automations_ran';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'board_id' => $this->board_id,
            'broadcast_id' => $this->broadcast_id,
            'actor_id' => $this->actor_id,
            'item_ids' => $this->item_ids,
            'needs_structure_refresh' => $this->needs_structure_refresh,
            'runs' => array_slice($this->runs, -10),
        ];
    }
}
