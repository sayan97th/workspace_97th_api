<?php

namespace App\Http\Requests\Board;

use App\Http\Requests\Board\Concerns\ValidatesAutomationDefinition;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Test run on an item": the same definition the builder saves, see
 * {@see ValidatesAutomationDefinition}, plus the item to run it on and, for a webhook trigger, a
 * sample JSON body.
 */
class TestBoardAutomationRequest extends FormRequest
{
    use ValidatesAutomationDefinition;

    /** Largest sample webhook body accepted, in bytes once encoded. */
    private const MAX_PAYLOAD_BYTES = 65536;

    /**
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
            'item_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('board_items', 'id')->where(fn ($query) => $query->where('board_id', $board_id)->whereNull('deleted_at')),
            ],
            'payload' => ['sometimes', 'nullable', 'array'],
            ...$this->definitionRules(false),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $board = $this->route('item');

                if (strlen((string) json_encode($this->input('payload', []))) > self::MAX_PAYLOAD_BYTES) {
                    $validator->errors()->add('payload', 'The sample body is too large.');
                }

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
