<?php

namespace App\Http\Requests\AccountTeam;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkAccountTeamBoardsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'board_ids' => ['required', 'array', 'min:1', 'max:100'],
            'board_ids.*' => ['integer', 'distinct', Rule::exists('workspace_navigation_items', 'id')->whereNull('deleted_at')],
        ];
    }
}
