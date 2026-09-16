<?php

namespace App\Http\Resources;

use App\Models\BoardAutomation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        ];
    }
}
