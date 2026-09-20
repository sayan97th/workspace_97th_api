<?php

namespace App\Http\Resources;

use App\Models\BoardAutomation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin BoardAutomation
 */
class BoardAutomationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'board_view_id' => $this->board_view_id,
            'name' => $this->name,
            'is_enabled' => $this->is_enabled,
            'trigger_type' => $this->trigger_type,
            'trigger_column_id' => $this->trigger_column_id,
            'trigger_value' => $this->trigger_value,
            'action_type' => $this->action_type,
            'action_params' => $this->action_params,
            'created_at' => $this->created_at,
            // Filled in by `BoardAutomationController`, which loads the run stats and the creator.
            'run_count' => (int) ($this->run_logs_count ?? 0),
            'last_run_at' => $this->run_logs_max_created_at ? Carbon::parse($this->run_logs_max_created_at)->toIso8601String() : null,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->full_name,
            ] : null),
        ];
    }
}
