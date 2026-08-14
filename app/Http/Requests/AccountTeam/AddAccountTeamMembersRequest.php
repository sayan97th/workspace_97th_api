<?php

namespace App\Http\Requests\AccountTeam;

use App\Concerns\ValidatesStaffMemberIds;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddAccountTeamMembersRequest extends FormRequest
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
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', $this->staffMemberIdRule()],
        ];
    }
}
