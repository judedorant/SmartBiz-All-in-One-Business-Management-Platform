<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordRecoveryRequest;
use App\Models\User;
use App\Services\PasswordSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PasswordController extends Controller
{
    public function forgot(PasswordRecoveryRequest $request, PasswordSecurity $security): JsonResponse
    {
        $security->sendLink($request->validated('email'));

        return $this->success(PasswordSecurity::REQUEST_MESSAGE);
    }

    public function reset(PasswordRecoveryRequest $request, PasswordSecurity $security): JsonResponse
    {
        $security->reset($request->validated());
        $security->endSession($request);

        return $this->success('Your password has been reset. Sign in with your new password.');
    }

    public function change(PasswordRecoveryRequest $request, PasswordSecurity $security): JsonResponse
    {
        $security->change($request->user(), $request->validated('current_password'), $request->validated('password'));
        $security->endSession($request);

        return $this->success('Password changed. Sign in again on your devices.');
    }

    public function admin(Request $request, int $user, PasswordSecurity $security): JsonResponse
    {
        Gate::authorize('viewAny', User::class);
        $target = User::findOrFail($user);
        Gate::authorize('sendPasswordReset', $target);
        $request->validate(['tenant_id' => ['prohibited'], 'email' => ['prohibited'], 'password' => ['prohibited']]);
        $security->sendLink($target->email, $request->user());

        return $this->success(PasswordSecurity::REQUEST_MESSAGE);
    }

    private function success(string $message): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => null])->header('Cache-Control', 'no-store, private');
    }
}
