<?php

namespace App\Http\Requests\Admin\BoardOwnership;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkReassignBoardOwnerRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_owner_id' => ['required', 'integer', 'exists:users,id'],
            'new_owner_id' => ['required', 'integer', 'exists:users,id', 'different:current_owner_id'],
        ];
    }
}
