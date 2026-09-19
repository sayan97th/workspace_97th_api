<?php

namespace App\Exports;

use App\Models\BoardItemComment;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The item drawer's "Export updates to Excel": one worksheet with a row per
 * update, each followed by its replies, oldest first so it reads like the
 * conversation did. Update bodies are stored as Markdown, so they are
 * flattened to plain text for the spreadsheet.
 */
class BoardItemUpdatesExport implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  Collection<int, BoardItemComment>  $updates  top-level comments with `author`, `likes`, `attachments` and `replies` (same relations) loaded
     */
    public function __construct(
        private readonly string $item_name,
        private readonly Collection $updates,
    ) {}

    public function title(): string
    {
        // Excel worksheet titles are capped at 31 characters and cannot contain \ / ? * [ ] :
        $title = trim(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $this->item_name), 0, 31));

        return $title !== '' ? $title : 'Updates';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Type', 'Author', 'Posted at', 'Edited', 'Pinned', 'Likes', 'Attachments', 'Update'];
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    public function array(): array
    {
        $rows = [];

        foreach ($this->updates as $update) {
            $rows[] = $this->buildRow($update, 'Update');

            foreach ($update->replies as $reply) {
                $rows[] = $this->buildRow($reply, 'Reply');
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string|int>
     */
    private function buildRow(BoardItemComment $comment, string $type): array
    {
        return [
            $type,
            $comment->author?->full_name ?? 'Deleted user',
            $comment->created_at?->toDateTimeString() ?? '',
            $comment->edited_at !== null ? 'Yes' : 'No',
            $comment->pinned ? 'Yes' : 'No',
            $comment->likes->count(),
            $comment->attachments->pluck('file_name')->implode(', '),
            $this->toPlainText($comment->body),
        ];
    }

    /**
     * Strips the Markdown the rich text composer produces (images, links,
     * emphasis, headings, quotes, list markers, code ticks) down to readable text.
     */
    private function toPlainText(string $markdown): string
    {
        $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '[Image]', $markdown);
        $text = preg_replace('/\[([^\]]*)\]\(([^)]*)\)/', '$1 ($2)', $text);
        $text = preg_replace('/^\s{0,3}(#{1,6}|>|[-*+]|\d+\.)\s+/m', '', $text);
        $text = preg_replace('/(\*\*|__|~~|`)/', '', $text);
        $text = preg_replace('/(?<![\w*])[*_]([^*_\n]+)[*_](?![\w*])/', '$1', $text);

        return trim($text);
    }
}
