<?php

namespace App\Http\Resources;

use App\Models\BoardItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A board item ("pulse") row, with its column values flattened into a
 * `{column_id: value}` map so the frontend can look a cell's value up by
 * the column id directly.
 *
 * @mixin BoardItem
 */
class BoardItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'group_id' => $this->group_id,
            'name' => $this->name,
            'position' => $this->position,
            // Only `index()` eager-loads the `comments` count (the board table's row
            // chat icon); other actions that return this resource (store/update/
            // updateValues) fall back to 0 rather than a real count.
            'comment_count' => $this->whenCounted('comments', default: 0),
            // Cast to a plain object: if every column id in this map happens to be an
            // integer key, Laravel's JsonResource::removeMissingValues() treats the
            // array as a list and silently reindexes it from 0 via array_values(),
            // destroying the column-id keys. An stdClass isn't array-shaped, so it
            // skips that pass and round-trips through json_encode() untouched.
            'values' => $this->whenLoaded(
                'values',
                fn () => (object) $this->values->mapWithKeys(fn ($value) => [(string) $value->column_id => $value->value])->all(),
                (object) []
            ),
        ];
    }
}
