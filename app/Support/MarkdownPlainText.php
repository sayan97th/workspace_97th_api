<?php

namespace App\Support;

/**
 * Flattens the Markdown the rich text composer produces to readable plain
 * text, for spreadsheet exports.
 */
class MarkdownPlainText
{
    /**
     * Strips images, links, emphasis, headings, quotes, list markers and code
     * ticks down to the words.
     */
    public static function convert(string $markdown): string
    {
        $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '[Image]', $markdown);
        $text = preg_replace('/\[([^\]]*)\]\(([^)]*)\)/', '$1 ($2)', $text);
        $text = preg_replace('/^\s{0,3}(#{1,6}|>|[-*+]|\d+\.)\s+/m', '', $text);
        $text = preg_replace('/(\*\*|__|~~|`)/', '', $text);
        $text = preg_replace('/(?<![\w*])[*_]([^*_\n]+)[*_](?![\w*])/', '$1', $text);

        return trim($text);
    }
}
