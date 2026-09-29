<?php

namespace App\Http\Requests\Board;

use App\Http\Requests\Board\Concerns\ValidatesAutomationDefinition;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating an automation with the sentence builder, see {@see ValidatesAutomationDefinition}.
 */
class StoreBoardAutomationRequest extends FormRequest
{
    use ValidatesAutomationDefinition;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $board = $this->route('item');
        $board_id = $board instanceof WorkspaceNavigationItem ? $board->id : null;

        return [
            'view_id' => [
                'required', 'integer',
                Rule::exists('board_views', 'id')->where(fn ($query) => $query->where('board_id', $board_id)),
            ],
            ...$this->definitionRules(false),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $board = $this->route('item');

                $this->validateDefinition($validator, [
                    'view_id' => $this->filled('view_id') ? (int) $this->input('view_id') : null,
                    'board' => $board instanceof WorkspaceNavigationItem ? $board : null,
                    'trigger_type' => null,
                    'trigger_column_id' => null,
                ]);
            },
        ];
    }
}
