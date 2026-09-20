<?php

namespace App\Exports;

use App\Models\BoardComment;
use App\Models\BoardItemComment;
use App\Support\MarkdownPlainText;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The Update Feed's "Export to Excel": one worksheet with a row per update or
 * reply in the tab and filters the viewer is looking at, newest first. Bodies
 * are stored as Markdown, so they are flattened to plain text.
 */
class FeedUpdatesExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  Collection<int, BoardItemComment|BoardComment>  $updates  with `author`, `mentions.user` and `item.board` (or `board`) loaded
     */
    public function __construct(private readonly Collection $updates) {}

    public function title(): string
    {
        return 'Update feed';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Type', 'Author', 'Board', 'Item', 'Posted at', 'Pinned', 'Mentions', 'Link', 'Update'];
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');

        return $this->updates->map(function (BoardItemComment|BoardComment $comment) use ($frontend_url) {
            $is_item = $comment instanceof BoardItemComment;
            $board = $is_item ? $comment->item->board : $comment->board;
            $path = $is_item ? "/boards/{$board->id}/pulses/{$comment->item_id}?comment={$comment->id}" : "/boards/{$board->id}?update={$comment->id}";

            return [
                $comment->parent_id !== null ? 'Reply' : 'Update',
                $comment->author?->full_name ?? 'Deleted user',
                $board->label,
                $is_item ? $comment->item->name : '',
                $comment->created_at?->toDateTimeString() ?? '',
                $comment->pinned ? 'Yes' : 'No',
                $comment->mentions->map(fn ($mention) => $mention->user?->full_name)->filter()->implode(', '),
                $frontend_url.$path,
                MarkdownPlainText::convert($comment->body),
            ];
        })->all();
    }
}
