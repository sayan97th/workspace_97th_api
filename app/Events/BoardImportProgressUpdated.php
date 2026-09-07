<?php

namespace App\Events;

use App\Http\Resources\BoardImportJobResource;
use App\Jobs\ProcessBoardImportJob;
use App\Models\BoardImportJob;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by {@see ProcessBoardImportJob} after every chunk it
 * commits (and once more on completion/failure/cancellation), so the "Import
 * items" wizard's progress bar updates live rather than the viewer staring
 * at a stalled percentage until the whole file is done. Delivered over the
 * importing user's private `board-import.{user_id}` channel (see
 * routes/channels.php) — mirrors {@see NewNotification}, except
 * {@see ShouldBroadcastNow} rather than queued, since this already fires
 * from inside a queued job; queuing the broadcast itself would just add an
 * extra hop of latency to a progress update whose whole point is to be timely.
 */
class BoardImportProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public BoardImportJob $import_job) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('board-import.'.$this->import_job->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'board_import_progress';
    }

    /**
     * Reuses {@see BoardImportJobResource} so the websocket payload is
     * byte-identical to the REST status-poll payload.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return (new BoardImportJobResource($this->import_job))->resolve();
    }
}
