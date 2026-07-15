<?php

namespace App\Http\Requests\Workspace;

use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceNavigationItemRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspace = $this->route('workspace');
        $workspace_id = $workspace instanceof Workspace ? $workspace->id : null;

        return [
            'type' => ['required', 'string', Rule::in([
                WorkspaceNavigationItem::TYPE_GROUP,
                WorkspaceNavigationItem::TYPE_LEAF,
            ])],
            'label' => ['required', 'string', 'max:255'],
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('workspace_navigation_items', 'id')
                    ->where(fn ($query) => $query->where('workspace_id', $workspace_id)),
            ],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'view_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'href' => ['sometimes', 'nullable', 'string', 'max:255'],
            'display_style' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_favorite' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
