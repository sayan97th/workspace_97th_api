<?php

namespace App\Services\Search\Everything;

/**
 * One tab of Search Everything (Cross Boards, Updates, Files, People, Tags, Docs).
 */
interface SearchTab
{
    /**
     * The number next to the tab name: every match for the term and date range, ignoring the
     * tab's own facet checkboxes.
     */
    public function count(SearchCriteria $criteria): int;

    /**
     * One page of results with the facet checkboxes applied.
     *
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function results(SearchCriteria $criteria, int $offset, int $limit): array;

    /**
     * Count per facet checkbox (empty for tabs without facets).
     *
     * @return array<string, int>
     */
    public function facets(SearchCriteria $criteria): array;
}
