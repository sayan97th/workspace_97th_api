<?php

namespace App\Http\Controllers\MyWork;

use App\Http\Controllers\Controller;
use App\Services\MyWork\MyWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyWorkController extends Controller
{
    public function __construct(private readonly MyWorkService $my_work) {}

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
}
