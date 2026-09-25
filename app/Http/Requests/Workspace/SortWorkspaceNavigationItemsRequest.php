<?php

namespace App\Http\Requests\Workspace;

use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the sidebar's "Sort A to Z": which level to sort (a folder of
 * this workspace, or `null` for the workspace root) and whether every folder
 * beneath it is sorted too.
 */
class SortWorkspaceNavigationItemsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspace = $this->route('workspace');
        $workspace_id = $workspace instanceof Workspace ? $workspace->id : null;

        return [
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('workspace_navigation_items', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', $workspace_id)
                    ->where('type', WorkspaceNavigationItem::TYPE_GROUP)),
            ],
            'recursive' => ['sometimes', 'boolean'],
        ];
    }
}
