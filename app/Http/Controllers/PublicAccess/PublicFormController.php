<?php

namespace App\Http\Controllers\PublicAccess;

use App\Http\Controllers\Controller;
use App\Services\Board\BoardFormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A board form opened by anyone holding its link, no account needed.
 */
class PublicFormController extends Controller
{
    public function __construct(private readonly BoardFormService $forms) {}

    /**
     * GET /api/public/forms/{token}
     */
    public function show(string $token): JsonResponse
    {
        return response()->json($this->forms->publicDefinition($this->forms->findPublicForm($token)));
    }

    /**
     * POST /api/public/forms/{token}/submissions
     */
    public function submit(Request $request, string $token): JsonResponse
    {
        $form_view = $this->forms->findPublicForm($token);
        $this->forms->submit($form_view, $request->only(['name', 'answers']));

        return response()->json([
            'message' => $this->forms->publicDefinition($form_view)['success_message'],
        ], 201);
    }
}
