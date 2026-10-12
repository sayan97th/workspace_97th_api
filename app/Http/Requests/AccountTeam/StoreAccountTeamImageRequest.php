<?php

namespace App\Http\Requests\AccountTeam;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A team's profile picture or cover image upload, both re-encoded server side.
 */
class StoreAccountTeamImageRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:10240'],
            // Only read on a cover upload, so the new image starts where the user framed it.
            'cover_position_y' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }
}
