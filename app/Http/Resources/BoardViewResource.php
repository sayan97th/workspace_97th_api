<?php

namespace App\Http\Resources;

use App\Models\BoardView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved board view/tab. Doubles as the "saved filter" payload — the
 * `filter_state`/`sort_state`/etc fields are exactly what
 * `PATCH /api/boards/{item}/views/{board_view}` accepts to save changes.
 *
 * @mixin BoardView
 */
class BoardViewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'label' => $this->label,
            'position' => $this->position,
            'is_primary' => $this->is_primary,
            'filter_state' => $this->filter_state,
            'sort_state' => $this->sort_state,
            'group_by_option_id' => $this->group_by_option_id,
            'hidden_column_ids' => $this->hidden_column_ids,
            'pinned_column_ids' => $this->pinned_column_ids,
            'row_height' => $this->row_height,
            'conditional_color_rules' => $this->conditional_color_rules,
        ];
    }
}
