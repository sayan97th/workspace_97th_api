<?php

namespace App\Http\Requests\Workspace;

use App\Support\WorkspacePermissionCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceInvitationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'emails' => ['required', 'array', 'min:1', 'max:25'],
            'emails.*' => ['required', 'string', 'email', 'max:255', 'distinct:ignore_case'],
            'role' => ['required', 'string', Rule::in(WorkspacePermissionCatalog::invitableRoleIds())],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
