<?php

namespace App\Http\Requests\Board;

use App\Models\BoardColumn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardColumnRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in([
                BoardColumn::TYPE_TEXT,
                BoardColumn::TYPE_STATUS,
                BoardColumn::TYPE_PEOPLE,
                BoardColumn::TYPE_DATE,
                BoardColumn::TYPE_TAGS,
                BoardColumn::TYPE_NUMBER,
                BoardColumn::TYPE_CHECKBOX,
            ])],
            'width' => ['sometimes', 'integer', 'min:40', 'max:600'],
            'config' => ['sometimes', 'nullable', 'array'],
            'hideable' => ['sometimes', 'boolean'],
            'pinnable' => ['sometimes', 'boolean'],
        ];
    }
}
