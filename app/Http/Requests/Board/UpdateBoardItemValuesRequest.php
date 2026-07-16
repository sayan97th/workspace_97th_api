<?php

namespace App\Http\Requests\Board;

use App\Http\Controllers\Board\BoardItemController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBoardItemValuesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `values` is a `{column_id: value}` map — the value's shape depends on
     * the column's type, so it's validated loosely here and checked for
     * column ownership in {@see BoardItemController::updateValues()}.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'values' => ['required', 'array', 'min:1'],
        ];
    }
}
