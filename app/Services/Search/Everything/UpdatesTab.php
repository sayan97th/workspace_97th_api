<?php

namespace App\Services\Search\Everything;

use App\Models\BoardItemComment;
use App\Support\LikeTerm;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Updates": item updates and replies on the boards the user can see, with the
 * "I was mentioned", "I wrote", "Has attachments", "Has links", "Is a reply" and "Archived"
 * checkboxes. Updates on archived items only show up with "Archived" (or "Search archives").
 */
final class UpdatesTab implements SearchTab
{
    public const FILTER_MENTIONED = 'mentioned';

    public const FILTER_WROTE = 'wrote';

    public const FILTER_ATTACHMENTS = 'attachments';

    public const FILTER_LINKS = 'links';

    public const FILTER_REPLY = 'reply';

    public const FILTER_ARCHIVED = 'archived';

    public const FILTERS = [
        self::FILTER_MENTIONED,
        self::FILTER_WROTE,
        self::FILTER_ATTACHMENTS,
        self::FILTER_LINKS,
        self::FILTER_REPLY,
        self::FILTER_ARCHIVED,
    ];

    public function count(SearchCriteria $criteria): int
    {
        return $this->baseQuery($criteria)->count();
    }

    public function results(SearchCriteria $criteria, int $offset, int $limit): array
    {
        $query = $this->baseQuery($criteria, $criteria->hasFilter(self::FILTER_ARCHIVED));
        foreach ($criteria->filters as $filter) {
            if ($filter !== self::FILTER_ARCHIVED) {
                $this->applyFilter($query, $criteria, $filter);
            }
        }

        $total = $query->clone()->count();

        if (! $criteria->sortsByTime()) {
            LikeTerm::orderByPrefix($query, 'body', $criteria->term);
        }

        $comments = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->with(['author', 'item.group:id,board_view_id', 'item.board.workspace'])
            ->withCount('attachments')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $data = $comments->map(fn (BoardItemComment $comment) => [
            'id' => $comment->id,
            'excerpt' => PlainText::fromMarkdown($comment->body),
            'created_at' => $comment->created_at?->toIso8601String(),
            'is_reply' => $comment->parent_id !== null,
            'attachment_count' => (int) $comment->attachments_count,
            'author' => SearchPresenter::person($comment->author),
            'item' => $comment->item ? SearchPresenter::item($comment->item) : null,
            'board' => $comment->item?->board ? SearchPresenter::board($comment->item->board) : null,
        ])->all();

        return ['data' => $data, 'total' => $total];
    }

    public function facets(SearchCriteria $criteria): array
    {
        $facets = [];
        foreach (self::FILTERS as $filter) {
            $query = $this->baseQuery($criteria, $filter === self::FILTER_ARCHIVED);
            if ($filter !== self::FILTER_ARCHIVED) {
                $this->applyFilter($query, $criteria, $filter);
            }
            $facets[$filter] = $query->count();
        }

        return $facets;
    }

    /**
     * Posted (not scheduled for later) updates matching the term and date range, on live
     * items, or on archived items only when `$archived_only`.
     *
     * @return Builder<BoardItemComment>
     */
    private function baseQuery(SearchCriteria $criteria, bool $archived_only = false): Builder
    {
        $query = BoardItemComment::query()
            ->whereIn('item_id', $criteria->itemIds($archived_only))
            ->where(fn (Builder $posted) => $posted->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()));

        LikeTerm::whereContains($query, ['body'], $criteria->term);

        return $criteria->applyDateRange($query, 'created_at');
    }

    /**
     * @param  Builder<BoardItemComment>  $query
     */
    private function applyFilter(Builder $query, SearchCriteria $criteria, string $filter): void
    {
        match ($filter) {
            self::FILTER_MENTIONED => $query->whereHas('mentions', fn (Builder $mentions) => $mentions->where('user_id', $criteria->user->id)),
            self::FILTER_WROTE => $query->where('user_id', $criteria->user->id),
            self::FILTER_ATTACHMENTS => $query->has('attachments'),
            self::FILTER_LINKS => $query->where(fn (Builder $links) => $links->where('body', 'like', '%http://%')
                ->orWhere('body', 'like', '%https://%')
                ->orWhere('body', 'like', '%www.%')),
            self::FILTER_REPLY => $query->whereNotNull('parent_id'),
            default => null,
        };
    }
}
