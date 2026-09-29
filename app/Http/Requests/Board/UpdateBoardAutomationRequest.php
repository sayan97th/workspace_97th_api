<?php

namespace App\Http\Requests\Board;

use App\Http\Requests\Board\Concerns\ValidatesAutomationDefinition;
use App\Models\BoardAutomation;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Editing an automation: the Manage list's switch, rename, importance and description, or the
 * whole definition saved again from the sentence builder, see {@see ValidatesAutomationDefinition}.
 */
class UpdateBoardAutomationRequest extends FormRequest
{
    use ValidatesAutomationDefinition;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->definitionRules(true),
            // "Transfer ownership" on an automation card.
            'owner_id' => ['sometimes', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var BoardAutomation $automation */
                $automation = $this->route('automation');
                $board = $this->route('item');

                $this->validateDefinition($validator, [
                    'view_id' => $automation->board_view_id,
                    'board' => $board instanceof WorkspaceNavigationItem ? $board : null,
                    'trigger_type' => $automation->trigger_type,
                    'trigger_column_id' => $automation->trigger_column_id,
                ]);
            },
        ];
    }
}
