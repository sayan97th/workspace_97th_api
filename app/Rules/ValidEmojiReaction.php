<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that a value is a single emoji, rather than arbitrary free-form
 * text, so a reaction can't be used to smuggle non-emoji strings into the
 * `emoji` column. Deliberately broad (whole Unicode emoji-adjacent
 * supplementary-plane block, plus variation selector/ZWJ/skin-tone/keycap
 * building blocks) instead of an exact whitelist, since the frontend offers
 * the full `emoji-picker-react` library rather than a small fixed set.
 */
class ValidEmojiReaction implements ValidationRule
{
    /**
     * Matches one or more emoji codepoints, allowing the combining pieces
     * (variation selector U+FE0F, zero-width joiner U+200D, skin tone
     * modifiers U+1F3FB-1F3FF, keycap combiner U+20E3) so multi-part
     * sequences (family emoji, flags, skin-toned gestures, keycaps) validate
     * as a single reaction.
     */
    private const PATTERN = '/^(?:[\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{25A0}-\x{27BF}\x{2934}\x{2935}\x{2B00}-\x{2BFF}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F000}-\x{1FFFF}\x{FE0F}\x{200D}]|[0-9#*]\x{FE0F}?\x{20E3})+$/u';

    private const MAX_LENGTH = 32;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || mb_strlen($value, 'UTF-8') > self::MAX_LENGTH || ! preg_match(self::PATTERN, $value)) {
            $fail(__('The :attribute must be a single emoji.'));
        }
    }
}
