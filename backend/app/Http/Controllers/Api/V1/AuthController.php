<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::guard('web')->attempt([...$request->validated(), 'status' => 'active'])) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect or the account is inactive.']]);
        }
        $request->session()->regenerate();
        $user = $request->user('web');
        DB::transaction(function () use ($user): void {
            $user->forceFill(['last_login_at' => now()])->save();
            DB::table('audit_logs')->insert(['user_id' => $user->id, 'action' => 'auth.login', 'created_at' => now()]);
        });

        return response()->json(['success' => true, 'message' => 'Signed in successfully', 'data' => new UserResource($user->load('roles.permissions', 'permissions'))]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Authenticated user', 'data' => new UserResource($request->user()->load('roles.permissions', 'permissions'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'auth.logout', 'created_at' => now()]);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true, 'message' => 'Signed out successfully', 'data' => null]);
    }
}
