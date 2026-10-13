<?php

namespace App\Services\Search;

use App\Models\BoardItemValue;
use App\Models\BoardTag;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Support\LikeTerm;
use App\Support\VisibleBoards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Account wide view of the Tags column, mirroring monday.com: a tag created on a main (public)
 * board is a public tag every board can reuse, while a tag created on a private or shareable
 * board stays on that board. Tags are still stored per board ({@see BoardTag}), so cell values
 * keep pointing at the same ids.
 *
 * Also counts how many items use each tag, for the "#seo 1369" pills of the Tags picker and the
 * Tags tab of Search Everything. Counting reads every Tags cell on the boards the user can see
 * once per request and is cached on this instance.
 */
class TagCatalog
{
    /**
     * Tag id => ids of the items tagged with it, keyed by the "include archived" flag.
     *
     * @var array<string, array<int, array<int, true>>>
     */
    private array $usage_cache = [];

    /**
     * Every tag a Tags cell on `$board` can pick: the board's own tags in their saved order,
     * then the public tags of the other boards the user can see, alphabetically. A public tag
     * whose label is already on the list (case-insensitive) is left out, so "#seo" shows once.
     * Each tag carries a `usage_count` attribute.
     *
     * @return Collection<int, BoardTag>
     */
    public function forBoard(User $user, WorkspaceNavigationItem $board): Collection
    {
        $own_tags = $board->tags()->get();
        $seen_labels = $own_tags->mapWithKeys(fn (BoardTag $tag) => [self::normalizeLabel($tag->label) => true])->all();

        $shared_tags = $this->publicTagsQuery($user)
            ->where('board_tags.board_id', '!=', $board->id)
            ->orderBy('board_tags.label')
            ->orderBy('board_tags.id')
            ->get()
            ->filter(function (BoardTag $tag) use (&$seen_labels) {
                $key = self::normalizeLabel($tag->label);
                if (isset($seen_labels[$key])) {
                    return false;
                }
                $seen_labels[$key] = true;

                return true;
            });

        return $this->withUsageCounts($user, $own_tags->concat($shared_tags->values()));
    }

    /**
     * Public tags whose label contains `$term`, for the Tags tab. A leading "#" in the term is
     * ignored, so "#seo" and "seo" find the same tags.
     *
     * @return Collection<int, BoardTag>
     */
    public function publicTags(User $user, string $term = ''): Collection
    {
        $tags = LikeTerm::whereContains($this->publicTagsQuery($user), ['board_tags.label'], self::stripHash($term))
            ->orderBy('board_tags.label')
            ->orderBy('board_tags.id')
            ->get();

        return $this->withUsageCounts($user, $tags);
    }

    /**
     * How many public tags match `$term`, without loading or counting their usage.
     */
    public function countPublicTags(User $user, string $term = ''): int
    {
        return LikeTerm::whereContains($this->publicTagsQuery($user), ['board_tags.label'], self::stripHash($term))->count();
    }

    /**
     * An existing public tag with exactly this label (case-insensitive) on a board the user can
     * see, so creating "#seo" on a main board reuses the account's "#seo" instead of adding a
     * duplicate.
     */
    public function findPublicTag(User $user, string $label): ?BoardTag
    {
        return $this->publicTagsQuery($user)
            ->whereRaw('LOWER(board_tags.label) = ?', [mb_strtolower(trim($label))])
            ->orderBy('board_tags.id')
            ->first();
    }

    /**
     * Whether `$tag` shows up in the picker of `$board`: it lives there, or it is a public tag
     * the user can see.
     */
    public function isAvailableOn(User $user, WorkspaceNavigationItem $board, BoardTag $tag): bool
    {
        return $tag->board_id === $board->id
            || $this->publicTagsQuery($user)->whereKey($tag->id)->exists();
    }

    /**
     * Ids of the items (on boards the user can see) tagged with any of `$tag_ids`.
     *
     * @param  array<int, int>  $tag_ids
     * @return array<int, int>
     */
    public function itemIdsForTags(User $user, array $tag_ids, bool $include_archived = false): array
    {
        $usage = $this->usage($user, $include_archived);
        $item_ids = [];

        foreach ($tag_ids as $tag_id) {
            $item_ids += $usage[$tag_id] ?? [];
        }

        return array_keys($item_ids);
    }

    /**
     * Ids of the tags, on any board the user can see (private ones included, their items are
     * already limited to visible boards), whose label matches `$term`. A leading "#" is ignored.
     * Tags with exactly that label win, so "#seo" does not also pull in "#seooptimizations",
     * otherwise every label containing the term matches.
     *
     * @return array<int, int>
     */
    public function tagIdsMatching(User $user, string $term): array
    {
        $term = self::stripHash($term);
        if ($term === '') {
            return [];
        }

        $visible_tags = BoardTag::query()->whereIn('board_id', VisibleBoards::query($user)->select('id'));

        $exact_ids = $visible_tags->clone()
            ->whereRaw('LOWER(label) IN (?, ?)', [mb_strtolower($term), '#'.mb_strtolower($term)])
            ->pluck('id')
            ->all();

        return $exact_ids !== []
            ? $exact_ids
            : LikeTerm::whereContains($visible_tags, ['label'], $term)->pluck('id')->all();
    }

    /**
     * Sets a `usage_count` attribute on every tag.
     *
     * @param  Collection<int, BoardTag>  $tags
     * @return Collection<int, BoardTag>
     */
    public function withUsageCounts(User $user, Collection $tags): Collection
    {
        $usage = $this->usage($user, false);

        return $tags->each(fn (BoardTag $tag) => $tag->setAttribute('usage_count', count($usage[$tag->id] ?? [])));
    }

    /**
     * Tags of the main boards the user can see. Private and shareable boards keep their tags
     * to themselves.
     *
     * @return Builder<BoardTag>
     */
    private function publicTagsQuery(User $user): Builder
    {
        $public_board_ids = VisibleBoards::query($user)
            ->where(fn (Builder $query) => $query->where('board_type', WorkspaceNavigationItem::BOARD_TYPE_MAIN)->orWhereNull('board_type'))
            ->select('id');

        return BoardTag::query()->select('board_tags.*')->whereIn('board_tags.board_id', $public_board_ids);
    }

    /**
     * Reads every Tags cell on the boards the user can see and maps each tag id to the items
     * that use it. Cells store tag ids as strings. Older imported cells may hold the raw label
     * instead ("design"), those match a tag of the same board by label, with or without "#".
     *
     * @return array<int, array<int, true>>
     */
    private function usage(User $user, bool $include_archived): array
    {
        $cache_key = $user->id.':'.($include_archived ? '1' : '0');
        if (isset($this->usage_cache[$cache_key])) {
            return $this->usage_cache[$cache_key];
        }

        $visible_board_ids = VisibleBoards::query($user, $include_archived)->select('id');

        $tag_ids = BoardTag::query()->pluck('id')->flip()->all();
        $labels_by_board = [];
        BoardTag::query()->get(['id', 'board_id', 'label'])->each(function (BoardTag $tag) use (&$labels_by_board) {
            $labels_by_board[$tag->board_id][self::normalizeLabel($tag->label)] = $tag->id;
        });

        $usage = [];

        BoardItemValue::query()
            ->join('board_columns', 'board_columns.id', '=', 'board_item_values.column_id')
            ->join('board_items', 'board_items.id', '=', 'board_item_values.item_id')
            ->where('board_columns.type', 'tags')
            ->whereNull('board_items.deleted_at')
            ->whereIn('board_items.board_id', $visible_board_ids)
            ->unless($include_archived, fn ($query) => $query->where('board_items.is_archived', false))
            ->select(['board_item_values.item_id', 'board_item_values.value', 'board_items.board_id'])
            ->toBase()
            ->orderBy('board_item_values.id')
            ->chunk(1000, function ($rows) use (&$usage, $tag_ids, $labels_by_board) {
                foreach ($rows as $row) {
                    $entries = is_string($row->value) ? json_decode($row->value, true) : $row->value;
                    if (! is_array($entries)) {
                        continue;
                    }

                    foreach ($entries as $entry) {
                        if (! is_scalar($entry)) {
                            continue;
                        }
                        $entry = (string) $entry;
                        $tag_id = ctype_digit($entry) && isset($tag_ids[(int) $entry])
                            ? (int) $entry
                            : ($labels_by_board[$row->board_id][self::normalizeLabel($entry)] ?? null);

                        if ($tag_id !== null) {
                            $usage[$tag_id][(int) $row->item_id] = true;
                        }
                    }
                }
            });

        return $this->usage_cache[$cache_key] = $usage;
    }

    /**
     * Lowercase label without its leading "#", so "#SEO" and "seo" compare equal.
     */
    public static function normalizeLabel(string $label): string
    {
        return mb_strtolower(self::stripHash($label));
    }

    private static function stripHash(string $value): string
    {
        return ltrim(trim($value), '#');
    }
}
