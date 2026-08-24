<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * GET /api/admin/audit-log
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $event = $request->query('event');

        $query = AuditLog::with('actor:id,first_name,last_name')->orderByDesc('created_at');

        if ($search !== null && $search !== '') {
            $query->whereHas('actor', function ($actor_query) use ($search) {
                $actor_query->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        if ($event !== null && $event !== '') {
            $query->where('event', $event);
        }

        $per_page = min((int) $request->query('per_page', 25), 200);
        $logs = $query->paginate($per_page);

        return response()->json([
            'data' => AuditLogResource::collection($logs->items()),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'total' => $logs->total(),
        ]);
    }
}
