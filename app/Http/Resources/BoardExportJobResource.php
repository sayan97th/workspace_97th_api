<?php

namespace App\Http\Resources;

use App\Models\BoardExportJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * One export run as the export dialog reads it, the same payload over REST
 * and over the `board_export_progress` broadcast.
 *
 * `download_url` is a short lived signed path (relative, so it survives a
 * proxy or a different public host) the browser can open directly: the file
 * streams from the server to disk without ever passing through JavaScript
 * memory, however large it is.
 *
 * @mixin BoardExportJob
 */
class BoardExportJobResource extends JsonResource
{
    /** How long one signed download link stays valid. A fresh one comes with every status read. */
    public const DOWNLOAD_LINK_MINUTES = 30;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'group_id' => $this->group_id,
            'status' => $this->status,
            'layout' => $this->layout(),
            'include_subitems' => (bool) ($this->options['include_subitems'] ?? true),
            'include_hidden_columns' => (bool) ($this->options['include_hidden_columns'] ?? true),
            'file_name' => $this->file_name,
            'file_size_bytes' => $this->file_size_bytes,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'column_count' => $this->column_count,
            'percent' => $this->percent(),
            'cancel_requested' => $this->cancel_requested,
            'error_message' => $this->error_message,
            'download_url' => $this->isDownloadable()
                ? URL::temporarySignedRoute('board-exports.download', now()->addMinutes(self::DOWNLOAD_LINK_MINUTES), ['export_job' => $this->id], absolute: false)
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
