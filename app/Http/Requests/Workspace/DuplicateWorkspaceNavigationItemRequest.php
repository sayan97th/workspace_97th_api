<?php

namespace App\Http\Requests\Workspace;

use App\Http\Controllers\Workspace\WorkspaceNavigationItemController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Duplicate board" options, see {@see WorkspaceNavigationItemController::duplicate()}.
 * `mode` picks what is copied: only the structure, the structure and items,
 * or also every item's updates. Defaults to structure and items, the
 * behavior before these options existed.
 */
class DuplicateWorkspaceNavigationItemRequest extends FormRequest
{
    public const MODE_STRUCTURE = 'structure';

    public const MODE_ITEMS = 'items';

    public const MODE_ITEMS_UPDATES = 'items_updates';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['sometimes', 'string', Rule::in([self::MODE_STRUCTURE, self::MODE_ITEMS, self::MODE_ITEMS_UPDATES])],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
