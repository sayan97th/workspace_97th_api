<?php

namespace App\Services\Search\Everything;

/**
 * Backs the "Search Everything" dialog (`GET /api/search/everything`). Each tab is its own
 * {@see SearchTab}. The first page of any tab also returns the count of every tab, so the tab
 * bar can show "Updates / 10154" while the user stays on another tab. The "All" tab returns a
 * short preview of every tab instead of a single list.
 */
class SearchEverythingService
{
    public const TAB_ALL = 'all';

    public const TAB_BOARDS = 'boards';

    public const TAB_UPDATES = 'updates';

    public const TAB_FILES = 'files';

    public const TAB_PEOPLE = 'people';

    public const TAB_TAGS = 'tags';

    public const TAB_DOCS = 'docs';

    public const TABS = [self::TAB_ALL, self::TAB_BOARDS, self::TAB_UPDATES, self::TAB_FILES, self::TAB_PEOPLE, self::TAB_TAGS, self::TAB_DOCS];

    public const MAX_TERM_LENGTH = 100;

    public const DEFAULT_PER_PAGE = 24;

    public const MAX_PER_PAGE = 60;

    /** How many results of each tab the "All" tab previews. */
    private const ALL_TAB_PREVIEW = [
        self::TAB_BOARDS => 5,
        self::TAB_UPDATES => 3,
        self::TAB_FILES => 6,
        self::TAB_PEOPLE => 6,
        self::TAB_TAGS => 12,
        self::TAB_DOCS => 3,
    ];

    /** @var array<string, SearchTab> */
    private array $tabs;

    public function __construct(
        BoardsTab $boards_tab,
        UpdatesTab $updates_tab,
        FilesTab $files_tab,
        PeopleTab $people_tab,
        TagsTab $tags_tab,
        DocsTab $docs_tab,
    ) {
        $this->tabs = [
            self::TAB_BOARDS => $boards_tab,
            self::TAB_UPDATES => $updates_tab,
            self::TAB_FILES => $files_tab,
            self::TAB_PEOPLE => $people_tab,
            self::TAB_TAGS => $tags_tab,
            self::TAB_DOCS => $docs_tab,
        ];
    }

    /**
     * @return array{data: mixed, counts: array<string, int>|null, facets: array<string, int>, meta: array<string, mixed>}
     */
    public function search(SearchCriteria $criteria, string $tab, int $page = 1, int $per_page = self::DEFAULT_PER_PAGE): array
    {
        $counts = $page === 1
            ? array_map(fn (SearchTab $search_tab) => $search_tab->count($criteria), $this->tabs)
            : null;

        if ($tab === self::TAB_ALL) {
            $sections = [];
            foreach (self::ALL_TAB_PREVIEW as $section => $limit) {
                $sections[$section] = $this->tabs[$section]->results($criteria, 0, $limit)['data'];
            }

            return [
                'data' => $sections,
                'counts' => $counts,
                'facets' => [],
                'meta' => ['query' => $criteria->term, 'tab' => $tab, 'page' => 1, 'per_page' => $per_page, 'total' => array_sum($counts ?? []), 'has_more' => false],
            ];
        }

        $search_tab = $this->tabs[$tab];
        $offset = ($page - 1) * $per_page;
        $results = $search_tab->results($criteria, $offset, $per_page);

        return [
            'data' => $results['data'],
            'counts' => $counts,
            'facets' => $page === 1 ? $search_tab->facets($criteria) : [],
            'meta' => [
                'query' => $criteria->term,
                'tab' => $tab,
                'page' => $page,
                'per_page' => $per_page,
                'total' => $results['total'],
                'has_more' => $offset + count($results['data']) < $results['total'],
            ],
        ];
    }
}
