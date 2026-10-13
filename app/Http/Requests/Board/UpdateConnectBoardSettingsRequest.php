<?php

namespace App\Http\Requests\Board;

use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\ConnectBoardColumnService;
use App\Support\VisibleBoards;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Connect boards column's "Boards settings": which boards it connects, whether a cell may
 * hold several items, and whether the connection shows on the other board too.
 */
class UpdateConnectBoardSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'linked_board_ids' => ['required', 'array', 'min:1', 'max:'.ConnectBoardColumnService::MAX_LINKED_BOARDS],
            'linked_board_ids.*' => ['required', 'integer', 'distinct', $this->visibleBoardRule()],
            'allow_multiple_items' => ['sometimes', 'boolean'],
            'two_way' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'linked_board_ids.required' => 'Choose at least one board to connect.',
            'linked_board_ids.min' => 'Choose at least one board to connect.',
            'linked_board_ids.max' => 'You can connect up to '.ConnectBoardColumnService::MAX_LINKED_BOARDS.' boards.',
        ];
    }

    /**
     * A newly added board must be one the user may open, never a doc or dashboard. A board the
     * column already connects is kept even when the user cannot see it (a private board), so
     * saving the other settings never drops someone else's connection.
     */
    private function visibleBoardRule(): Closure
    {
        $column = $this->route('column');
        $connected_ids = $column instanceof BoardColumn ? ConnectBoardColumnService::linkedBoardIds($column) : [];

        return function (string $attribute, mixed $value, Closure $fail) use ($connected_ids) {
            if (in_array((int) $value, $connected_ids, true)) {
                return;
            }

            $board = VisibleBoards::query($this->user())->find((int) $value);

            if (! $board || $board->assetType() !== WorkspaceNavigationItem::ASSET_TYPE_BOARD) {
                $fail('One of the chosen boards is not available.');
            }
        };
    }
}
