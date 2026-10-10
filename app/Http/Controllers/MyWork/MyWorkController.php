<?php

namespace App\Http\Controllers\MyWork;

use App\Http\Controllers\Controller;
use App\Http\Requests\MyWork\StoreMyWorkItemRequest;
use App\Models\BoardColumn;
use App\Models\BoardItemValue;
use App\Services\MyWork\MyWorkItemCreator;
use App\Services\MyWork\MyWorkService;
use App\Support\VisibleBoards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyWorkController extends Controller
{
    public function __construct(
        private readonly MyWorkService $my_work,
        private readonly MyWorkItemCreator $item_creator,
    ) {}

    /**
     * GET /api/my-work
     *
     * Every item assigned to the user across all boards. Bucketing by date
     * (Past dates, Today, This week, ...) happens on the client, in the
     * user's own timezone.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->my_work->forUser($request->user()));
    }

    /**
     * GET /api/my-work/boards
     *
     * The boards the user can add an item to from My Work's "New item".
     */
    public function boards(Request $request): JsonResponse
    {
        return response()->json(['boards' => $this->item_creator->boardsFor($request->user())]);
    }

    /**
     * GET /api/my-work/boards/{board_id}/form
     *
     * The groups and the People, date, Status and Priority columns of one
     * board, for the "New Item" dialog.
     */
    public function form(Request $request, int $board_id): JsonResponse
    {
        $user = $request->user();
        $board = VisibleBoards::query($user)->boards()->findOrFail($board_id);

        return response()->json($this->item_creator->formFor($user, $board));
    }

    /**
     * POST /api/my-work/items
     *
     * Creates an item on the chosen board, assigned to the user and due on
     * the section's date when one is given. `is_assigned` is false when the
     * board has no People column the user can edit, in which case the item
     * exists but does not show up in My Work.
     */
    public function store(StoreMyWorkItemRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $board = VisibleBoards::query($user)->boards()->findOrFail($validated['board_id']);

        $board_item = $this->item_creator->create($user, $board, $validated['name'], $validated['date'] ?? null, [
            'group_id' => $validated['group_id'] ?? null,
            'status' => $validated['status'] ?? null,
            'priority' => $validated['priority'] ?? null,
        ]);

        $is_assigned = BoardItemValue::query()
            ->where('item_id', $board_item->id)
            ->whereHas('column', fn ($query) => $query->where('type', BoardColumn::TYPE_PEOPLE))
            ->exists();

        return response()->json([
            'message' => 'Item created successfully.',
            'item' => ['id' => $board_item->id, 'board_id' => $board->id, 'name' => $board_item->name],
            'is_assigned' => $is_assigned,
        ], 201);
    }
}
