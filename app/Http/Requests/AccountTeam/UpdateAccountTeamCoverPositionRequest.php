<?php

namespace App\Http\Requests\AccountTeam;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The vertical focal point of a team's cover, set by dragging the image in the banner.
 */
class UpdateAccountTeamCoverPositionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cover_position_y' => ['required', 'integer', 'between:0,100'],
        ];
    }
}
