<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFeedbackRequest;
use App\Models\FeedbackEntry;
use Illuminate\Http\JsonResponse;

/**
 * The board options menu's "Give feedback" item — a lightweight, always
 * available channel for a free-form product note, independent of any
 * specific bug-report/support-ticket flow.
 */
class FeedbackController extends Controller
{
    /**
     * POST /api/feedback
     */
    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $validated = $request->validated();

        FeedbackEntry::create([
            'user_id' => $request->user()?->id,
            'board_id' => $validated['board_id'] ?? null,
            'message' => $validated['message'],
            'page_url' => $validated['page_url'] ?? null,
        ]);

        return response()->json([
            'message' => 'Thanks for the feedback!',
        ], 201);
    }
}
