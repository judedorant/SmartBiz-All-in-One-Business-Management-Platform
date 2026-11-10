<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManageUserRequest;
use App\Http\Requests\UserListRequest;
use App\Http\Resources\ManagedUserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(UserListRequest $request): JsonResponse
    {
        $query = User::with(['roles.permissions', 'permissions']);
        if ($search = $request->validated('search')) {
            $query->where(fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        }
        if ($role = $request->validated('role')) {
            $query->role($role);
        }
        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        return ManagedUserResource::collection($query->orderBy('name')->orderBy('id')->paginate($request->integer('per_page', 20))->withQueryString())
            ->additional(['success' => true, 'message' => 'Users retrieved'])->response()->header('Cache-Control', 'no-store, private');
    }

    public function store(ManageUserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $this->lockAdministration($request);
            $input = $request->validated();
            $this->checkEmail($input['email']);
            $user = User::create(collect($input)->only(['name', 'email', 'password', 'status'])->all());
            $user->syncRoles([$input['role']]);
            $this->audit($request->user(), $user, 'created');

            return $user;
        }, 5);

        return $this->response($user, 'User created', 201);
    }

    public function update(ManageUserRequest $request, int $user): JsonResponse
    {
        $model = DB::transaction(function () use ($request, $user): User {
            $this->lockAdministration($request);
            $target = User::with('roles')->lockForUpdate()->findOrFail($user);
            $input = $request->validated();
            abort_unless(hash_equals($target->managementRevision(), $input['revision']), 409, 'This account changed. Reload the user list before saving again.');
            $this->checkEmail($input['email'], $target->id);
            $losesAdmin = $target->hasRole('Admin') && ($input['role'] !== 'Admin' || $input['status'] !== 'active');
            if ($target->id === $request->user()->id && ($input['role'] !== 'Admin' || $input['status'] !== 'active')) {
                throw ValidationException::withMessages(['role' => 'You cannot remove your own Admin access or deactivate your account.']);
            }
            if ($losesAdmin && $target->status === 'active' && User::role('Admin')->where('status', 'active')->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'At least one active Admin must remain.']);
            }
            $resetPassword = ! empty($input['password']);
            $accessChanged = $target->status !== $input['status'] || $target->getRoleNames()->sort()->values()->all() !== [$input['role']];
            $fields = collect($input)->only(['name', 'email', 'status'])->all();
            if ($resetPassword) {
                $fields['password'] = $input['password'];
                $fields['remember_token'] = null;
            }
            $target->forceFill($fields)->save();
            $target->syncRoles([$input['role']]);
            if ($accessChanged || $resetPassword) {
                DB::table('sessions')->where('user_id', $target->id)->delete();
            }
            $this->audit($request->user(), $target, $resetPassword ? 'password_reset' : 'updated');

            return $target;
        }, 5);

        if ($model->id === $request->user()->id && $request->filled('password')) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return $this->response($model, 'User updated');
    }

    private function lockAdministration(ManageUserRequest $request): void
    {
        Role::where('name', 'Admin')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
        $actor = User::findOrFail($request->user()->id);
        abort_unless($actor->status === 'active' && $actor->can('users.manage'), 403);
    }

    private function checkEmail(string $email, ?int $except = null): void
    {
        if (User::where('email', $email)->when($except, fn (Builder $query) => $query->where('id', '!=', $except))->exists()) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }
    }

    private function audit(User $actor, User $target, string $action): void
    {
        DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => 'users.'.$action.':'.$target->id, 'created_at' => now()]);
    }

    private function response(User $user, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => new ManagedUserResource($user->load('roles.permissions', 'permissions'))], $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
