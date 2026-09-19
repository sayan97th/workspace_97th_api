<?php

namespace App\Http\Requests\Board;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Rules\SlackActionHasConnectedWorkspace;
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
            'trigger_value' => ['sometimes', 'nullable'],
            'action_type' => ['sometimes', 'string', Rule::in([
                BoardAutomation::ACTION_MOVE_TO_GROUP,
                BoardAutomation::ACTION_NOTIFY_PERSON,
                BoardAutomation::ACTION_ARCHIVE_ITEM,
                BoardAutomation::ACTION_SET_COLUMN_VALUE,
                BoardAutomation::ACTION_CREATE_ITEM,
                BoardAutomation::ACTION_SEND_EMAIL,
                BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL,
                BoardAutomation::ACTION_SLACK_NOTIFY_PERSON,
            ]), new SlackActionHasConnectedWorkspace],
            'action_params' => ['sometimes', 'array'],
            'action_params.target_group_id' => ['sometimes', 'integer', Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id))],
            'action_params.notify_user_id' => ['sometimes', 'integer', Rule::exists('users', 'id')],
            'action_params.notify_from_people_column_id' => [
                'sometimes', 'integer',
                Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id)->where('type', BoardColumn::TYPE_PEOPLE)),
            ],
            'action_params.target_column_id' => ['sometimes', 'integer', Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id))],
            'action_params.value' => ['sometimes', 'nullable'],
            'action_params.item_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'action_params.message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'action_params.subject' => ['sometimes', 'nullable', 'string', 'max:150'],
            'action_params.slack_channel_id' => ['sometimes', 'string', 'regex:/^[CG][A-Z0-9]{2,}$/'],
            'action_params.slack_channel_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
