<?php

use App\Http\Controllers\Admin\Role\RoleController;
use App\Http\Controllers\Admin\User\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
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
    Route::get('boards/{item}', [BoardController::class, 'show']);

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
