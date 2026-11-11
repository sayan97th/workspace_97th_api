<?php

namespace App\Events;

use App\Http\Resources\BoardExportJobResource;
use App\Jobs\ProcessBoardExportJob;
use App\Models\BoardExportJob;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by {@see ProcessBoardExportJob} as it writes the workbook (and once
 * more when it finishes, fails or is cancelled), so the export dialog's
 * progress bar moves live. Sent on the exporting person's private
 * `board-export.{user_id}` channel, with the same payload as the REST
 * status endpoint. Broadcast "now" since it already fires from a queued job.
 */
class BoardExportProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public BoardExportJob $export_job) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('board-export.'.$this->export_job->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'board_export_progress';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return (new BoardExportJobResource($this->export_job))->resolve();
    }
}
