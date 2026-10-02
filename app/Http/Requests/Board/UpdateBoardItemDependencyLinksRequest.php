<?php

namespace App\Http\Requests\Board;

use App\Http\Controllers\Board\BoardItemDependencyController;
use App\Models\BoardItemDependencyLink;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardItemDependencyLinksRequest extends FormRequest
{
    /**
     * Every link of one Dependency cell, replacing the ones it had. Whether each predecessor
     * belongs to the same table and keeps the links free of loops is checked in
     * {@see BoardItemDependencyController::update()}.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'links' => ['present', 'array', 'max:100'],
            'links.*.predecessor_id' => ['required', 'integer', 'distinct'],
            'links.*.type' => ['sometimes', 'nullable', 'string', Rule::in(BoardItemDependencyLink::TYPES)],
            'links.*.lag_days' => ['sometimes', 'nullable', 'integer', 'min:'.-BoardItemDependencyLink::MAX_LAG_DAYS, 'max:'.BoardItemDependencyLink::MAX_LAG_DAYS],
        ];
    }
}
