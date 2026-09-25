<?php

namespace App\Http\Requests\Template;

use App\Support\BuiltInBoardTemplates;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The board menu's "Save as a template": which board, how the template is
 * named and filed, and whether its items are kept as sample content.
 */
class StoreBoardTemplateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'board_id' => ['required', 'integer', Rule::exists('workspace_navigation_items', 'id')->where('type', 'leaf')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category' => ['sometimes', 'string', Rule::in(array_keys(BuiltInBoardTemplates::CATEGORIES))],
            'include_items' => ['sometimes', 'boolean'],
        ];
    }
}
