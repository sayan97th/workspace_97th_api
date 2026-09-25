<?php

namespace App\Http\Requests\Admin\User;

use App\Models\UserProfileField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `values` maps a profile field id to its new value, or null to clear it. Each value is
 * checked against its own field's type: numbers must be numeric, dates `Y-m-d`, dropdowns
 * one of the field's option ids.
 */
class UpdateUserProfileFieldValuesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $values = $this->input('values');
                if (! is_array($values)) {
                    return;
                }

                $fields = UserProfileField::query()->whereIn('id', array_map('intval', array_keys($values)))->get()->keyBy('id');

                foreach ($values as $field_id => $value) {
                    $field = $fields->get((int) $field_id);
                    $key = "values.{$field_id}";

                    if (! $field) {
                        $validator->errors()->add($key, 'This profile field no longer exists.');

                        continue;
                    }
                    if ($value === null || $value === '') {
                        continue;
                    }

                    $is_valid = match ($field->type) {
                        UserProfileField::TYPE_NUMBER => is_numeric($value),
                        UserProfileField::TYPE_DATE => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) && strtotime((string) $value) !== false,
                        UserProfileField::TYPE_DROPDOWN => in_array((string) $value, $field->optionIds(), true),
                        default => true,
                    };

                    if (! $is_valid) {
                        $validator->errors()->add($key, match ($field->type) {
                            UserProfileField::TYPE_NUMBER => "{$field->name} must be a number.",
                            UserProfileField::TYPE_DATE => "{$field->name} must be a valid date.",
                            default => "{$field->name} must be one of its dropdown options.",
                        });
                    }
                }
            },
        ];
    }
}
