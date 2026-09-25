<?php

namespace App\Http\Requests\Board;

use App\Enums\BoardEditPermission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardPermissionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'edit_permission' => ['required', 'string', Rule::in(BoardEditPermission::values())],
        ];
    }
}
