<?php

namespace App\Http\Requests\Board;

use App\Models\BoardExportJob;
use App\Services\Board\Export\BoardExportCellFormatter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The export dialog's choices: which tab (and optionally which group), the
 * layout, whether subitems and hidden columns come along, and the viewer's
 * time zone for the Creation log and Last updated columns.
 */
class StoreBoardExportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'view_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'layout' => ['nullable', 'string', Rule::in(BoardExportJob::LAYOUTS)],
            'include_subitems' => ['nullable', 'boolean'],
            'include_hidden_columns' => ['nullable', 'boolean'],
            'time_zone' => ['nullable', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && ! BoardExportCellFormatter::isValidTimeZone($value)) {
                    $fail('The time zone is not valid.');
                }
            }],
        ];
    }
}
