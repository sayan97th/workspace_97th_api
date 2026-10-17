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

    private const ROLES = ['super_admin', 'admin', 'staff', 'client'];

    private const SORTS = ['newest', 'oldest', 'email', 'expires'];

    private const EXPIRY_DAYS = 7;

    /**
     * GET /api/admin/invitations?status=pending|expired|accepted|all&search=&role=&sort=newest|oldest|email|expires
     *
     * Also returns `counts` per status (all, pending, expired, accepted), so the summary cards
     * stay right whichever status is open. Search matches the invited email, the inviter's name
     * or the name of the account that joined through the invitation.
     */
    public function index(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : 'pending';
        $role = in_array($request->query('role'), self::ROLES, true) ? $request->query('role') : null;
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'newest';
        $search = trim((string) $request->query('search', ''));

        $query = StaffInvitation::with([
            'inviter:id,first_name,last_name,profile_photo_path',
            'department:id,name',
            // Deactivated accounts are soft deleted, they still joined so the row keeps showing them.
            'account' => fn ($account) => $account->withTrashed()
                ->select(['id', 'email', 'first_name', 'last_name', 'job_title', 'profile_photo_path', 'deleted_at']),
        ]);

        $this->applyStatus($query, $status);
        $this->applySort($query, $sort);

        if ($role !== null) {
            $query->where('role', $role);
        }

        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('email', 'LIKE', "%{$search}%")
                ->orWhereHas('inviter', fn (Builder $inviter) => $inviter
                    ->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%"))
                ->orWhereHas('account', fn (Builder $account) => $account
                    ->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")));
        }

        $per_page = max(1, min((int) $request->query('per_page', 20), 100));
        $invitations = $query->paginate($per_page);

        return response()->json([
            'data' => AdminStaffInvitationResource::collection($invitations->items()),
            'current_page' => $invitations->currentPage(),
            'last_page' => $invitations->lastPage(),
            'total' => $invitations->total(),
            'counts' => [
                'all' => StaffInvitation::query()->count(),
                'pending' => $this->applyStatus(StaffInvitation::query(), 'pending')->count(),
                'expired' => $this->applyStatus(StaffInvitation::query(), 'expired')->count(),
                'accepted' => $this->applyStatus(StaffInvitation::query(), 'accepted')->count(),
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

    /**
     * Newest first by default (by `updated_at`, so a resend moves it to the top), the soonest
     * expiry first for `expires` with open ended rows last.
     *
     * @param  Builder<StaffInvitation>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->orderBy('updated_at'),
            'email' => $query->orderBy('email'),
            'expires' => $query->orderByRaw('expires_at IS NULL')->orderBy('expires_at'),
            default => $query->orderByDesc('updated_at'),
        };

        $query->orderByDesc('id');
    }
}
