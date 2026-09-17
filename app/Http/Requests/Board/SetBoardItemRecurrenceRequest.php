<?php

namespace App\Http\Requests\Board;

use App\Models\BoardItemRecurrence;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the Row menu's "Set recurring..." popover — see
 * {@see \App\Services\Board\RecurringItemService}.
 */
class SetBoardItemRecurrenceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'frequency' => ['required', 'string', Rule::in([
                BoardItemRecurrence::FREQUENCY_DAILY,
                BoardItemRecurrence::FREQUENCY_WEEKLY,
                BoardItemRecurrence::FREQUENCY_MONTHLY,
            ])],
            'interval_count' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }
}
