<?php

namespace App\Services\Search\Everything;

use Illuminate\Support\Str;

/**
 * Turns stored rich text (markdown update bodies, Tiptap JSON docs) into a short plain text
 * excerpt for result cards.
 */
final class PlainText
{
    /** Longest excerpt returned, the cards clamp it further with CSS. */
    public const MAX_LENGTH = 600;

    public static function fromMarkdown(?string $markdown): string
    {
        $text = (string) $markdown;
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', ' ', $text) ?? $text;
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/^\s{0,3}(#{1,6}|>|[-*+]|\d+\.)\s+/mu', '', $text) ?? $text;
        $text = str_replace(['**', '__', '~~', '`'], '', $text);

        return self::squash(html_entity_decode($text, ENT_QUOTES | ENT_HTML5));
    }

    /**
     * Text of a Tiptap/ProseMirror JSON document, starting near the first match of `$term` so
     * the excerpt shows why the doc was found.
     */
    public static function fromDocJson(?string $json, string $term = ''): string
    {
        $document = json_decode((string) $json, true);
        $parts = [];
        if (is_array($document)) {
            array_walk_recursive($document, function ($value, $key) use (&$parts) {
                if ($key === 'text' && is_string($value)) {
                    $parts[] = $value;
                }
            });
        }

        $text = self::squash(implode(' ', $parts));
        if ($term === '') {
            return $text;
        }

        $position = mb_stripos($text, $term);
        if ($position === false || $position < 80) {
            return $text;
        }

        return '...'.self::squash(mb_substr($text, $position - 60));
    }

    private static function squash(string $text): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), self::MAX_LENGTH);
    }
}
