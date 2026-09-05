<?php

use App\Http\Controllers\AccountTeam\AccountTeamController;
use App\Http\Controllers\AccountTeam\AccountTeamMemberController;
use App\Http\Controllers\Admin\AccountSetting\AccountSettingController;
use App\Http\Controllers\Admin\AccountSetting\AdvancedSettingsController;
use App\Http\Controllers\Admin\AccountSetting\AuthenticationSettingsController;
use App\Http\Controllers\Admin\AccountSetting\BrandingController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BoardOwnershipController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\Role\RoleController;
use App\Http\Controllers\Admin\SessionController as AdminSessionController;
use App\Http\Controllers\Admin\User\UserController as AdminUserController;
use App\Http\Controllers\Admin\WebsocketTest\WebsocketTestController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\BoardInvitationController as AuthBoardInvitationController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\StaffInvitationController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\WorkspaceInvitationController as AuthWorkspaceInvitationController;
use App\Http\Controllers\Auth\WorkspaceInviteLinkController as AuthWorkspaceInviteLinkController;
use App\Http\Controllers\Board\BoardColumnController;
use App\Http\Controllers\Board\BoardCommentController;
use App\Http\Controllers\Board\BoardGroupController;
use App\Http\Controllers\Board\BoardInvitationController;
use App\Http\Controllers\Board\BoardItemAttachmentController;
use App\Http\Controllers\Board\BoardItemChecklistItemController;
use App\Http\Controllers\Board\BoardItemCommentController;
use App\Http\Controllers\Board\BoardItemController;
use App\Http\Controllers\Board\BoardViewController;
use App\Http\Controllers\Board\BoardViewFileController;
use App\Http\Controllers\Board\BoardViewImageController;
use App\Http\Controllers\BrandingController as PublicBrandingController;
use App\Http\Controllers\BroadcastAuthController;
use App\Http\Controllers\Feed\FeedUpdateController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Profile\LocalePreferenceController;
use App\Http\Controllers\Profile\NotificationPreferenceController;
use App\Http\Controllers\Profile\PasswordController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\ProfilePhotoController;
use App\Http\Controllers\Profile\UserSessionController;
use App\Http\Controllers\Profile\WorkingStatusController;
use App\Http\Controllers\Workspace\BoardController;
use App\Http\Controllers\Workspace\ContentController;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Controllers\Workspace\WorkspaceInvitationController;
use App\Http\Controllers\Workspace\WorkspaceInviteLinkController;
use App\Http\Controllers\Workspace\WorkspaceNavigationItemController;
use App\Http\Controllers\Workspace\WorkspacePermissionController;
use Illuminate\Support\Facades\Route;

// ─── Auth routes ────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('two-factor-challenge', [AuthController::class, 'twoFactorChallenge']);
    Route::post('forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('reset-password', [PasswordResetController::class, 'resetPassword']);

    // Public — the invitee may not have an account or session yet.
    Route::prefix('invitations')->group(function () {
        Route::get('{invitation}', [AuthWorkspaceInvitationController::class, 'show']);
        Route::post('{invitation}/accept', [AuthWorkspaceInvitationController::class, 'accept']);
        Route::post('{invitation}/decline', [AuthWorkspaceInvitationController::class, 'decline']);
    });

    // Public, the "Invite with link" share link. Whoever holds the link may
    // not have an account or session yet either.
    Route::prefix('workspaces/join')->group(function () {
        Route::get('{invite_code}', [AuthWorkspaceInviteLinkController::class, 'show']);
        Route::post('{invite_code}', [AuthWorkspaceInviteLinkController::class, 'accept']);
    });

    // Public — the "Invite to this board" emailed link, granting view access
    // to a single board rather than a whole workspace.
    Route::prefix('board-invitations')->group(function () {
        Route::get('{invitation}', [AuthBoardInvitationController::class, 'show']);
        Route::post('{invitation}/accept', [AuthBoardInvitationController::class, 'accept']);
        Route::post('{invitation}/decline', [AuthBoardInvitationController::class, 'decline']);
    });

    // Public — the Administration "Invite" emailed link for a brand-new platform user, with
    // a role (and optionally a department) pre-assigned before they register.
    Route::prefix('staff-invitations')->group(function () {
        Route::get('{invitation}', [StaffInvitationController::class, 'show']);
        Route::post('{invitation}/accept', [StaffInvitationController::class, 'accept']);
    });

    Route::prefix('google')->group(function () {
        Route::get('redirect', [GoogleAuthController::class, 'redirect']);
        Route::get('callback', [GoogleAuthController::class, 'callback']);
    });

    Route::middleware(['auth:api', 'session.active'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
    });
});

// ─── Authenticated routes ───────────────────────────────────────────────────
Route::middleware(['auth:api', 'active', 'session.active', 'panic.mode', 'ip.allowed', 'two_factor.enforced'])->group(function () {

    // Broadcasting auth (JWT-based) — used by the frontend's Echo client to
    // subscribe to private channels, see routes/channels.php.
    Route::post('broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);

    // Account branding (logo, email header) — readable by any authenticated user, not just
    // staff, since the top bar shows it for everyone. Managed at `/admin/account-settings/*`.
    Route::get('branding', [PublicBrandingController::class, 'show']);

    // Notifications — real-time (Reverb) + REST-readable notification feed.
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('{notification}/read', [NotificationController::class, 'markAsRead']);
    });

    // Update Feed — real-time (Reverb) + REST-readable stream of comment
    // "updates" (item- and board-level) the current user has visibility on.
    Route::prefix('feed')->group(function () {
        Route::get('updates', [FeedUpdateController::class, 'index']);
        Route::get('boards', [FeedUpdateController::class, 'boards']);
        Route::get('unread-count', [FeedUpdateController::class, 'unreadCount']);
        Route::post('updates/{id}/bookmark', [FeedUpdateController::class, 'toggleBookmark']);
        Route::post('updates/{id}/like', [FeedUpdateController::class, 'toggleLike']);
        Route::post('updates/{id}/reply', [FeedUpdateController::class, 'reply']);
        Route::post('updates/{id}/seen', [FeedUpdateController::class, 'markSeen']);
        Route::post('updates/{id}/schedule', [FeedUpdateController::class, 'schedule']);
    });

    // Profile — available to any authenticated user
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::patch('/', [ProfileController::class, 'partialUpdate']);
        Route::put('password', [PasswordController::class, 'update']);
        Route::post('photo', [ProfilePhotoController::class, 'store']);
        Route::delete('photo', [ProfilePhotoController::class, 'destroy']);

        Route::patch('working-status', [WorkingStatusController::class, 'update']);
        Route::patch('notifications', [NotificationPreferenceController::class, 'update']);
        Route::patch('locale', [LocalePreferenceController::class, 'update']);

        Route::get('sessions', [UserSessionController::class, 'index']);
        Route::delete('sessions/{session}', [UserSessionController::class, 'destroy']);
    });

    // Workspaces — dynamic sidebar + nested navigation tree
    Route::prefix('workspaces')->group(function () {
        Route::get('/', [WorkspaceController::class, 'index']);
        Route::post('/', [WorkspaceController::class, 'store']);
        Route::get('{workspace}', [WorkspaceController::class, 'show']);
        Route::patch('{workspace}', [WorkspaceController::class, 'update']);
        Route::delete('{workspace}', [WorkspaceController::class, 'destroy']);
        Route::post('{workspace}/leave', [WorkspaceController::class, 'leave']);
        Route::post('{workspace}/transfer-ownership', [WorkspaceController::class, 'transferOwnership']);
        Route::get('{workspace}/members', [WorkspaceController::class, 'members']);
        Route::get('{workspace}/invitations', [WorkspaceInvitationController::class, 'index']);
        Route::get('{workspace}/invitations/available-users', [WorkspaceInvitationController::class, 'availableUsers']);
        Route::post('{workspace}/invitations', [WorkspaceInvitationController::class, 'store']);
        Route::delete('{workspace}/invitations/{invitation:id}', [WorkspaceInvitationController::class, 'destroy']);
        Route::get('{workspace}/invite-link', [WorkspaceInviteLinkController::class, 'show']);
        Route::patch('{workspace}/invite-link', [WorkspaceInviteLinkController::class, 'update']);
        Route::post('{workspace}/invite-link/regenerate', [WorkspaceInviteLinkController::class, 'regenerate']);
        Route::get('{workspace}/content/recent', [ContentController::class, 'recent']);

        Route::prefix('{workspace}/navigation')->group(function () {
            Route::get('/', [WorkspaceNavigationItemController::class, 'index']);
            Route::post('/', [WorkspaceNavigationItemController::class, 'store']);
            Route::patch('{item}', [WorkspaceNavigationItemController::class, 'update']);
            Route::patch('{item}/move', [WorkspaceNavigationItemController::class, 'move']);
            Route::post('{item}/duplicate', [WorkspaceNavigationItemController::class, 'duplicate']);
            Route::delete('{item}', [WorkspaceNavigationItemController::class, 'destroy']);
        });
    });

    // Boards — resolve a single navigation item (leaf or group) by its
    // globally-unique id, for id-based deep links like `/boards/{id}` on the
    // frontend. No workspace slug needed: the item's own row says which
    // workspace it belongs to.
    Route::get('boards/{item}', [BoardController::class, 'show']);

    // Board invitations — email-invite a person to view a single board,
    // independent of full workspace membership. Powers the board header's
    // "Invite" dialog.
    Route::prefix('boards/{item}')->group(function () {
        Route::get('invitations', [BoardInvitationController::class, 'index']);
        Route::post('invitations', [BoardInvitationController::class, 'store']);
        Route::delete('invitations/{invitation:id}', [BoardInvitationController::class, 'destroy']);
        Route::delete('collaborators/{collaborator}', [BoardInvitationController::class, 'removeCollaborator']);
    });

    // Content, listed across every board/doc — powers Manage Workspace's
    // Content tab ("every board/doc I have access to", the same rows the
    // sidebar renders), as opposed to `boards/{item}/views` which lists a
    // single board's own tabs.
    Route::get('content', [ContentController::class, 'index']);
    Route::get('content/creators', [ContentController::class, 'creators']);

    // The default workspace-role permission matrix — shared config, not a
    // single workspace's own settings. Any authenticated member can view it
    // (Manage Workspace's Permissions tab); only staff can edit it.
    Route::get('workspace-permissions', [WorkspacePermissionController::class, 'index']);
    Route::middleware('role:super_admin,admin,staff')
        ->patch('workspace-permissions', [WorkspacePermissionController::class, 'update']);

    // Board content — the reusable "table board" engine: any number of
    // tables (groups) per board, items (pulses) with typed column values,
    // and saved views/tabs that double as saved filter configurations.
    Route::prefix('boards/{item}')->group(function () {
        Route::prefix('columns')->group(function () {
            Route::get('/', [BoardColumnController::class, 'index']);
            Route::post('/', [BoardColumnController::class, 'store']);

            // Column-header drag-and-drop reordering — declared before the
            // `{column}` wildcard routes below so this literal segment isn't
            // swallowed by route-model binding, mirroring how `items/reorder`
            // is declared ahead of `items/{board_item}`.
            Route::patch('reorder', [BoardColumnController::class, 'reorder']);

            Route::patch('{column}', [BoardColumnController::class, 'update']);
            Route::patch('{column}/move', [BoardColumnController::class, 'move']);
            Route::post('{column}/duplicate', [BoardColumnController::class, 'duplicate']);
            Route::delete('{column}', [BoardColumnController::class, 'destroy']);
        });

        Route::prefix('groups')->group(function () {
            Route::get('/', [BoardGroupController::class, 'index']);
            Route::post('/', [BoardGroupController::class, 'store']);
            Route::put('collapsed-state', [BoardGroupController::class, 'updateCollapsedState']);
            Route::patch('{group}', [BoardGroupController::class, 'update']);
            Route::patch('{group}/move', [BoardGroupController::class, 'move']);
            Route::post('{group}/duplicate', [BoardGroupController::class, 'duplicate']);
            Route::delete('{group}', [BoardGroupController::class, 'destroy']);
        });

        Route::prefix('items')->group(function () {
            Route::get('/', [BoardItemController::class, 'index']);
            Route::post('/', [BoardItemController::class, 'store']);

            // Selection action bar (bulk row actions) — declared before the
            // `{board_item}` wildcard routes below so these literal segments
            // aren't swallowed by it.
            Route::post('duplicate', [BoardItemController::class, 'bulkDuplicate']);
            Route::patch('move', [BoardItemController::class, 'bulkMove']);
            Route::patch('reorder', [BoardItemController::class, 'reorder']);
            Route::patch('archive', [BoardItemController::class, 'bulkArchive']);
            Route::delete('/', [BoardItemController::class, 'bulkDestroy']);

            Route::get('{board_item}', [BoardItemController::class, 'show']);
            Route::patch('{board_item}', [BoardItemController::class, 'update']);
            Route::patch('{board_item}/values', [BoardItemController::class, 'updateValues']);
            Route::patch('{board_item}/parent', [BoardItemController::class, 'updateParent']);
            Route::delete('{board_item}', [BoardItemController::class, 'destroy']);

            Route::prefix('{board_item}/comments')->group(function () {
                Route::get('/', [BoardItemCommentController::class, 'index']);
                Route::post('/', [BoardItemCommentController::class, 'store']);
                Route::patch('{comment}', [BoardItemCommentController::class, 'update']);
                Route::delete('{comment}', [BoardItemCommentController::class, 'destroy']);
                Route::post('{comment}/like', [BoardItemCommentController::class, 'toggleLike']);
                Route::post('{comment}/reactions', [BoardItemCommentController::class, 'toggleReaction']);
                Route::post('{comment}/seen', [BoardItemCommentController::class, 'toggleSeen']);
            });

            Route::prefix('{board_item}/checklist-items')->group(function () {
                Route::post('/', [BoardItemChecklistItemController::class, 'store']);
                Route::patch('{checklist_item}', [BoardItemChecklistItemController::class, 'update']);
                Route::delete('{checklist_item}', [BoardItemChecklistItemController::class, 'destroy']);
            });

            Route::prefix('{board_item}/attachments')->group(function () {
                Route::get('/', [BoardItemAttachmentController::class, 'index']);
                Route::post('/', [BoardItemAttachmentController::class, 'store']);
                Route::delete('{attachment}', [BoardItemAttachmentController::class, 'destroy']);
            });
        });

        Route::prefix('views')->group(function () {
            Route::get('/', [BoardViewController::class, 'index']);
            Route::post('/', [BoardViewController::class, 'store']);
            Route::put('order', [BoardViewController::class, 'updatePersonalOrder']);
            Route::patch('{board_view}', [BoardViewController::class, 'update']);
            Route::delete('{board_view}', [BoardViewController::class, 'destroy']);
            Route::post('{board_view}/duplicate', [BoardViewController::class, 'duplicate']);
            Route::get('{board_view}/chart-data', [BoardViewController::class, 'chartData']);
            Route::post('{board_view}/pin', [BoardViewController::class, 'togglePin']);
            Route::post('{board_view}/lock', [BoardViewController::class, 'toggleLock']);
            Route::post('{board_view}/images', [BoardViewImageController::class, 'store']);

            Route::prefix('{board_view}/files')->group(function () {
                Route::get('/', [BoardViewFileController::class, 'index']);
                Route::post('/', [BoardViewFileController::class, 'store']);
                Route::delete('{file}', [BoardViewFileController::class, 'destroy']);
            });
        });

        // Board-wide discussion feed ("Board updates") — the whole board's
        // own comment thread, independent of any single item or view/tab.
        Route::prefix('comments')->group(function () {
            Route::get('/', [BoardCommentController::class, 'index']);
            Route::post('/', [BoardCommentController::class, 'store']);
            Route::patch('{comment}', [BoardCommentController::class, 'update']);
            Route::delete('{comment}', [BoardCommentController::class, 'destroy']);
            Route::post('{comment}/like', [BoardCommentController::class, 'toggleLike']);
            Route::post('{comment}/reactions', [BoardCommentController::class, 'toggleReaction']);
            Route::post('{comment}/seen', [BoardCommentController::class, 'toggleSeen']);
        });
    });

    // Two-factor authentication management
    Route::prefix('auth/two-factor')->group(function () {
        Route::get('/', [TwoFactorController::class, 'status']);
        Route::post('/', [TwoFactorController::class, 'setup']);
        Route::post('confirm', [TwoFactorController::class, 'confirm']);
        Route::delete('/', [TwoFactorController::class, 'disable']);
        Route::get('recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
        Route::post('recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
    });

    // Admin — staff-level roles and above
    Route::middleware('role:super_admin,admin,staff')->prefix('admin')->group(function () {
        Route::get('users', [AdminUserController::class, 'index']);
        Route::get('users/{user}', [AdminUserController::class, 'show']);

        Route::middleware('role:super_admin,admin')->group(function () {
            Route::patch('users/{user}', [AdminUserController::class, 'update']);
            Route::patch('users/{user}/ban', [AdminUserController::class, 'ban']);
            Route::patch('users/{user}/unban', [AdminUserController::class, 'unban']);
            Route::post('users/invite', [AdminUserController::class, 'invite']);
        });

        // Role management — super_admin only
        Route::middleware('role:super_admin')->prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index']);
            Route::post('users/{user}/assign', [RoleController::class, 'assignRole']);
            Route::post('users/{user}/revoke', [RoleController::class, 'revokeRole']);
        });

        // Websocket test — diagnostic screen for verifying Reverb connectivity.
        Route::prefix('websocket-test')->group(function () {
            Route::get('status', [WebsocketTestController::class, 'status']);
            Route::post('ping', [WebsocketTestController::class, 'ping']);
        });

        // Account-wide settings (Administration: Profile, Account, Branding metadata,
        // Authentication policy, Advanced) — a single settings row, see AccountSetting::current().
        Route::prefix('account-settings')->group(function () {
            Route::get('/', [AccountSettingController::class, 'show']);

            Route::middleware('role:super_admin,admin')->group(function () {
                Route::patch('profile', [AccountSettingController::class, 'updateProfile']);
                Route::patch('preferences', [AccountSettingController::class, 'updatePreferences']);
                Route::post('logo', [BrandingController::class, 'storeLogo']);
                Route::delete('logo', [BrandingController::class, 'destroyLogo']);
                Route::post('email-header', [BrandingController::class, 'storeEmailHeader']);
                Route::delete('email-header', [BrandingController::class, 'destroyEmailHeader']);
                Route::patch('authentication', [AuthenticationSettingsController::class, 'update']);
                Route::post('scim-token/rotate', [AuthenticationSettingsController::class, 'rotateScimToken']);
                Route::patch('advanced', [AdvancedSettingsController::class, 'update']);
                Route::post('panic-mode', [AdvancedSettingsController::class, 'activatePanicMode']);
                Route::delete('panic-mode', [AdvancedSettingsController::class, 'deactivatePanicMode']);
            });
        });

        // Departments — account-wide organizational units, one per user, used for
        // headcount/seat-limit tracking on the Administration Users/Departments sections.
        Route::prefix('departments')->group(function () {
            Route::get('/', [DepartmentController::class, 'index']);

            Route::middleware('role:super_admin,admin')->group(function () {
                Route::post('/', [DepartmentController::class, 'store']);
                Route::patch('{department}', [DepartmentController::class, 'update']);
                Route::delete('{department}', [DepartmentController::class, 'destroy']);
            });
        });

        // Board ownership — reassigning a departed/renamed staff member's boards, and
        // assigning an owner to boards that have never had one. Admin+ only: high blast
        // radius (bulk reassign moves every board a person owns in one call).
        Route::prefix('board-ownership')->middleware('role:super_admin,admin')->group(function () {
            Route::get('orphans', [BoardOwnershipController::class, 'orphans']);
            Route::post('reassign', [BoardOwnershipController::class, 'bulkReassign']);
            Route::patch('orphans/{item}', [BoardOwnershipController::class, 'assignOrphan']);
        });

        // Audit log — read-only trail of every consequential Administration action.
        Route::get('audit-log', [AuditLogController::class, 'index']);

        // Account-wide session management (view/revoke any user's active session).
        Route::prefix('sessions')->middleware('role:super_admin,admin')->group(function () {
            Route::get('/', [AdminSessionController::class, 'index']);
            Route::delete('{session}', [AdminSessionController::class, 'destroy']);
            Route::delete('/', [AdminSessionController::class, 'destroyAll']);
        });
    });

    // Account Teams — company-wide staff groupings (Monday-style "Teams"),
    // independent of any single workspace. Staff-level roles and above, same
    // gate as the rest of the account-management surface.
    Route::middleware('role:super_admin,admin,staff')->group(function () {
        Route::get('account-team-members', [AccountTeamMemberController::class, 'all']);
        Route::get('account-team-candidates', [AccountTeamMemberController::class, 'candidates']);

        Route::prefix('account-teams')->group(function () {
            Route::get('/', [AccountTeamController::class, 'index']);
            Route::post('/', [AccountTeamController::class, 'store']);
            Route::get('{team}', [AccountTeamController::class, 'show']);
            Route::patch('{team}', [AccountTeamController::class, 'update']);
            Route::delete('{team}', [AccountTeamController::class, 'destroy']);
            Route::get('{team}/members', [AccountTeamMemberController::class, 'forTeam']);
            Route::post('{team}/members', [AccountTeamMemberController::class, 'store']);
            Route::put('{team}/members', [AccountTeamMemberController::class, 'sync']);
            Route::delete('{team}/members/{user}', [AccountTeamMemberController::class, 'destroy']);
        });
    });
});
