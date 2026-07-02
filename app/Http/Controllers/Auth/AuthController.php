<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\CreateTeam;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class AuthController extends Controller
{
    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    /**
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            $user->assignRole('client');

            return $user;
        });

        $token = $this->guard()->login($user);

        return $this->respondWithToken($token, $user);
    }

    /**
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $existing_user = User::where('email', $credentials['email'])->first();

        if ($existing_user && ! $existing_user->is_active) {
            return response()->json([
                'message' => 'Your account has been disabled. Please contact support if you believe this is a mistake.',
                'code' => 'account_disabled',
            ], 403);
        }

        $token = $this->guard()->attempt($credentials);

        if (! is_string($token)) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
                'errors' => [
                    'email' => ['The provided credentials are incorrect.'],
                ],
            ], 422);
        }

        /** @var User $user */
        $user = $this->guard()->user();

        return $this->respondWithToken($token, $user);
    }

    /**
     * GET /api/auth/me
     */
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->guard()->user();
        $user->load('roles:id,name,display_name');

        return response()->json([
            'user' => $this->formatUser($user),
            'permissions' => $user->getAllPermissions(),
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(): JsonResponse
    {
        $this->guard()->logout();

        return response()->json([
            'message' => 'Successfully logged out.',
        ]);
    }

    /**
     * POST /api/auth/refresh
     */
    public function refresh(): JsonResponse
    {
        $token = $this->guard()->refresh();

        /** @var User $user */
        $user = $this->guard()->user();

        return $this->respondWithToken($token, $user);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'profile_photo_url' => $user->profile_photo_url,
            'email_verified_at' => $user->email_verified_at,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
            ])->values(),
        ];
    }

    protected function respondWithToken(string $token, User $user): JsonResponse
    {
        $user->load('roles:id,name,display_name');

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
            'user' => $this->formatUser($user),
        ]);
    }

    protected function guard(): JWTGuard
    {
        /** @var JWTGuard */
        return Auth::guard('api');
    }
}
