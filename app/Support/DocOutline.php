<?php

namespace App\Support;

/**
 * Reads the first blocks of a Doc (Tiptap JSON, or the plain text of an
 * older Doc tab) into a short outline: headings, paragraphs, list and to do
 * items, images, quotes, code and dividers. The Home page draws it as a
 * small page preview, so the card shows what the Doc is actually about.
 */
class DocOutline
{
    /** How many blocks a preview draws. */
    public const BLOCK_LIMIT = 8;

    /** Longest text kept per block, the preview is tiny anyway. */
    private const TEXT_LIMIT = 70;

    /**
     * @return array{blocks: array<int, array{type: string, text: string|null, level?: int, checked?: bool}>, word_count: int}
     */
    public static function fromContent(?string $content): array
    {
        $content = trim((string) $content);
        if ($content === '') {
            return ['blocks' => [], 'word_count' => 0];
        }

        $document = json_decode($content, true);
        if (! is_array($document) || ! isset($document['content']) || ! is_array($document['content'])) {
            return self::fromPlainText($content);
        }

        $blocks = [];
        self::collect($document['content'], $blocks);

        return [
            'blocks' => array_slice($blocks, 0, self::BLOCK_LIMIT),
            'word_count' => str_word_count(self::textOf($document)),
        ];
    }

    /**
     * @return array{blocks: array<int, array{type: string, text: string|null}>, word_count: int}
     */
    private static function fromPlainText(string $content): array
    {
        $text = trim(strip_tags($content));
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn (string $line) => $line !== '' && $line !== "\u{200B}"));

        return [
            'blocks' => array_map(fn (string $line) => ['type' => 'paragraph', 'text' => self::shorten($line)], array_slice($lines, 0, self::BLOCK_LIMIT)),
            'word_count' => str_word_count($text),
        ];
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private static function collect(array $nodes, array &$blocks): void
    {
        foreach ($nodes as $node) {
            if (count($blocks) >= self::BLOCK_LIMIT || ! is_array($node)) {
                return;
            }

            $type = $node['type'] ?? null;
            $children = is_array($node['content'] ?? null) ? $node['content'] : [];

            switch ($type) {
                case 'heading':
                    $blocks[] = ['type' => 'heading', 'level' => (int) ($node['attrs']['level'] ?? 1), 'text' => self::textOrNull($node)];
                    break;
                case 'paragraph':
                    // Empty paragraphs are just spacing in the editor, nothing to preview.
                    $text = self::textOrNull($node);
                    if ($text !== null) {
                        $blocks[] = ['type' => 'paragraph', 'text' => $text];
                    }
                    break;
                case 'bulletList':
                case 'orderedList':
                    self::collectListItems($children, $type === 'orderedList' ? 'numbered' : 'bullet', $blocks);
                    break;
                case 'taskList':
                    self::collectTaskItems($children, $blocks);
                    break;
                case 'codeBlock':
                    $blocks[] = ['type' => 'code', 'text' => self::textOrNull($node)];
                    break;
                case 'blockquote':
                    $blocks[] = ['type' => 'quote', 'text' => self::textOrNull($node)];
                    break;
                case 'image':
                case 'horizontalRule':
                case 'table':
                    $blocks[] = ['type' => ['image' => 'image', 'horizontalRule' => 'divider', 'table' => 'table'][$type], 'text' => null];
                    break;
                default:
                    self::collect($children, $blocks);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private static function collectListItems(array $items, string $type, array &$blocks): void
    {
        foreach ($items as $item) {
            if (count($blocks) >= self::BLOCK_LIMIT) {
                return;
            }
            $blocks[] = ['type' => $type, 'text' => is_array($item) ? self::textOrNull($item) : null];
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private static function collectTaskItems(array $items, array &$blocks): void
    {
        foreach ($items as $item) {
            if (count($blocks) >= self::BLOCK_LIMIT || ! is_array($item)) {
                return;
            }
            $blocks[] = ['type' => 'todo', 'checked' => (bool) ($item['attrs']['checked'] ?? false), 'text' => self::textOrNull($item)];
        }
    }

    private static function textOrNull(array $node): ?string
    {
        $text = trim(self::textOf($node));

        return $text === '' ? null : self::shorten($text);
    }

    /**
     * Every text leaf under `$node`, joined with spaces.
     */
    private static function textOf(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return (string) ($node['text'] ?? '');
        }
        if (($node['type'] ?? null) === 'mention') {
            return '@'.($node['attrs']['label'] ?? '');
        }

        $parts = [];
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                $parts[] = self::textOf($child);
            }
        }

        return implode(' ', array_filter($parts, fn (string $part) => $part !== ''));
    }

    private static function shorten(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strlen($text) > self::TEXT_LIMIT ? rtrim(mb_substr($text, 0, self::TEXT_LIMIT)).'…' : $text;
    }
}
