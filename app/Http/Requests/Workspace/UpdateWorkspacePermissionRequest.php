<?php

namespace App\Http\Requests\Workspace;

use App\Support\WorkspacePermissionCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspacePermissionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(WorkspacePermissionCatalog::roleIds())],
            'permission_key' => ['required', 'string', Rule::in(WorkspacePermissionCatalog::permissionKeys())],
            'allowed' => ['required', 'boolean'],
        ];
    }
}
