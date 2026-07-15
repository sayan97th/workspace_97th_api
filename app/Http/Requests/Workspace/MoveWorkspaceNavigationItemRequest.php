<?php

namespace App\Http\Requests\Workspace;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveWorkspaceNavigationItemRequest extends FormRequest
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
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('workspace_navigation_items', 'id')
                    ->where(fn ($query) => $query->where('workspace_id', $workspace_id)),
            ],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
