<?php

namespace App\Http\Requests\Admin\Content;

use Illuminate\Contracts\Validation\ValidationRule;

class BulkReassignBoardsRequest extends BulkBoardActionRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
