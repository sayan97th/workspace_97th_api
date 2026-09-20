<?php

namespace App\Http\Resources;

use App\Models\BoardAutomationRunLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoardAutomationRunLog
 */
class BoardAutomationRunLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automation_id,
            'automation_name' => $this->automation_name,
            'board_item_id' => $this->board_item_id,
            'item_name' => $this->item_name,
            'trigger_type' => $this->trigger_type,
            'action_type' => $this->action_type,
            'status' => $this->status,
            'message' => $this->message,
            'actor_name' => $this->actor?->full_name,
            'ran_at' => $this->created_at,
        ];
    }
}
