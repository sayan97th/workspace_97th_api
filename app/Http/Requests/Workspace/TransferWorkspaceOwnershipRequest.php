<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferWorkspaceOwnershipRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Whether the new owner id actually belongs to a member of the workspace
     * is checked in the controller, which already has the member list loaded
     * to resolve the current owner's own membership row.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'new_owner_id' => ['required', 'integer'],
            'self_role' => ['required', 'string', Rule::in(['member', 'viewer', 'leave'])],
        ];
    }
}
