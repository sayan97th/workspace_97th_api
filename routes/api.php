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
use App\Http\Controllers\Admin\Impersonation\ImpersonationController;
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
use App\Http\Controllers\Board\BoardActivityLogController;
use App\Http\Controllers\Board\BoardAutomationController;
use App\Http\Controllers\Board\BoardColumnController;
use App\Http\Controllers\Board\BoardCommentController;
use App\Http\Controllers\Board\BoardExportController;
use App\Http\Controllers\Board\BoardFormController;
use App\Http\Controllers\Board\BoardGroupController;
use App\Http\Controllers\Board\BoardImportController;
use App\Http\Controllers\Board\BoardInvitationController;
use App\Http\Controllers\Board\BoardItemAttachmentController;
use App\Http\Controllers\Board\BoardItemCellFileController;
use App\Http\Controllers\Board\BoardItemChecklistItemController;
use App\Http\Controllers\Board\BoardItemCommentController;
use App\Http\Controllers\Board\BoardItemController;
use App\Http\Controllers\Board\BoardItemMoveController;
use App\Http\Controllers\Board\BoardItemNotificationMuteController;
use App\Http\Controllers\Board\BoardItemUpdatesExportController;
use App\Http\Controllers\Board\BoardNotificationMuteController;
use App\Http\Controllers\Board\BoardTagController;
use App\Http\Controllers\Board\BoardTrashController;
use App\Http\Controllers\Board\BoardViewController;
use App\Http\Controllers\Board\BoardViewFileController;
use App\Http\Controllers\Board\BoardViewImageController;
use App\Http\Controllers\Board\BoardViewShareLinkController;
use App\Http\Controllers\BrandingController as PublicBrandingController;
use App\Http\Controllers\BroadcastAuthController;
use App\Http\Controllers\Comment\SavedReplyController;
use App\Http\Controllers\Favorite\FavoriteController;
use App\Http\Controllers\Feed\FeedUpdateController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\Home\RecentBoardController;
use App\Http\Controllers\InlineUploadController;
use App\Http\Controllers\Integration\SlackEventController;
use App\Http\Controllers\Integration\SlackIntegrationController;
use App\Http\Controllers\Integration\SlackOAuthCallbackController;
use App\Http\Controllers\MyWork\MyWorkController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\People\MentionTeamController;
use App\Http\Controllers\People\PersonCardController;
use App\Http\Controllers\Profile\LocalePreferenceController;
use App\Http\Controllers\Profile\NotificationPreferenceController;
use App\Http\Controllers\Profile\PasswordController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\ProfilePhotoController;
use App\Http\Controllers\Profile\SidebarPreferenceController;
use App\Http\Controllers\Profile\UserSessionController;
use App\Http\Controllers\Profile\WorkingStatusController;
use App\Http\Controllers\PublicAccess\PublicFormController;
use App\Http\Controllers\PublicAccess\PublicSharedViewController;
use App\Http\Controllers\Search\GlobalSearchController;
use App\Http\Controllers\Workspace\BoardController;
use App\Http\Controllers\Workspace\ContentController;
use App\Http\Controllers\Workspace\WorkspaceAvatarController;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Controllers\Workspace\WorkspaceInvitationController;
use App\Http\Controllers\Workspace\WorkspaceInviteLinkController;
use App\Http\Controllers\Workspace\WorkspaceMemberController;
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

// ─── Slack OAuth callback ───────────────────────────────────────────────────
// Public, the browser arrives from Slack with no JWT. Who it acts for comes from the
// single use `state` value issued when the flow started, see `SlackService::consumeState()`.
Route::get('integrations/slack/callback', SlackOAuthCallbackController::class);

// Event Subscriptions request URL of the Slack app. Public as well, but only requests signed
// with SLACK_SIGNING_SECRET get through, see `VerifySlackSignature`.
Route::post('integrations/slack/events', SlackEventController::class)->middleware(['slack.signature', 'throttle:120,1']);

// ─── Authenticated routes ───────────────────────────────────────────────────
// Public, no account needed: a board form's respondents and the people a
// board view was shared with through its read only link. Every token is an
// unguessable random string, and both are throttled per IP.
Route::prefix('public')->group(function () {
    Route::get('forms/{token}', [PublicFormController::class, 'show'])->middleware('throttle:60,1');
    Route::post('forms/{token}/submissions', [PublicFormController::class, 'submit'])->middleware('throttle:10,1');
    Route::post('views/{token}', [PublicSharedViewController::class, 'show'])->middleware('throttle:30,1');
});

Route::middleware(['auth:api', 'active', 'session.active', 'panic.mode', 'ip.allowed', 'two_factor.enforced'])->group(function () {

    // Personal navigation: the sidebar's Favorites, My Work, and the Home
    // page's recently visited boards.
    Route::get('favorites', [FavoriteController::class, 'index']);
    Route::put('favorites/order', [FavoriteController::class, 'reorder']);
    Route::put('favorites/{item}', [FavoriteController::class, 'store']);
    Route::delete('favorites/{item}', [FavoriteController::class, 'destroy']);
    Route::get('my-work', [MyWorkController::class, 'index']);
    Route::get('home/recent-boards', [RecentBoardController::class, 'index']);

    // Broadcasting auth (JWT-based) — used by the frontend's Echo client to
    // subscribe to private channels, see routes/channels.php.
    Route::post('broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);

    // Account branding (logo, email header) — readable by any authenticated user, not just
    // staff, since the top bar shows it for everyone. Managed at `/admin/account-settings/*`.
    Route::get('branding', [PublicBrandingController::class, 'show']);

    // Top bar "Search for anything..." box, a typeahead so it is throttled more loosely than
    // most endpoints, the frontend already debounces and cancels stale requests.
    Route::get('search', GlobalSearchController::class)->middleware('throttle:120,1');

    // Notifications — real-time (Reverb) + REST-readable notification feed.
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('filters', [NotificationController::class, 'filters']);
        Route::get('unread-count', [NotificationController::class, 'unreadCount']);
        Route::get('latest', [NotificationController::class, 'latest'])->middleware('throttle:60,1');
        Route::get('summary', [NotificationController::class, 'summary']);
        Route::patch('read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('bulk', [NotificationController::class, 'bulk']);
        Route::patch('{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::patch('{notification}/unread', [NotificationController::class, 'markAsUnread']);
        Route::patch('{notification}/snooze', [NotificationController::class, 'snooze']);
        Route::delete('{notification}/snooze', [NotificationController::class, 'unsnooze']);
        Route::patch('{notification}/save', [NotificationController::class, 'save']);
        Route::delete('{notification}/save', [NotificationController::class, 'unsave']);
        Route::delete('{notification}', [NotificationController::class, 'dismiss']);
    });

    // Profile card shown when hovering a mention or avatar, limited to people
    // who share a workspace with the viewer.
    Route::get('people/{user}/card', [PersonCardController::class, 'show'])->withTrashed();

    // Account teams a board's comment composers can group `@mention`, limited
    // to each team's members inside the board's workspace.
    Route::get('people/boards/{board}/teams', [MentionTeamController::class, 'index']);

    // The current user's reusable update and reply templates.
    Route::apiResource('saved-replies', SavedReplyController::class)->except(['show']);

    // Update Feed — real-time (Reverb) + REST-readable stream of comment
    // "updates" (item- and board-level) the current user has visibility on.
    Route::prefix('feed')->group(function () {
        Route::get('updates', [FeedUpdateController::class, 'index']);
        Route::get('updates/export', [FeedUpdateController::class, 'export'])->middleware('throttle:10,1');
        Route::get('boards', [FeedUpdateController::class, 'boards']);
        Route::get('boards/{board}/people', [FeedUpdateController::class, 'people']);
        Route::get('unread-count', [FeedUpdateController::class, 'unreadCount']);
        Route::get('filters', [FeedUpdateController::class, 'filters']);
        Route::get('saved-views', [FeedUpdateController::class, 'savedViews']);
        Route::post('saved-views', [FeedUpdateController::class, 'storeSavedView']);
        Route::delete('saved-views/{saved_view}', [FeedUpdateController::class, 'destroySavedView']);
        Route::get('follows', [FeedUpdateController::class, 'follows']);
        Route::post('follows', [FeedUpdateController::class, 'follow']);
        Route::delete('follows/{type}/{id}', [FeedUpdateController::class, 'unfollow'])->whereIn('type', ['board', 'item'])->whereNumber('id');
        Route::post('updates/read-all', [FeedUpdateController::class, 'markAllSeen']);
        Route::post('updates/{id}/bookmark', [FeedUpdateController::class, 'toggleBookmark']);
        Route::post('updates/{id}/pin', [FeedUpdateController::class, 'togglePin']);
        Route::post('updates/{id}/like', [FeedUpdateController::class, 'toggleLike']);
        Route::post('updates/{id}/reply', [FeedUpdateController::class, 'reply']);
        Route::post('updates/{id}/seen', [FeedUpdateController::class, 'markSeen']);
        Route::delete('updates/{id}/seen', [FeedUpdateController::class, 'markUnseen']);
        Route::post('updates/{id}/schedule', [FeedUpdateController::class, 'schedule']);
    });

    // Slack integration, an administrator installs the app into the Slack workspace, then
    // every member links their own Slack account to receive notifications as direct messages.
    Route::prefix('integrations/slack')->group(function () {
        Route::get('/', [SlackIntegrationController::class, 'show']);
        Route::get('channels', [SlackIntegrationController::class, 'channels']);
        Route::post('link-url', [SlackIntegrationController::class, 'linkUrl']);
        Route::delete('link', [SlackIntegrationController::class, 'unlink']);
        Route::post('link/test', [SlackIntegrationController::class, 'sendTest'])->middleware('throttle:6,1');

        Route::middleware('role:super_admin,admin')->group(function () {
            Route::post('install-url', [SlackIntegrationController::class, 'installUrl']);
            Route::delete('/', [SlackIntegrationController::class, 'destroy']);
            Route::get('diagnostics', [SlackIntegrationController::class, 'diagnostics'])->middleware('throttle:10,1');
            Route::post('diagnostics/channel-test', [SlackIntegrationController::class, 'sendChannelTest'])->middleware('throttle:6,1');
            Route::get('diagnostics/recipients', [SlackIntegrationController::class, 'notificationRecipients']);
            Route::post('diagnostics/user-test', [SlackIntegrationController::class, 'sendUserTest'])->middleware('throttle:6,1');
        });
    });

    // Per-board notification muting — checked by `NotificationService::notify()`
    // ahead of the recipient's own per-type preferences.
    Route::get('boards/muted', [BoardNotificationMuteController::class, 'index']);
    Route::get('boards/muted-items', [BoardItemNotificationMuteController::class, 'index']);
    Route::post('boards/{item}/mute', [BoardNotificationMuteController::class, 'store']);
    Route::delete('boards/{item}/mute', [BoardNotificationMuteController::class, 'destroy']);

    // Generic inline-image upload for content pasted/dropped directly into a
    // rich text editor (comment/update composers) — not a comment attachment.
    Route::post('uploads/inline-images', [InlineUploadController::class, 'store']);

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
        Route::patch('sidebar', [SidebarPreferenceController::class, 'update']);

        Route::get('sessions', [UserSessionController::class, 'index']);
        Route::delete('sessions/{session}', [UserSessionController::class, 'destroy']);
    });

    // Workspaces — dynamic sidebar + nested navigation tree
    Route::prefix('workspaces')->group(function () {
        Route::get('/', [WorkspaceController::class, 'index']);
        Route::post('/', [WorkspaceController::class, 'store']);

        // Id-based lookup for the frontend's `/workspaces/{workspace_id}/...`
        // tab routes, which only have the numeric id from the URL — declared
        // ahead of the slug-bound `{workspace}` route below for clarity, though
        // the differing segment count means they can't actually collide.
        Route::get('by-id/{workspace:id}', [WorkspaceController::class, 'show']);

        Route::get('{workspace}', [WorkspaceController::class, 'show']);
        Route::patch('{workspace}', [WorkspaceController::class, 'update']);
        Route::post('{workspace}/avatar', [WorkspaceAvatarController::class, 'store']);
        Route::delete('{workspace}/avatar', [WorkspaceAvatarController::class, 'destroy']);
        Route::patch('{workspace}/priority', [WorkspaceController::class, 'togglePriority']);
        Route::patch('{workspace}/activate', [WorkspaceController::class, 'activate']);
        Route::delete('{workspace}', [WorkspaceController::class, 'destroy']);
        Route::post('{workspace}/leave', [WorkspaceController::class, 'leave']);
        Route::post('{workspace}/transfer-ownership', [WorkspaceController::class, 'transferOwnership']);
        Route::get('{workspace}/members', [WorkspaceController::class, 'members']);
        Route::patch('{workspace}/members/{member}', [WorkspaceMemberController::class, 'update']);
        Route::delete('{workspace}/members/{member}', [WorkspaceMemberController::class, 'destroy']);
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
            Route::put('collapsed-state', [WorkspaceNavigationItemController::class, 'updateCollapsedState']);

            // Sidebar drag-and-drop / "Move up" / "Move down" reordering —
            // declared before the `{item}` wildcard routes below so this
            // literal segment isn't swallowed by route-model binding,
            // mirroring how `items/reorder` is declared ahead of
            // `items/{board_item}`.
            Route::patch('reorder', [WorkspaceNavigationItemController::class, 'reorder']);

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

    // Board options menu ("...") — archive/unarchive the whole board, its
    // per-board archived/deleted-items panel, its activity log, and
    // exporting its primary (or a given) tab to Excel.
    Route::prefix('boards/{item}')->group(function () {
        Route::post('archive', [BoardController::class, 'archive']);
        Route::patch('permissions', [BoardController::class, 'updatePermission']);
        Route::post('unarchive', [BoardController::class, 'unarchive']);

        Route::get('activity-log', [BoardActivityLogController::class, 'index']);

        Route::get('trash', [BoardTrashController::class, 'index']);
        Route::patch('trash/{board_item}/restore', [BoardTrashController::class, 'restore']);
        Route::delete('trash/{board_item}', [BoardTrashController::class, 'forceDelete']);
        Route::patch('trash/groups/{group}/restore', [BoardTrashController::class, 'restoreGroup']);
        Route::delete('trash/groups/{group}', [BoardTrashController::class, 'forceDeleteGroup']);

        Route::get('export', [BoardExportController::class, 'export']);
    });

    // Board options menu's "Give feedback" — a free-form product note,
    // optionally tied to the board the user was on.
    Route::post('feedback', [FeedbackController::class, 'store']);

    // Ends the caller's own impersonation session. Deliberately outside the `admin`-role-gated
    // group below: this is called while holding the *target's* token, which for a client target
    // holds none of those roles, not the impersonating admin's.
    Route::post('impersonation/stop', [ImpersonationController::class, 'stop']);

    // Content, listed across every board/doc — powers Manage Workspace's
    // Content tab ("every board/doc I have access to", the same rows the
    // sidebar renders), as opposed to `boards/{item}/views` which lists a
    // single board's own tabs.
    Route::get('content', [ContentController::class, 'index']);
    Route::get('content/creators', [ContentController::class, 'creators']);

    // The default workspace-role permission matrix — shared config, not a
    // single workspace's own settings. Manage Workspace's Permissions tab
    // stays visible to everyone but renders disabled for anyone outside this
    // role floor, so both viewing and editing are restricted here too —
    // a member without the role can't reach the matrix by hitting the API
    // directly either.
    Route::middleware('role:super_admin,admin,staff')->group(function () {
        Route::get('workspace-permissions', [WorkspacePermissionController::class, 'index']);
        Route::patch('workspace-permissions', [WorkspacePermissionController::class, 'update']);
    });

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
            Route::patch('{column}/permissions', [BoardColumnController::class, 'updatePermissions']);
            Route::patch('{column}/move', [BoardColumnController::class, 'move']);
            Route::post('{column}/duplicate', [BoardColumnController::class, 'duplicate']);
            Route::delete('{column}', [BoardColumnController::class, 'destroy']);
        });

        // Rule-based (no AI) automations — see `BoardAutomation`'s own doc comment.
        Route::prefix('automations')->group(function () {
            Route::get('/', [BoardAutomationController::class, 'index']);
            Route::post('/', [BoardAutomationController::class, 'store']);

            // Manage tab data, declared before the `{automation}` wildcard routes so these literal segments win.
            Route::get('runs', [BoardAutomationController::class, 'runs']);
            Route::get('usage', [BoardAutomationController::class, 'usage']);

            Route::post('{automation}/duplicate', [BoardAutomationController::class, 'duplicate']);
            Route::patch('{automation}', [BoardAutomationController::class, 'update']);
            Route::delete('{automation}', [BoardAutomationController::class, 'destroy']);
        });

        // Board header's "More actions" > "Import items" wizard — parses an
        // uploaded .csv/.xlsx/.xls into rows ("analyze"), then writes them
        // once the "Map columns"/"Handle matches" steps resolve ("commit").
        Route::prefix('import')->group(function () {
            Route::post('analyze', [BoardImportController::class, 'analyze']);
            Route::post('commit', [BoardImportController::class, 'commit']);

            Route::get('{import_job}', [BoardImportController::class, 'show']);
            Route::post('{import_job}/cancel', [BoardImportController::class, 'cancel']);
        });

        Route::prefix('groups')->group(function () {
            Route::get('/', [BoardGroupController::class, 'index']);
            Route::post('/', [BoardGroupController::class, 'store']);
            Route::put('collapsed-state', [BoardGroupController::class, 'updateCollapsedState']);
            Route::patch('{group}', [BoardGroupController::class, 'update']);
            Route::patch('{group}/move', [BoardGroupController::class, 'move']);
            Route::patch('{group}/archive', [BoardGroupController::class, 'archive']);
            Route::post('{group}/duplicate', [BoardGroupController::class, 'duplicate']);
            Route::delete('{group}', [BoardGroupController::class, 'destroy']);
        });

        // The Tags column's board-wide option list — shared across every
        // `tags`-type column on this board, unlike Status/Dropdown's own
        // per-column `config.options`. See `BoardTag`'s own doc comment.
        Route::prefix('tags')->group(function () {
            Route::get('/', [BoardTagController::class, 'index']);
            Route::post('/', [BoardTagController::class, 'store']);
            Route::patch('{tag}', [BoardTagController::class, 'update']);
            Route::delete('{tag}', [BoardTagController::class, 'destroy']);
        });

        // Boards (and their tables) an item can be moved into from its drawer.
        Route::get('move-targets', [BoardItemMoveController::class, 'targets']);

        Route::prefix('items')->group(function () {
            Route::get('/', [BoardItemController::class, 'index']);
            Route::post('/', [BoardItemController::class, 'store']);

            // Selection action bar (bulk row actions) — declared before the
            // `{board_item}` wildcard routes below so these literal segments
            // aren't swallowed by it.
            Route::post('duplicate', [BoardItemController::class, 'bulkDuplicate']);
            Route::patch('move', [BoardItemController::class, 'bulkMove']);
            Route::patch('values', [BoardItemController::class, 'bulkSetValue']);
            Route::patch('reorder', [BoardItemController::class, 'reorder']);
            Route::patch('archive', [BoardItemController::class, 'bulkArchive']);
            Route::delete('/', [BoardItemController::class, 'bulkDestroy']);

            Route::get('{board_item}', [BoardItemController::class, 'show']);
            Route::patch('{board_item}', [BoardItemController::class, 'update']);
            Route::patch('{board_item}/values', [BoardItemController::class, 'updateValues']);
            Route::patch('{board_item}/parent', [BoardItemController::class, 'updateParent']);
            Route::patch('{board_item}/recurrence', [BoardItemController::class, 'setRecurrence']);
            Route::delete('{board_item}/recurrence', [BoardItemController::class, 'clearRecurrence']);
            Route::patch('{board_item}/board', [BoardItemMoveController::class, 'store']);
            Route::get('{board_item}/updates/export', [BoardItemUpdatesExportController::class, 'export']);
            Route::post('{board_item}/mute', [BoardItemNotificationMuteController::class, 'store']);
            Route::delete('{board_item}/mute', [BoardItemNotificationMuteController::class, 'destroy']);
            Route::delete('{board_item}', [BoardItemController::class, 'destroy']);

            Route::prefix('{board_item}/comments')->group(function () {
                Route::get('/', [BoardItemCommentController::class, 'index']);
                Route::post('/', [BoardItemCommentController::class, 'store']);
                Route::get('scheduled', [BoardItemCommentController::class, 'scheduled']);
                Route::patch('{comment}', [BoardItemCommentController::class, 'update']);
                Route::delete('{comment}', [BoardItemCommentController::class, 'destroy']);
                Route::post('{comment}/like', [BoardItemCommentController::class, 'toggleLike']);
                Route::post('{comment}/reactions', [BoardItemCommentController::class, 'toggleReaction']);
                Route::post('{comment}/seen', [BoardItemCommentController::class, 'toggleSeen']);
                Route::post('{comment}/pin', [BoardItemCommentController::class, 'togglePin']);
                Route::post('{comment}/resolve', [BoardItemCommentController::class, 'toggleResolve']);
                Route::post('{comment_id}/restore', [BoardItemCommentController::class, 'restore'])->whereNumber('comment_id');
                Route::post('{comment}/bookmark', [BoardItemCommentController::class, 'toggleBookmark']);
                Route::patch('{comment}/schedule', [BoardItemCommentController::class, 'updateSchedule']);
                Route::get('{comment}/revisions', [BoardItemCommentController::class, 'revisions']);
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

            // Files cell (a `files`-type column) — distinct from
            // `{board_item}/attachments` above, which attaches to the item as
            // a whole rather than one specific column's cell.
            Route::prefix('{board_item}/columns/{column}/files')->group(function () {
                Route::post('/', [BoardItemCellFileController::class, 'store']);
                Route::delete('{file_id}', [BoardItemCellFileController::class, 'destroy']);
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

            Route::get('{board_view}/form', [BoardFormController::class, 'show']);
            Route::patch('{board_view}/form', [BoardFormController::class, 'update']);
            Route::post('{board_view}/form/regenerate-link', [BoardFormController::class, 'regenerateLink']);

            Route::get('{board_view}/share-link', [BoardViewShareLinkController::class, 'show']);
            Route::post('{board_view}/share-link', [BoardViewShareLinkController::class, 'store']);
            Route::patch('{board_view}/share-link', [BoardViewShareLinkController::class, 'update']);
            Route::delete('{board_view}/share-link', [BoardViewShareLinkController::class, 'destroy']);
            Route::post('{board_view}/share-link/regenerate', [BoardViewShareLinkController::class, 'regenerate']);
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
            Route::get('scheduled', [BoardCommentController::class, 'scheduled']);
            Route::patch('{comment}', [BoardCommentController::class, 'update']);
            Route::delete('{comment}', [BoardCommentController::class, 'destroy']);
            Route::post('{comment}/like', [BoardCommentController::class, 'toggleLike']);
            Route::post('{comment}/reactions', [BoardCommentController::class, 'toggleReaction']);
            Route::post('{comment}/seen', [BoardCommentController::class, 'toggleSeen']);
            Route::post('{comment}/pin', [BoardCommentController::class, 'togglePin']);
            Route::post('{comment}/resolve', [BoardCommentController::class, 'toggleResolve']);
            Route::post('{comment_id}/restore', [BoardCommentController::class, 'restore'])->whereNumber('comment_id');
            Route::post('{comment}/bookmark', [BoardCommentController::class, 'toggleBookmark']);
            Route::patch('{comment}/schedule', [BoardCommentController::class, 'updateSchedule']);
            Route::get('{comment}/revisions', [BoardCommentController::class, 'revisions']);
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
        Route::get('users/{user}', [AdminUserController::class, 'show'])->withTrashed();

        Route::middleware('role:super_admin,admin')->group(function () {
            Route::patch('users/{user}', [AdminUserController::class, 'update']);
            Route::patch('users/{user}/ban', [AdminUserController::class, 'ban']);
            Route::patch('users/{user}/unban', [AdminUserController::class, 'unban']);
            Route::delete('users/{user}', [AdminUserController::class, 'destroy']);
            Route::patch('users/{user}/restore', [AdminUserController::class, 'restore'])->withTrashed();
            Route::post('users/invite', [AdminUserController::class, 'invite']);

            // Password management — set a password directly, or email the account a reset
            // link so the user chooses their own, mirroring `PasswordResetController::forgotPassword()`.
            Route::patch('users/{user}/password', [AdminUserController::class, 'setPassword']);
            Route::post('users/{user}/send-password-reset-link', [AdminUserController::class, 'sendPasswordResetLink']);

            // Sign in as another account to troubleshoot what they see. Further restricted
            // inside the controller: a plain admin may only impersonate client-tier accounts,
            // and nobody may impersonate a super_admin.
            Route::post('users/{user}/impersonate', [ImpersonationController::class, 'store']);
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
        // headcount/seat-limit tracking on the Administration Users/Departments sections. Each
        // department can have owners who manage its members without full admin access.
        Route::prefix('departments')->group(function () {
            Route::get('/', [DepartmentController::class, 'index']);

            Route::middleware('role:super_admin,admin')->group(function () {
                Route::post('/', [DepartmentController::class, 'store']);
                Route::patch('{department}', [DepartmentController::class, 'update']);
                Route::delete('{department}', [DepartmentController::class, 'destroy']);
                Route::post('{department}/owners', [DepartmentController::class, 'assignOwners']);
                Route::delete('{department}/owners/{user}', [DepartmentController::class, 'removeOwner']);
            });

            // Members: admins, or the department's owners (authorized inside the controller).
            Route::post('{department}/members', [DepartmentController::class, 'assignMembers']);
            Route::delete('{department}/members/{user}', [DepartmentController::class, 'removeMember']);
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

    // Account Teams, company-wide staff groupings (Monday-style "Teams"), independent of any
    // single workspace. Staff-level roles and above can read them, but only admins and the
    // account owner create, edit or delete a team and pick its owners. Adding and removing
    // members is also open to that team's own owners, checked in `AccountTeamMemberController`.
    Route::middleware('role:super_admin,admin,staff')->group(function () {
        Route::get('account-team-members', [AccountTeamMemberController::class, 'all']);
        Route::get('account-team-candidates', [AccountTeamMemberController::class, 'candidates']);

        Route::prefix('account-teams')->group(function () {
            Route::get('/', [AccountTeamController::class, 'index']);
            Route::get('{team}', [AccountTeamController::class, 'show']);
            Route::get('{team}/members', [AccountTeamMemberController::class, 'forTeam']);
            Route::post('{team}/members', [AccountTeamMemberController::class, 'store']);
            Route::delete('{team}/members/{user}', [AccountTeamMemberController::class, 'destroy']);

            Route::middleware('role:super_admin,admin')->group(function () {
                Route::post('/', [AccountTeamController::class, 'store']);
                Route::patch('{team}', [AccountTeamController::class, 'update']);
                Route::delete('{team}', [AccountTeamController::class, 'destroy']);
                Route::put('{team}/members', [AccountTeamMemberController::class, 'sync']);
                Route::put('{team}/owners/{user}', [AccountTeamMemberController::class, 'assignOwner']);
                Route::delete('{team}/owners/{user}', [AccountTeamMemberController::class, 'removeOwner']);
            });
        });
    });
});
