<?php

namespace App\Http\Requests\AccountTeam;

use App\Concerns\ValidatesStaffMemberIds;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountTeamRequest extends FormRequest
{
    use ValidatesStaffMemberIds;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('account_teams', 'id')->whereNull('deleted_at')],
            'allow_guests' => ['sometimes', 'boolean'],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer', $this->teamMemberIdRule($this->boolean('allow_guests'))],
        ];
    }
}
