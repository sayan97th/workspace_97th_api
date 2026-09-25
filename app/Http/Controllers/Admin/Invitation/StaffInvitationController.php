<?php

namespace App\Http\Controllers\Admin\Invitation;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminStaffInvitationResource;
use App\Jobs\SendEmailJob;
use App\Mail\StaffInvitationMail;
use App\Models\StaffInvitation;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration > Users > Invitations: the account invitations sent from the Invite
 * dialog, with resend and cancel. Sending lives on `UserController::invite()`.
 */
class StaffInvitationController extends Controller
{
    private const STATUSES = ['pending', 'expired', 'accepted', 'all'];

    private const EXPIRY_DAYS = 7;

    /**
     * GET /api/admin/invitations?status=pending|expired|accepted|all&search=
     *
     * Also returns `counts` per status, so the tab badges stay right whichever tab is open.
     */
    public function index(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : 'pending';
        $search = trim((string) $request->query('search', ''));

        $query = StaffInvitation::with(['inviter:id,first_name,last_name,profile_photo_path', 'department:id,name'])
            ->orderByDesc('updated_at');

        $this->applyStatus($query, $status);

        if ($search !== '') {
            $query->where('email', 'LIKE', "%{$search}%");
        }

        $per_page = max(1, min((int) $request->query('per_page', 20), 100));
        $invitations = $query->paginate($per_page);

        return response()->json([
            'data' => AdminStaffInvitationResource::collection($invitations->items()),
            'current_page' => $invitations->currentPage(),
            'last_page' => $invitations->lastPage(),
            'total' => $invitations->total(),
            'counts' => [
                'pending' => $this->applyStatus(StaffInvitation::query(), 'pending')->count(),
                'expired' => $this->applyStatus(StaffInvitation::query(), 'expired')->count(),
            ],
        ]);
    }

    /**
     * POST /api/admin/invitations/{invitation}/resend
     *
     * Emails the same invitation again and restarts its expiry window, which is also how an
     * expired invitation is revived.
     */
    public function resend(Request $request, StaffInvitation $invitation): JsonResponse
    {
        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'This invitation was already accepted.'], 409);
        }

        $invitation->update(['expires_at' => now()->addDays(self::EXPIRY_DAYS)]);

        SendEmailJob::dispatchWithThrottle(new StaffInvitationMail($invitation->fresh('inviter')), $invitation->email);

        AuditLogger::log('user.invitation_resent', "Resent the invitation to {$invitation->email}.", $request->user(), ['email' => $invitation->email]);

        return response()->json([
            'message' => "Invitation resent to {$invitation->email}.",
            'invitation' => new AdminStaffInvitationResource($invitation->fresh(['inviter', 'department'])),
        ]);
    }

    /**
     * DELETE /api/admin/invitations/{invitation}
     *
     * Cancels the invitation, its link stops working immediately.
     */
    public function destroy(Request $request, StaffInvitation $invitation): JsonResponse
    {
        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'An accepted invitation cannot be canceled.'], 409);
        }

        $email = $invitation->email;
        $invitation->delete();

        AuditLogger::log('user.invitation_canceled', "Canceled the invitation to {$email}.", $request->user(), ['email' => $email]);

        return response()->json(['message' => "Invitation to {$email} canceled."]);
    }

    /**
     * @param  Builder<StaffInvitation>  $query
     * @return Builder<StaffInvitation>
     */
    private function applyStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'pending' => $query->whereNull('accepted_at')
                ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now())),
            'expired' => $query->whereNull('accepted_at')->where('expires_at', '<', now()),
            'accepted' => $query->whereNotNull('accepted_at'),
            default => $query,
        };
    }
}
