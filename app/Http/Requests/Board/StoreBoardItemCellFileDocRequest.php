<?php

namespace App\Http\Requests\Board;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Files cell's "Doc" option: creates a new Doc next to the board and
 * attaches it to the cell. The name is optional, the item's name is used
 * when none is given.
 */
class StoreBoardItemCellFileDocRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name', ''))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
