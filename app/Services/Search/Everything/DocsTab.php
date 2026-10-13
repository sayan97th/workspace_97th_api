<?php

namespace App\Services\Search\Everything;

use App\Models\BoardDoc;
use App\Models\WorkspaceNavigationItem;
use App\Support\LikeTerm;
use App\Support\VisibleBoards;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Docs": Docs the user can open, by title or by their content. Like monday.com this tab only
 * searches once something is typed, an empty term returns nothing and the frontend shows its
 * "Type to search docs" state.
 */
final class DocsTab implements SearchTab
{
    public function count(SearchCriteria $criteria): int
    {
        return $criteria->hasTerm() ? $this->query($criteria)->count() : 0;
    }

    public function results(SearchCriteria $criteria, int $offset, int $limit): array
    {
        if (! $criteria->hasTerm()) {
            return ['data' => [], 'total' => 0];
        }

        $query = $this->query($criteria);
        $total = $query->clone()->count();

        if ($criteria->sortsByTime()) {
            $query->orderByDesc('updated_at');
        } else {
            LikeTerm::orderByPrefix($query, 'label', $criteria->term)->orderBy('label');
        }

        $docs = $query->with(['workspace'])->orderBy('id')->offset($offset)->limit($limit)->get();
        $bodies = BoardDoc::query()->whereIn('board_id', $docs->pluck('id'))->with('updatedBy')->get()->keyBy('board_id');

        $data = $docs->map(function (WorkspaceNavigationItem $doc) use ($bodies, $criteria) {
            $body = $bodies[$doc->id] ?? null;

            return [
                ...SearchPresenter::board($doc),
                'excerpt' => PlainText::fromDocJson($body?->content, $criteria->term),
                'updated_at' => ($body?->updated_at ?? $doc->updated_at)?->toIso8601String(),
                'updated_by' => SearchPresenter::person($body?->updatedBy),
            ];
        })->all();

        return ['data' => $data, 'total' => $total];
    }

    public function facets(SearchCriteria $criteria): array
    {
        return [];
    }

    /**
     * @return Builder<WorkspaceNavigationItem>
     */
    private function query(SearchCriteria $criteria): Builder
    {
        $matching_bodies = LikeTerm::whereContains(BoardDoc::query(), ['content'], $criteria->term)->select('board_id');

        $query = VisibleBoards::query($criteria->user, $criteria->include_archived)
            ->whereIn('view_key', WorkspaceNavigationItem::ASSET_TYPE_VIEW_KEYS[WorkspaceNavigationItem::ASSET_TYPE_DOC])
            ->where(fn (Builder $docs) => LikeTerm::whereContains($docs, ['label'], $criteria->term)
                ->orWhereIn('id', $matching_bodies));

        return $criteria->applyDateRange($query, 'updated_at');
    }
}
