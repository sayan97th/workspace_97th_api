<?php

namespace App\Http\Requests\AccountTeam;

use App\Concerns\ValidatesStaffMemberIds;
use App\Models\AccountTeam;
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
            'member_ids.*' => ['integer', $this->teamMemberIdRule($this->team()->allow_guests)],
        ];
    }

    private function team(): AccountTeam
    {
        /** @var AccountTeam $team */
        $team = $this->route('team');

        return $team;
    }
}
