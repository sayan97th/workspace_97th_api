<?php

namespace App\Services\Search\Everything;

use App\Models\BoardItem;
use App\Models\BoardItemAttachment;
use App\Models\BoardItemCommentAttachment;
use App\Models\BoardItemValue;
use App\Models\User;
use App\Support\LikeTerm;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * "Files": every file the user can reach, from three places: files attached to updates, files
 * attached to items (drawer Files tab) and Files column cells (uploads and "From Link" links).
 * The three sources are merged in PHP, since Files cells live inside JSON, then filtered by the
 * file type checkboxes, sorted and paged. The merged list is cached per criteria for the request.
 */
final class FilesTab implements SearchTab
{
    /**
     * @var array<string, Collection<int, array<string, mixed>>>
     */
    private array $rows_cache = [];

    public function count(SearchCriteria $criteria): int
    {
        return $this->rows($criteria)->count();
    }

    public function results(SearchCriteria $criteria, int $offset, int $limit): array
    {
        $rows = $this->rows($criteria);
        if ($criteria->file_types !== []) {
            $rows = $rows->filter(fn (array $row) => in_array($row['kind'], $criteria->file_types, true));
        }

        $term = mb_strtolower($criteria->term);
        $rows = $rows->sortBy([
            fn (array $a, array $b) => $criteria->sortsByTime() || $term === ''
                ? 0
                : (int) ! str_starts_with(mb_strtolower($a['name']), $term) <=> (int) ! str_starts_with(mb_strtolower($b['name']), $term),
            fn (array $a, array $b) => ($b['created_at']?->getTimestamp() ?? 0) <=> ($a['created_at']?->getTimestamp() ?? 0),
        ])->values();

        $page = $rows->slice($offset, $limit)->values();

        return ['data' => $this->present($page), 'total' => $rows->count()];
    }

    public function facets(SearchCriteria $criteria): array
    {
        $counts = $this->rows($criteria)->countBy('kind');

        return collect(FileKind::ALL)->mapWithKeys(fn (string $kind) => [$kind => (int) ($counts[$kind] ?? 0)])->all();
    }

    /**
     * Every file matching the term and date range, newest first, as lightweight rows.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(SearchCriteria $criteria): Collection
    {
        $cache_key = md5(serialize([$criteria->user->id, $criteria->term, $criteria->date_from, $criteria->date_to, $criteria->include_archived]));

        return $this->rows_cache[$cache_key] ??= $this->commentAttachmentRows($criteria)
            ->concat($this->itemAttachmentRows($criteria))
            ->concat($this->cellFileRows($criteria))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function commentAttachmentRows(SearchCriteria $criteria): Collection
    {
        $query = BoardItemCommentAttachment::query()
            ->join('board_item_comments', 'board_item_comments.id', '=', 'board_item_comment_attachments.comment_id')
            ->whereNull('board_item_comments.deleted_at')
            ->whereIn('board_item_comments.item_id', $criteria->itemIds())
            ->select('board_item_comment_attachments.*', 'board_item_comments.item_id');

        LikeTerm::whereContains($query, ['board_item_comment_attachments.file_name'], $criteria->term);
        $criteria->applyDateRange($query, 'board_item_comment_attachments.created_at');

        return $query->get()->map(fn (BoardItemCommentAttachment $file) => $this->row(
            key: "comment-{$file->id}",
            name: $file->file_name,
            url: $file->download_url,
            extension: $file->extension ?: FileKind::extensionOf($file->file_name),
            mime_type: $file->mime_type,
            created_at: $file->created_at?->toImmutable(),
            uploaded_by_id: $file->uploaded_by_id,
            item_id: (int) $file->getAttribute('item_id'),
            source: 'update',
        ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function itemAttachmentRows(SearchCriteria $criteria): Collection
    {
        $query = BoardItemAttachment::query()->whereIn('item_id', $criteria->itemIds());

        LikeTerm::whereContains($query, ['file_name'], $criteria->term);
        $criteria->applyDateRange($query, 'created_at');

        return $query->get()->map(fn (BoardItemAttachment $file) => $this->row(
            key: "item-{$file->id}",
            name: $file->file_name,
            url: $file->download_url,
            extension: $file->extension ?: FileKind::extensionOf($file->file_name),
            mime_type: $file->mime_type,
            created_at: $file->created_at?->toImmutable(),
            uploaded_by_id: $file->uploaded_by_id,
            item_id: $file->item_id,
            source: 'item',
        ));
    }

    /**
     * Files column cells hold a JSON list of `{id, url, path, file_name, mime_type, kind?}`.
     * Uploaded files get their URL from the storage disk, links keep their own.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function cellFileRows(SearchCriteria $criteria): Collection
    {
        $term = mb_strtolower($criteria->term);
        $rows = collect();

        BoardItemValue::query()
            ->join('board_columns', 'board_columns.id', '=', 'board_item_values.column_id')
            ->where('board_columns.type', 'files')
            ->whereIn('board_item_values.item_id', $criteria->itemIds())
            ->select('board_item_values.id', 'board_item_values.item_id', 'board_item_values.value', 'board_item_values.updated_at')
            ->orderBy('board_item_values.id')
            ->chunk(500, function ($values) use ($criteria, $term, $rows) {
                foreach ($values as $value) {
                    $updated_at = $value->updated_at?->toImmutable();
                    if (! is_array($value->value) || ! $criteria->isInDateRange($updated_at)) {
                        continue;
                    }

                    foreach ($value->value as $index => $file) {
                        $name = is_array($file) ? (string) ($file['file_name'] ?? '') : '';
                        if ($name === '' || ($term !== '' && ! str_contains(mb_strtolower($name), $term))) {
                            continue;
                        }

                        $is_link = ($file['kind'] ?? null) === 'link';
                        $path = $file['path'] ?? null;
                        $url = ! $is_link && is_string($path) && $path !== ''
                            ? Storage::disk(config('filesystems.app_disk'))->url($path)
                            : (string) ($file['url'] ?? '');

                        $rows->push($this->row(
                            key: 'cell-'.$value->id.'-'.($file['id'] ?? $index),
                            name: $name,
                            url: $url,
                            extension: FileKind::extensionOf(is_string($path) && $path !== '' ? $path : $name),
                            mime_type: $is_link ? null : ($file['mime_type'] ?? null),
                            created_at: $updated_at,
                            uploaded_by_id: null,
                            item_id: (int) $value->item_id,
                            source: $is_link ? 'link' : 'cell',
                        ));
                    }
                }
            });

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $key,
        string $name,
        string $url,
        string $extension,
        ?string $mime_type,
        ?CarbonImmutable $created_at,
        ?int $uploaded_by_id,
        int $item_id,
        string $source,
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'url' => $url,
            'extension' => strtolower($extension),
            'kind' => FileKind::classify($extension, $mime_type),
            'created_at' => $created_at,
            'uploaded_by_id' => $uploaded_by_id,
            'item_id' => $item_id,
            'source' => $source,
        ];
    }

    /**
     * Loads the uploaders and items of one page only.
     *
     * @param  Collection<int, array<string, mixed>>  $page
     * @return array<int, array<string, mixed>>
     */
    private function present(Collection $page): array
    {
        $users = User::withTrashed()->whereIn('id', $page->pluck('uploaded_by_id')->filter()->unique())->get()->keyBy('id');
        $items = BoardItem::query()
            ->whereIn('id', $page->pluck('item_id')->unique())
            ->with(['group:id,board_view_id', 'board.workspace', 'creator'])
            ->get()
            ->keyBy('id');

        return $page->map(function (array $row) use ($users, $items) {
            $item = $items[$row['item_id']] ?? null;
            // Files cells don't record who uploaded them, the item's creator is the closest owner.
            $uploader = $row['uploaded_by_id'] ? ($users[$row['uploaded_by_id']] ?? null) : $item?->creator;

            return [
                'key' => $row['key'],
                'name' => $row['name'],
                'url' => $row['url'],
                'extension' => $row['extension'],
                'kind' => $row['kind'],
                'source' => $row['source'],
                'created_at' => $row['created_at']?->toIso8601String(),
                'uploaded_by' => SearchPresenter::person($uploader),
                'item' => $item ? SearchPresenter::item($item) : null,
                'board' => $item?->board ? SearchPresenter::board($item->board) : null,
            ];
        })->all();
    }
}
