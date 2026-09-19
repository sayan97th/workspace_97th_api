<?php

namespace App\Http\Controllers\Comment;

use App\Http\Controllers\Controller;
use App\Models\SavedReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The current user's reusable update and reply templates, inserted from the
 * comment composer's "Saved replies" menu and its `/` command menu. Every
 * template is private to its owner.
 */
class SavedReplyController extends Controller
{
    /**
     * GET /api/saved-replies
     */
    public function index(Request $request): JsonResponse
    {
        $replies = $request->user()->savedReplies()->orderBy('title')->orderBy('id')->get();

        return response()->json(['data' => $replies->map(fn (SavedReply $reply) => $this->payload($reply))->values()]);
    }

    /**
     * POST /api/saved-replies
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        abort_if(
            $request->user()->savedReplies()->count() >= SavedReply::MAX_PER_USER,
            422,
            'You can keep up to '.SavedReply::MAX_PER_USER.' saved replies.'
        );

        $reply = $request->user()->savedReplies()->create([
            'title' => trim($validated['title']),
            'body' => trim($validated['body']),
        ]);

        return response()->json(['data' => $this->payload($reply)], 201);
    }

    /**
     * PATCH /api/saved-replies/{saved_reply}
     */
    public function update(Request $request, SavedReply $saved_reply): JsonResponse
    {
        abort_if($saved_reply->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:60'],
            'body' => ['sometimes', 'required', 'string', 'max:5000'],
        ]);

        $saved_reply->update(array_map('trim', $validated));

        return response()->json(['data' => $this->payload($saved_reply->fresh())]);
    }

    /**
     * DELETE /api/saved-replies/{saved_reply}
     */
    public function destroy(Request $request, SavedReply $saved_reply): JsonResponse
    {
        abort_if($saved_reply->user_id !== $request->user()->id, 403);

        $saved_reply->delete();

        return response()->json(['message' => 'Saved reply deleted.']);
    }

    /**
     * @return array{id: int, title: string, body: string}
     */
    private function payload(SavedReply $reply): array
    {
        return ['id' => $reply->id, 'title' => $reply->title, 'body' => $reply->body];
    }
}
