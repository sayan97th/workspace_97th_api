<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBoardColumnPermissionsRequest extends FormRequest
{
    /**
     * Each restriction is `null` (everyone) or the people and teams allowed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'view_restriction' => ['sometimes', 'nullable', 'array'],
            'view_restriction.user_ids' => ['sometimes', 'array', 'max:200'],
            'view_restriction.user_ids.*' => ['integer', 'exists:users,id'],
            'view_restriction.team_ids' => ['sometimes', 'array', 'max:100'],
            'view_restriction.team_ids.*' => ['integer', 'exists:account_teams,id'],
            'edit_restriction' => ['sometimes', 'nullable', 'array'],
            'edit_restriction.user_ids' => ['sometimes', 'array', 'max:200'],
            'edit_restriction.user_ids.*' => ['integer', 'exists:users,id'],
            'edit_restriction.team_ids' => ['sometimes', 'array', 'max:100'],
            'edit_restriction.team_ids.*' => ['integer', 'exists:account_teams,id'],
        ];
    }
}
