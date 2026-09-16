<?php

namespace App\Http\Requests\Board;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardAutomationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var BoardAutomation $automation */
        $automation = $this->route('automation');
        $view_id = $automation->board_view_id;

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_enabled' => ['sometimes', 'boolean'],
            'trigger_value' => ['sometimes'],
            'action_type' => ['sometimes', 'string', Rule::in([BoardAutomation::ACTION_MOVE_TO_GROUP, BoardAutomation::ACTION_NOTIFY_PERSON])],
            'action_params' => ['sometimes', 'array'],
            'action_params.target_group_id' => ['sometimes', 'integer', Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id))],
            'action_params.notify_user_id' => ['sometimes', 'integer', Rule::exists('users', 'id')],
            'action_params.notify_from_people_column_id' => [
                'sometimes', 'integer',
                Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id)->where('type', BoardColumn::TYPE_PEOPLE)),
            ],
        ];
    }
}
