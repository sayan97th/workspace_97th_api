<?php

namespace App\Http\Resources;

use App\Models\BoardView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Attributes\PreserveKeys;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved board view/tab. Doubles as the "saved filter" payload — the
 * `filter_state`/`sort_state`/etc fields are exactly what
 * `PATCH /api/boards/{item}/views/{board_view}` accepts to save changes.
 *
 * `#[PreserveKeys]` is required: `filter_state.quick_filter_selections` is a
 * map of column-id => option ids (e.g. `{"23": ["in_progress"]}`), and
 * `JsonResource`'s array filtering treats any nested array with all-numeric
 * keys as a list to reindex — silently dropping the column-id key and
 * corrupting the saved filter on every read.
 *
 * @mixin BoardView
 */
#[PreserveKeys]
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
            'view_type' => $this->view_type,
            'icon' => $this->icon,
            'position' => $this->position,
            'is_primary' => $this->is_primary,
            'pinned' => $this->pinned,
            'is_locked' => $this->is_locked,
            'locked_by_id' => $this->locked_by_id,
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
