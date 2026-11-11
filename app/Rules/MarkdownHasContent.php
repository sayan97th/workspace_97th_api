<?php

namespace App\Rules;

use App\Services\Search\Everything\PlainText;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that a Markdown body shows something once rendered: visible text
 * or an inline image (`![alt](url)`). A body made only of syntax, such as
 * `**  **`, `> ` or an empty list item, would post as a blank bubble, so it
 * is rejected the same way an empty string is.
 */
class MarkdownHasContent implements ValidationRule
{
    /** An inline image, which counts as content even without any text around it. */
    private const IMAGE_PATTERN = '/!\[[^\]]*\]\([^)]+\)/u';

    /** Characters that are only Markdown syntax when nothing else is left: emphasis, quotes, titles, list and table markers. */
    private const SYNTAX_ONLY_PATTERN = '/[\s*_~`>#\-+|=\[\]\\\\]/u';

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $text = preg_replace(self::SYNTAX_ONLY_PATTERN, '', PlainText::fromMarkdown($value)) ?? '';
        $has_text = $text !== '';
        $has_image = preg_match(self::IMAGE_PATTERN, $value) === 1;

        if (! $has_text && ! $has_image) {
            $fail(__('Write something before sending.'));
        }
    }
}
