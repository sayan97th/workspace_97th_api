<?php

namespace App\Http\Requests\Workspace;

use App\Support\WorkspacePermissionCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceInviteLinkRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'string', Rule::in(WorkspacePermissionCatalog::invitableRoleIds())],
        ];
    }
}
