<?php

namespace App\Http\Requests\Board;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use App\Rules\SlackActionHasConnectedWorkspace;
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
            'trigger_type' => ['required', 'string', Rule::in([
                BoardAutomation::TRIGGER_STATUS_CHANGED,
                BoardAutomation::TRIGGER_DATE_ARRIVED,
                BoardAutomation::TRIGGER_ITEM_CREATED,
                BoardAutomation::TRIGGER_SUBITEM_CREATED,
                BoardAutomation::TRIGGER_PERSON_ASSIGNED,
                BoardAutomation::TRIGGER_COLUMN_CHANGED,
                BoardAutomation::TRIGGER_UPDATE_POSTED,
            ])],
            // `item_created`/`subitem_created`/`update_posted` watch no column at all.
            'trigger_column_id' => [
                Rule::requiredIf(in_array($trigger_type, [
                    BoardAutomation::TRIGGER_STATUS_CHANGED, BoardAutomation::TRIGGER_DATE_ARRIVED,
                    BoardAutomation::TRIGGER_PERSON_ASSIGNED, BoardAutomation::TRIGGER_COLUMN_CHANGED,
                ], true)),
                'nullable', 'integer',
                Rule::exists('board_columns', 'id')->where(function ($query) use ($view_id, $trigger_type) {
                    $query->where('board_view_id', $view_id)->where('scope', BoardColumn::SCOPE_ITEM);
                    if ($trigger_type === BoardAutomation::TRIGGER_DATE_ARRIVED) {
                        $query->where('type', BoardColumn::TYPE_DATE);
                    } elseif ($trigger_type === BoardAutomation::TRIGGER_PERSON_ASSIGNED) {
                        $query->where('type', BoardColumn::TYPE_PEOPLE);
                    } elseif ($trigger_type === BoardAutomation::TRIGGER_COLUMN_CHANGED) {
                        // Any column whose value the user can edit, computed ones never change on their own write.
                        $query->whereNotIn('type', BoardColumn::READ_ONLY_TYPES);
                    } else {
                        $query->whereIn('type', [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL]);
                    }
                }),
            ],
            // Required for `status_changed` (the exact option matched); optional
            // for `person_assigned` (null watches for anyone newly assigned).
            'trigger_value' => [Rule::requiredIf($trigger_type === BoardAutomation::TRIGGER_STATUS_CHANGED), 'nullable'],
            'action_type' => ['required', 'string', Rule::in([
                BoardAutomation::ACTION_MOVE_TO_GROUP,
                BoardAutomation::ACTION_NOTIFY_PERSON,
                BoardAutomation::ACTION_ARCHIVE_ITEM,
                BoardAutomation::ACTION_SET_COLUMN_VALUE,
                BoardAutomation::ACTION_CREATE_ITEM,
                BoardAutomation::ACTION_SEND_EMAIL,
                BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL,
                BoardAutomation::ACTION_SLACK_NOTIFY_PERSON,
            ]), new SlackActionHasConnectedWorkspace],
            'action_params' => ['required', 'array'],
            'action_params.target_group_id' => [
                Rule::requiredIf(in_array($action_type, [BoardAutomation::ACTION_MOVE_TO_GROUP, BoardAutomation::ACTION_CREATE_ITEM], true)),
                'integer',
                Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id)),
            ],
            'action_params.notify_user_id' => ['sometimes', 'integer', Rule::exists('users', 'id')],
            'action_params.notify_from_people_column_id' => [
                'sometimes', 'integer',
                Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id)->where('type', BoardColumn::TYPE_PEOPLE)),
            ],
            'action_params.target_column_id' => [
                Rule::requiredIf($action_type === BoardAutomation::ACTION_SET_COLUMN_VALUE),
                'integer',
                Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id)),
            ],
            'action_params.value' => ['sometimes', 'nullable'],
            'action_params.item_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Communication actions: who to reach is `notify_user_id`/`notify_from_people_column_id`
            // above, for a channel post it is the Slack channel id instead.
            'action_params.message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'action_params.subject' => ['sometimes', 'nullable', 'string', 'max:150'],
            'action_params.slack_channel_id' => [
                Rule::requiredIf($action_type === BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL),
                'string', 'regex:/^[CG][A-Z0-9]{2,}$/',
            ],
            'action_params.slack_channel_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
