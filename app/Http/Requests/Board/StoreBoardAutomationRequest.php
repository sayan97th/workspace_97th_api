<?php

namespace App\Http\Requests\Board;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoardAutomationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $board = $this->route('item');
        $board_id = $board instanceof WorkspaceNavigationItem ? $board->id : null;
        $view_id = $this->input('view_id');
        $trigger_type = $this->input('trigger_type');
        $action_type = $this->input('action_type');

        return [
            'view_id' => [
                'required', 'integer',
                Rule::exists('board_views', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_enabled' => ['sometimes', 'boolean'],
            'trigger_type' => ['required', 'string', Rule::in([BoardAutomation::TRIGGER_STATUS_CHANGED, BoardAutomation::TRIGGER_DATE_ARRIVED])],
            'trigger_column_id' => [
                'required', 'integer',
                Rule::exists('board_columns', 'id')->where(function ($query) use ($view_id, $trigger_type) {
                    $query->where('board_view_id', $view_id)->where('scope', BoardColumn::SCOPE_ITEM);
                    if ($trigger_type === BoardAutomation::TRIGGER_DATE_ARRIVED) {
                        $query->where('type', BoardColumn::TYPE_DATE);
                    } else {
                        $query->whereIn('type', [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL]);
                    }
                }),
            ],
            'trigger_value' => [Rule::requiredIf($trigger_type === BoardAutomation::TRIGGER_STATUS_CHANGED)],
            'action_type' => ['required', 'string', Rule::in([BoardAutomation::ACTION_MOVE_TO_GROUP, BoardAutomation::ACTION_NOTIFY_PERSON])],
            'action_params' => ['required', 'array'],
            'action_params.target_group_id' => [
                Rule::requiredIf($action_type === BoardAutomation::ACTION_MOVE_TO_GROUP),
                'integer',
                Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id)),
            ],
            'action_params.notify_user_id' => ['sometimes', 'integer', Rule::exists('users', 'id')],
            'action_params.notify_from_people_column_id' => [
                'sometimes', 'integer',
                Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id)->where('type', BoardColumn::TYPE_PEOPLE)),
            ],
        ];
    }
}
