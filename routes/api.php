<?php

use App\Http\Controllers\Admin\Role\RoleController;
use App\Http\Controllers\Admin\User\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Board\BoardColumnController;
use App\Http\Controllers\Board\BoardGroupController;
use App\Http\Controllers\Board\BoardItemCommentController;
use App\Http\Controllers\Board\BoardItemController;
use App\Http\Controllers\Board\BoardViewController;
use App\Http\Controllers\Board\BoardViewFileController;
use App\Http\Controllers\Board\BoardViewImageController;
use App\Http\Controllers\Profile\PasswordController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\ProfilePhotoController;
use App\Http\Controllers\Workspace\BoardController;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Controllers\Workspace\WorkspaceNavigationItemController;
use Illuminate\Support\Facades\Route;

// ─── Auth routes ────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('two-factor-challenge', [AuthController::class, 'twoFactorChallenge']);
    Route::post('forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('reset-password', [PasswordResetController::class, 'resetPassword']);

    Route::prefix('google')->group(function () {
        Route::get('redirect', [GoogleAuthController::class, 'redirect']);
        Route::get('callback', [GoogleAuthController::class, 'callback']);
    });

    Route::middleware('auth:api')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
    });
});

// ─── Authenticated routes ───────────────────────────────────────────────────
Route::middleware(['auth:api', 'active'])->group(function () {

    // Profile — available to any authenticated user
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::patch('/', [ProfileController::class, 'partialUpdate']);
        Route::put('password', [PasswordController::class, 'update']);
        Route::post('photo', [ProfilePhotoController::class, 'store']);
        Route::delete('photo', [ProfilePhotoController::class, 'destroy']);
    });

    // Workspaces — dynamic sidebar + nested navigation tree
    Route::prefix('workspaces')->group(function () {
        Route::get('/', [WorkspaceController::class, 'index']);
        Route::post('/', [WorkspaceController::class, 'store']);
        Route::get('{workspace}', [WorkspaceController::class, 'show']);
        Route::patch('{workspace}', [WorkspaceController::class, 'update']);
        Route::delete('{workspace}', [WorkspaceController::class, 'destroy']);
        Route::post('{workspace}/leave', [WorkspaceController::class, 'leave']);

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
    //
    // `boards/client-hub` must be registered before the `{item}` wildcard —
    // Client Hub renders at the static `/client-hub` frontend route and never
    // gets its navigation item id from the URL, so it resolves its board id
    // through this literal route instead.
    Route::get('boards/client-hub', [BoardController::class, 'showClientHub']);
    Route::get('boards/{item}', [BoardController::class, 'show']);

    // Board content — the reusable "table board" engine: any number of
    // tables (groups) per board, items (pulses) with typed column values,
    // and saved views/tabs that double as saved filter configurations.
    Route::prefix('boards/{item}')->group(function () {
        Route::prefix('columns')->group(function () {
            Route::get('/', [BoardColumnController::class, 'index']);
            Route::post('/', [BoardColumnController::class, 'store']);
            Route::patch('{column}', [BoardColumnController::class, 'update']);
            Route::patch('{column}/move', [BoardColumnController::class, 'move']);
            Route::delete('{column}', [BoardColumnController::class, 'destroy']);
        });

        Route::prefix('groups')->group(function () {
            Route::get('/', [BoardGroupController::class, 'index']);
            Route::post('/', [BoardGroupController::class, 'store']);
            Route::patch('{group}', [BoardGroupController::class, 'update']);
            Route::patch('{group}/move', [BoardGroupController::class, 'move']);
            Route::delete('{group}', [BoardGroupController::class, 'destroy']);
        });

        Route::prefix('items')->group(function () {
            Route::get('/', [BoardItemController::class, 'index']);
            Route::post('/', [BoardItemController::class, 'store']);
            Route::get('{board_item}', [BoardItemController::class, 'show']);
            Route::patch('{board_item}', [BoardItemController::class, 'update']);
            Route::patch('{board_item}/values', [BoardItemController::class, 'updateValues']);
            Route::post('{board_item}/cover', [BoardItemController::class, 'updateCover']);
            Route::delete('{board_item}/cover', [BoardItemController::class, 'removeCover']);
            Route::delete('{board_item}', [BoardItemController::class, 'destroy']);

            Route::prefix('{board_item}/comments')->group(function () {
                Route::get('/', [BoardItemCommentController::class, 'index']);
                Route::post('/', [BoardItemCommentController::class, 'store']);
                Route::delete('{comment}', [BoardItemCommentController::class, 'destroy']);
                Route::post('{comment}/like', [BoardItemCommentController::class, 'toggleLike']);
                Route::post('{comment}/reactions', [BoardItemCommentController::class, 'toggleReaction']);
                Route::post('{comment}/seen', [BoardItemCommentController::class, 'toggleSeen']);
            });
        });

        Route::prefix('views')->group(function () {
            Route::get('/', [BoardViewController::class, 'index']);
            Route::post('/', [BoardViewController::class, 'store']);
            Route::put('order', [BoardViewController::class, 'updatePersonalOrder']);
            Route::patch('{board_view}', [BoardViewController::class, 'update']);
            Route::delete('{board_view}', [BoardViewController::class, 'destroy']);
            Route::post('{board_view}/duplicate', [BoardViewController::class, 'duplicate']);
            Route::post('{board_view}/pin', [BoardViewController::class, 'togglePin']);
            Route::post('{board_view}/lock', [BoardViewController::class, 'toggleLock']);
            Route::post('{board_view}/images', [BoardViewImageController::class, 'store']);

            Route::prefix('{board_view}/files')->group(function () {
                Route::get('/', [BoardViewFileController::class, 'index']);
                Route::post('/', [BoardViewFileController::class, 'store']);
                Route::delete('{file}', [BoardViewFileController::class, 'destroy']);
            });
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
        });

        // Role management — super_admin only
        Route::middleware('role:super_admin')->prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index']);
            Route::post('users/{user}/assign', [RoleController::class, 'assignRole']);
            Route::post('users/{user}/revoke', [RoleController::class, 'revokeRole']);
        });
    });
});
