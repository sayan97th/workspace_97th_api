<?php

namespace App\Http\Controllers\Comment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comment\UpsertCommentDraftRequest;
use App\Models\CommentDraft;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Support\VisibleBoards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The current user's unsent update and reply drafts (Slack's drafts). The
 * comment composers save one while the user types, restore it when they come
 * back to the item or the board discussion, and remove it once it is sent or
 * discarded. Every draft is private to its owner.
 */
class CommentDraftController extends Controller
{
    /** How many drafts the "Drafts" list returns at most. */
    private const LIST_LIMIT = 100;

    /**
     * GET /api/comment-drafts
     *
     * Every draft of the user on boards they can still open, newest first,
     * with the board and item names the "Drafts" list shows.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $drafts = $user->commentDrafts()
            ->whereHas('board', fn (Builder $boards) => VisibleBoards::withoutHiddenPrivate($boards->where('is_archived', false), $user))
            ->where(fn (Builder $query) => $query->whereNull('item_id')->orWhereHas('item'))
            ->with(['board:id,label,workspace_id', 'board.workspace:id,name', 'item:id,name,parent_id'])
            ->latest('updated_at')
            ->limit(self::LIST_LIMIT)
            ->get();

        return response()->json(['data' => $drafts->map(fn (CommentDraft $draft) => $this->payload($draft, true))->values()]);
    }

    /**
     * GET /api/boards/{item}/comment-drafts
     *
     * The user's drafts on one board, item updates, replies and the board
     * discussion alike, loaded once when the board opens so every composer
     * restores instantly and the table can mark the items with a draft.
     */
    public function boardIndex(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $drafts = $request->user()->commentDrafts()
            ->where('board_id', $item->id)
            ->where(fn (Builder $query) => $query->whereNull('item_id')->orWhereHas('item'))
            ->latest('updated_at')
            ->get();

        return response()->json(['data' => $drafts->map(fn (CommentDraft $draft) => $this->payload($draft))->values()]);
    }

    /**
     * PUT /api/boards/{item}/comment-drafts
     *
     * Saves one composer's draft, or removes it when the body is empty.
     */
    public function upsert(UpsertCommentDraftRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $item_id = isset($validated['item_id']) ? (int) $validated['item_id'] : null;
        $parent_id = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;
        $draft_key = CommentDraft::keyFor($item->id, $item_id, $parent_id);
        $body = (string) ($validated['body'] ?? '');

        if (trim($body) === '') {
            $user->commentDrafts()->where('draft_key', $draft_key)->delete();

            return response()->json(['data' => null]);
        }

        $draft = $user->commentDrafts()->updateOrCreate(
            ['draft_key' => $draft_key],
            [
                'board_id' => $item->id,
                'item_id' => $item_id,
                'parent_id' => $parent_id,
                'body' => $body,
                'mentioned_user_ids' => $this->uniqueIds($validated['mentioned_user_ids'] ?? []),
                'notified_user_ids' => $this->uniqueIds($validated['notified_user_ids'] ?? []),
            ]
        );

        // A draft saved again with the same text keeps its timestamp, so "Draft saved" still moves forward.
        if (! $draft->wasRecentlyCreated && ! $draft->wasChanged()) {
            $draft->touch();
        }

        $this->pruneOldest($user);

        return response()->json(['data' => $this->payload($draft->fresh())]);
    }

    /**
     * DELETE /api/boards/{item}/comment-drafts?item_id=&parent_id=
     *
     * Discards one composer's draft. Removing one that is already gone is not an error.
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $draft_key = CommentDraft::keyFor(
            $item->id,
            isset($validated['item_id']) ? (int) $validated['item_id'] : null,
            isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
        );

        $request->user()->commentDrafts()->where('draft_key', $draft_key)->delete();

        return response()->json(['message' => 'Draft discarded.']);
    }

    /**
     * DELETE /api/comment-drafts/{comment_draft}
     *
     * Discards a draft from the "Drafts" list, where only its id is at hand.
     */
    public function destroyById(Request $request, CommentDraft $comment_draft): JsonResponse
    {
        abort_if($comment_draft->user_id !== $request->user()->id, 403);

        $comment_draft->delete();

        return response()->json(['message' => 'Draft discarded.']);
    }

    /**
     * Keeps the user under {@see CommentDraft::MAX_PER_USER} by dropping the oldest drafts.
     */
    private function pruneOldest(User $user): void
    {
        $stale_ids = $user->commentDrafts()
            ->latest('updated_at')
            ->latest('id')
            ->skip(CommentDraft::MAX_PER_USER)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($stale_ids->isNotEmpty()) {
            CommentDraft::query()->whereIn('id', $stale_ids)->delete();
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CommentDraft $draft, bool $with_context = false): array
    {
        $payload = [
            'id' => $draft->id,
            'board_id' => $draft->board_id,
            'item_id' => $draft->item_id,
            'parent_id' => $draft->parent_id,
            'body' => $draft->body,
            'mentioned_user_ids' => $draft->mentioned_user_ids ?? [],
            'notified_user_ids' => $draft->notified_user_ids ?? [],
            'updated_at' => $draft->updated_at?->toIso8601String(),
        ];

        if (! $with_context) {
            return $payload;
        }

        return [
            ...$payload,
            'board_name' => $draft->board?->label,
            'workspace_name' => $draft->board?->workspace?->name,
            'item_name' => $draft->item?->name,
            'is_subitem' => $draft->item?->parent_id !== null,
        ];
    }
}
