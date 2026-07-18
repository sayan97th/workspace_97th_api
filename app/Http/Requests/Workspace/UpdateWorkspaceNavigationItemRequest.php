<?php

namespace App\Http\Requests\Workspace;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceNavigationItemRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'view_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'href' => ['sometimes', 'nullable', 'string', 'max:255'],
            'display_style' => ['sometimes', 'nullable', 'string', 'max:50'],
            'board_type' => ['sometimes', 'string', Rule::in([
                WorkspaceNavigationItem::BOARD_TYPE_MAIN,
                WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
                WorkspaceNavigationItem::BOARD_TYPE_SHAREABLE,
            ])],
            'item_column_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_favorite' => ['sometimes', 'boolean'],
        ];
    }
}
