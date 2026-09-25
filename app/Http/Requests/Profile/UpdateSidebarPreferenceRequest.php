<?php

namespace App\Http\Requests\Profile;

use App\Support\SidebarPreferences;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSidebarPreferenceRequest extends FormRequest
{
    /** Shared clamp for the sidebar's `width`, mirroring `UpdateBoardColumnRequest`'s own column-width bounds. */
    public const MIN_SIDEBAR_WIDTH = 220;

    public const MAX_SIDEBAR_WIDTH = 480;

    /**
     * Every field is optional on its own, so the resize handle, the
     * "Customize sidebar" panel and the section collapse toggles can each
     * save only what they changed, but at least one of them must be sent.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'width' => ['sometimes', 'integer', 'min:'.self::MIN_SIDEBAR_WIDTH, 'max:'.self::MAX_SIDEBAR_WIDTH],
            'sections' => ['sometimes', 'array', 'max:'.count(SidebarPreferences::SECTION_KEYS)],
            'sections.*.key' => ['required', 'string', 'distinct', Rule::in(SidebarPreferences::SECTION_KEYS)],
            'sections.*.is_visible' => ['required', 'boolean'],
            'collapsed_sections' => ['sometimes', 'array', 'max:'.SidebarPreferences::MAX_COLLAPSED_SECTIONS],
            'collapsed_sections.*' => ['string', 'distinct', 'regex:'.SidebarPreferences::COLLAPSED_SECTION_PATTERN],
        ];
    }

    /**
     * An empty `collapsed_sections` list is a valid change (nothing folded),
     * so presence is checked by key rather than with `required_without_all`.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->hasAny(['width', 'sections', 'collapsed_sections'])) {
                    $validator->errors()->add('width', 'Send at least one of width, sections or collapsed_sections.');
                }
            },
        ];
    }
}
