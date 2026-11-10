<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PasswordChanged;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordSecurity
{
    public const REQUEST_MESSAGE = 'If an account exists with this email, a password reset link has been sent.';

    public function sendLink(string $email, ?User $actor = null): void
    {
        try {
            Password::broker()->sendResetLink(['email' => $email, 'status' => 'active']);
        } catch (Throwable) {
            // SMTP exceptions can contain entire messages, including reset URLs.
            Log::warning('Password recovery delivery failed. Check the password mail transport.');
        }
        DB::table('audit_logs')->insert(['user_id' => $actor?->id, 'action' => $actor ? 'password.reset_requested_for_user:'.User::where('email', $email)->value('id') : 'password.recovery_requested', 'created_at' => now()]);
    }

    public function reset(#[\SensitiveParameter] array $input): void
    {
        $status = DB::transaction(function () use ($input): string {
            // Serialize token consumption against concurrent reset requests.
            User::where('email', $input['email'])->lockForUpdate()->first();

            return Password::broker()->reset([...$input, 'status' => 'active'], function (User $user, string $password): void {
                $this->replace($user, $password, 'password.recovered');
                event(new PasswordReset($user));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'This reset link is invalid or expired. Request a new link.']);
        }
    }

    public function change(User $user, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($user, $current, $password): void {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            if (! Hash::check($current, $locked->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            $this->replace($locked, $password, 'password.changed');
            Password::broker()->deleteToken($locked);
        });
    }

    private function replace(User $user, #[\SensitiveParameter] string $password, string $action): void
    {
        if (Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from your current password.']);
        }
        $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->delete();
        DB::table('audit_logs')->insert(['user_id' => $user->id, 'action' => $action, 'created_at' => now()]);
        DB::afterCommit(function () use ($user): void {
            try {
                $user->notify(new PasswordChanged);
            } catch (Throwable) {
                Log::warning('Password change notification delivery failed.');
            }
        });
    }

    public function endSession(Request $request): void
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    public static function mailer(): string
    {
        $name = (string) config('passwords.mailer');
        $transport = config('mail.mailers.'.$name.'.transport');
        if (! in_array($transport, ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun', 'sendmail'], true) && ! (app()->environment('testing') && $transport === 'array')) {
            throw new \RuntimeException('Configure a secure password notification mailer.');
        }

        return $name;
    }
}
