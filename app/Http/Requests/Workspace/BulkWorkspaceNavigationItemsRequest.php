<?php

namespace App\Http\Requests\Workspace;

use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the sidebar's multi-select bulk bar: one action (move, archive or
 * delete) applied to several boards and folders of the same workspace.
 * `parent_id` is only read for `move`, a folder of this workspace or `null`
 * for the workspace root.
 */
class BulkWorkspaceNavigationItemsRequest extends FormRequest
{
    public const ACTION_MOVE = 'move';

    public const ACTION_ARCHIVE = 'archive';

    public const ACTION_DELETE = 'delete';

    public const MAX_ITEMS = 200;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspace = $this->route('workspace');
        $workspace_id = $workspace instanceof Workspace ? $workspace->id : null;

        return [
            'action' => ['required', 'string', Rule::in([self::ACTION_MOVE, self::ACTION_ARCHIVE, self::ACTION_DELETE])],
            'item_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'item_ids.*' => [
                'integer', 'distinct',
                Rule::exists('workspace_navigation_items', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace_id)),
            ],
            'parent_id' => [
                'exclude_unless:action,'.self::ACTION_MOVE, 'present', 'nullable', 'integer',
                Rule::exists('workspace_navigation_items', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', $workspace_id)
                    ->where('type', WorkspaceNavigationItem::TYPE_GROUP)),
            ],
        ];
    }
}
