<?php

namespace App\Http\Requests\Workspace;

use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UseWorkspaceNavigationTemplateRequest extends FormRequest
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
            // The new board's name, the template's own name when omitted.
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            // The folder the new board lands in, the workspace root when null.
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('workspace_navigation_items', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', $workspace_id)
                    ->where('type', WorkspaceNavigationItem::TYPE_GROUP)
                    ->where('is_template', false)
                    ->whereNull('deleted_at')),
            ],
        ];
    }
}
