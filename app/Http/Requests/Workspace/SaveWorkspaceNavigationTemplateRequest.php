<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWorkspaceNavigationTemplateRequest extends FormRequest
{
    /** "Save as a template": a copy of the board becomes the template, the board stays where it is. */
    public const MODE_COPY = 'copy';

    /** "Move to template": the board itself becomes the template and leaves the tree. */
    public const MODE_MOVE = 'move';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in([self::MODE_COPY, self::MODE_MOVE])],
        ];
    }
}
