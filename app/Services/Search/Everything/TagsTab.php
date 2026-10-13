<?php

namespace App\Services\Search\Everything;

use App\Models\BoardTag;
use App\Services\Search\TagCatalog;

/**
 * "Tags": the account's public tags with how many items use each one. The frontend groups them
 * by first letter, so every match comes back in one page.
 */
final class TagsTab implements SearchTab
{
    /** Upper bound for the single page, far above any real account's tag count. */
    public const MAX_TAGS = 1000;

    public function __construct(
        private readonly TagCatalog $tag_catalog,
    ) {}

    public function count(SearchCriteria $criteria): int
    {
        return $this->tag_catalog->countPublicTags($criteria->user, $criteria->term);
    }

    public function results(SearchCriteria $criteria, int $offset, int $limit): array
    {
        $tags = $this->tag_catalog->publicTags($criteria->user, $criteria->term);

        if ($criteria->sortsByTime()) {
            $tags = $tags->sortByDesc('created_at')->values();
        }

        $data = $tags->slice($offset, max($limit, self::MAX_TAGS))->map(fn (BoardTag $tag) => [
            'id' => $tag->id,
            'board_id' => $tag->board_id,
            'label' => $tag->label,
            'color' => $tag->color,
            'usage_count' => (int) $tag->getAttribute('usage_count'),
        ])->values()->all();

        return ['data' => $data, 'total' => $tags->count()];
    }

    public function facets(SearchCriteria $criteria): array
    {
        return [];
    }
}
