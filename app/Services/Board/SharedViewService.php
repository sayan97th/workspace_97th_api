<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardViewShareLink;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Builds the read only payload of a public "Share view" link. Only what the
 * page renders leaves the server: no emails, no files, no comments, and no
 * value of a column with a view restriction.
 */
class SharedViewService
{
    /** Views that own columns and items, so they can be shared as a table. */
    public const ITEM_VIEW_TYPES = ['table', 'kanban', 'calendar', 'gantt'];

    /** Every view type a public link can open: the item views, plus a Doc tab shared as a read only doc. */
    public const SHAREABLE_VIEW_TYPES = [...self::ITEM_VIEW_TYPES, 'doc'];

    /** Column types left out of a public view: file urls and internal links. */
    private const EXCLUDED_TYPES = [BoardColumn::TYPE_FILES, BoardColumn::TYPE_DEPENDENCY, BoardColumn::TYPE_CONNECT_BOARD, BoardColumn::TYPE_MIRROR, BoardColumn::TYPE_FORMULA];

    /** Upper bound on the rows one public page returns. */
    private const MAX_ITEMS = 2000;

    public function __construct(private readonly ColumnPermissionService $column_permissions) {}

    /**
     * Finds an enabled link, or aborts with a 404.
     */
    public function findLink(string $token): BoardViewShareLink
    {
        $link = BoardViewShareLink::query()
            ->where('token', $token)
            ->where('is_enabled', true)
            ->with(['board', 'boardView'])
            ->first();

        abort_if($link === null || $link->board === null || $link->boardView === null || $link->board->is_archived, 404, 'This link does not exist or was turned off.');

        return $link;
    }

    /**
     * Whether `$password` opens `$link`. A link without a password is open.
     */
    public function passwordMatches(BoardViewShareLink $link, ?string $password): bool
    {
        return $link->password === null || ($password !== null && $password !== '' && Hash::check($password, $link->password));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(BoardViewShareLink $link): array
    {
        $view = $link->boardView;

        if ($view->view_type === 'doc') {
            return $this->docPayload($link);
        }
        $hidden_ids = $this->column_permissions->hiddenColumnIds($link->board_id, null);
        $view_hidden_ids = array_map('intval', $view->hidden_column_ids ?? []);

        $columns = $view->columns()
            ->where('scope', BoardColumn::SCOPE_ITEM)
            ->whereNotIn('type', self::EXCLUDED_TYPES)
            ->get()
            ->reject(fn (BoardColumn $column) => in_array($column->id, $hidden_ids, true) || in_array($column->id, $view_hidden_ids, true))
            ->values();
        $column_ids = $columns->pluck('id')->all();

        $groups = $view->groups()->where('is_archived', false)->get();

        $items = BoardItem::query()
            ->where('board_id', $link->board_id)
            ->whereIn('group_id', $groups->pluck('id'))
            ->whereNull('parent_id')
            ->where('is_archived', false)
            ->with(['values' => fn ($query) => $query->whereIn('column_id', $column_ids)])
            ->withCount(['children as subitem_count' => fn ($query) => $query->where('is_archived', false)])
            ->orderBy('group_id')
            ->orderBy('position')
            ->limit(self::MAX_ITEMS)
            ->get();

        $link->forceFill(['last_accessed_at' => now()])->saveQuietly();

        return [
            'board' => ['label' => $link->board->label],
            'view' => ['label' => $view->label, 'view_type' => $view->view_type],
            'columns' => $columns->map(fn (BoardColumn $column) => [
                'id' => $column->id,
                'label' => $column->label,
                'type' => $column->type,
                'width' => $column->width,
                'options' => collect($column->config['options'] ?? [])
                    ->map(fn ($option) => ['id' => (string) $option['id'], 'label' => (string) $option['label'], 'color' => (string) ($option['color'] ?? '#c4c4c4')])
                    ->values(),
                // Rating columns only: the scale and icon the shared view draws the value with.
                'rating_max' => $column->type === BoardColumn::TYPE_RATING ? $column->ratingMax() : null,
                'rating_icon' => $column->type === BoardColumn::TYPE_RATING ? ($column->config['rating_icon'] ?? 'star') : null,
            ])->values(),
            'groups' => $groups->map(fn (BoardGroup $group) => ['id' => $group->id, 'name' => $group->name, 'color' => $group->accent_color])->values(),
            'items' => $items->map(fn (BoardItem $item) => [
                'id' => $item->id,
                'group_id' => $item->group_id,
                'name' => $item->name,
                'subitem_count' => $item->subitem_count,
                'values' => (object) $item->values->mapWithKeys(fn ($value) => [(string) $value->column_id => $value->value])->all(),
            ])->values(),
            'people' => $this->people($columns, $items),
            'is_truncated' => $items->count() >= self::MAX_ITEMS,
        ];
    }

    /**
     * A Doc tab's public payload: the body and its creator row, in the same
     * shape as an item view (no columns, groups or items) so the page can
     * tell the two apart by `view.view_type`. Only the creator's name and
     * photo leave the server.
     *
     * @return array<string, mixed>
     */
    private function docPayload(BoardViewShareLink $link): array
    {
        $view = $link->boardView->loadMissing('creator:id,first_name,last_name,profile_photo_path');
        $link->forceFill(['last_accessed_at' => now()])->saveQuietly();

        return [
            'board' => ['label' => $link->board->label],
            'view' => ['label' => $view->label, 'view_type' => $view->view_type],
            'doc' => [
                'content' => $view->doc_content,
                'creator' => $view->creator ? ['full_name' => $view->creator->full_name, 'profile_photo_url' => $view->creator->profile_photo_url] : null,
                'created_at' => $view->created_at?->toIso8601String(),
                'updated_at' => $view->updated_at?->toIso8601String(),
            ],
            'columns' => [],
            'groups' => [],
            'items' => [],
            'people' => (object) [],
            'is_truncated' => false,
        ];
    }

    /**
     * Display names for everyone referenced by a People column, keyed by id.
     * Only the name and initials leave the server.
     *
     * @param  Collection<int, BoardColumn>  $columns
     * @param  Collection<int, BoardItem>  $items
     */
    private function people($columns, $items): object
    {
        $people_column_ids = $columns->where('type', BoardColumn::TYPE_PEOPLE)->pluck('id')->all();
        if ($people_column_ids === []) {
            return (object) [];
        }

        $user_ids = $items
            ->flatMap(fn (BoardItem $item) => $item->values->whereIn('column_id', $people_column_ids)->pluck('value'))
            ->flatten()
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return (object) User::withTrashed()
            ->whereIn('id', $user_ids)
            ->get()
            ->mapWithKeys(fn (User $user) => [(string) $user->id => ['name' => $user->full_name]])
            ->all();
    }
}
