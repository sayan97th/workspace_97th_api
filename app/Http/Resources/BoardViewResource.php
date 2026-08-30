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
            // Markdown source for a `doc`-type view — null/unused for every other kind.
            'doc_content' => $this->doc_content,
            // Chart type/data source/grouping for a `chart`-type view — null/unused for every other kind.
            'chart_config' => $this->chart_config,
            'emoji' => $this->emoji,
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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'full_name' => $this->creator->full_name,
                'profile_photo_url' => $this->creator->profile_photo_url,
            ] : null),
        ];
    }
}
