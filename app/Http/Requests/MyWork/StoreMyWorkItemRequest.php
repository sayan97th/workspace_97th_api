<?php

namespace App\Http\Requests\MyWork;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMyWorkItemRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'board_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            // The section's due date, so an item added under "Today" lands there.
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // Optional picks from the "New Item" dialog, checked against the board by MyWorkItemCreator.
            'group_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string', 'max:64'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
