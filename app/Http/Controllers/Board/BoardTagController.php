<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardTagRequest;
use App\Http\Requests\Board\UpdateBoardTagRequest;
use App\Http\Resources\BoardTagResource;
use App\Models\BoardTag;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Search\TagCatalog;
use App\Support\BoardEditGate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Tags column's option list (`#label` + color). Tags are stored per board,
 * see {@see BoardTag}, but the picker also offers the public tags
 * of every other main board the user can see, like monday.com's account wide
 * tags, see {@see TagCatalog}.
 */
class BoardTagController extends Controller
{
    public function __construct(
        private readonly TagCatalog $tag_catalog,
    ) {}

    /**
     * GET /api/boards/{item}/tags
     *
     * The board's own tags first, then the shared public tags, each with the
     * number of items using it (`usage_count`).
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => BoardTagResource::collection($this->tag_catalog->forBoard($user, $item)),
        ]);
    }

    /**
     * POST /api/boards/{item}/tags
     *
     * Fired from a Tags cell's own "Create new tag" or from the column's
     * "Manage tags" modal, either way it lands at the end of the board's tag
     * list. On a main board an existing public tag with the same label is
     * returned instead (200), so the account never ends up with two "#seo".
     */
    public function store(StoreBoardTagRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        BoardEditGate::authorize($item, $user);

        $validated = $request->validated();

        if ($this->isPublicBoard($item) && ($existing = $this->tag_catalog->findPublicTag($user, $validated['label']))) {
            return response()->json([
                'message' => 'Tag already exists, it was reused.',
                'tag' => new BoardTagResource($this->tag_catalog->withUsageCounts($user, new Collection([$existing]))->first()),
            ]);
        }

        $tag = $item->tags()->create([
            'label' => $validated['label'],
            'color' => $validated['color'],
            'position' => $this->nextPosition($item),
        ]);

        return response()->json([
            'message' => 'Tag created successfully.',
            'tag' => new BoardTagResource($tag),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/tags/{tag}
     */
    public function update(UpdateBoardTagRequest $request, WorkspaceNavigationItem $item, BoardTag $tag): JsonResponse
    {
        $this->authorizeTagEdit($request, $item, $tag);

        $tag->fill($request->validated())->save();

        return response()->json([
            'message' => 'Tag updated successfully.',
            'tag' => new BoardTagResource($tag->fresh()),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/tags/{tag}
     *
     * Permanently removes the tag. Every cell that had it selected simply
     * drops it from its own value the next time that item is saved, mirroring
     * a deleted Status/Dropdown option.
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardTag $tag): JsonResponse
    {
        $this->authorizeTagEdit($request, $item, $tag);

        $tag->delete();

        return response()->json([
            'message' => 'Tag deleted successfully.',
        ]);
    }

    /**
     * A tag can be edited from any board whose picker shows it (see
     * {@see TagCatalog::isAvailableOn()}), by someone allowed to edit the board
     * the tag lives on. Anything else is a 404 or a 403.
     */
    private function authorizeTagEdit(Request $request, WorkspaceNavigationItem $item, BoardTag $tag): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($this->tag_catalog->isAvailableOn($user, $item, $tag), 404);
        BoardEditGate::authorize($tag->board_id === $item->id ? $item : $tag->board, $user);
    }

    /**
     * Main boards share their tags with the whole account, private and
     * shareable boards keep them.
     */
    private function isPublicBoard(WorkspaceNavigationItem $item): bool
    {
        return $item->board_type === WorkspaceNavigationItem::BOARD_TYPE_MAIN;
    }

    /**
     * The next free position among the board's tags (append to the end).
     */
    private function nextPosition(WorkspaceNavigationItem $item): int
    {
        return (int) $item->tags()->max('position') + 1;
    }
}
