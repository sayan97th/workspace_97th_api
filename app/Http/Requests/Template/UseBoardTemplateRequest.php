<?php

namespace App\Http\Requests\Template;

use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a board from the Template center. `template_id` is either
 * `builtin:{key}` or `custom:{id}`, `parent_id` an optional folder of the
 * workspace to create the board in.
 */
class UseBoardTemplateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspace = $this->route('workspace');
        $workspace_id = $workspace instanceof Workspace ? $workspace->id : null;

        return [
            'template_id' => ['required', 'string', 'max:80', 'regex:/^(builtin:[a-z_]+|custom:\d+)$/'],
            'label' => ['required', 'string', 'max:255'],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('workspace_navigation_items', 'id')
                    ->where('workspace_id', $workspace_id)
                    ->where('type', WorkspaceNavigationItem::TYPE_GROUP)
                    ->whereNull('deleted_at'),
            ],
            'board_type' => ['sometimes', 'string', Rule::in([
                WorkspaceNavigationItem::BOARD_TYPE_MAIN,
                WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
                WorkspaceNavigationItem::BOARD_TYPE_SHAREABLE,
            ])],
        ];
    }
}
