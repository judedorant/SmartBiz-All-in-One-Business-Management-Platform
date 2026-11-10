<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Notifications\PasswordRecovery;
use App\Policies\UserPolicy;
use App\Services\PasswordSecurity;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\TestWith;

class PasswordRecoveryTest extends CrmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['passwords.mailer' => 'array', 'mail.default' => 'array']);
        Notification::fake();
    }

    private function account(string $role = 'Staff'): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['password' => 'Old-Password-123!']);
        $user->assignRole($role);

        return $user;
    }

    private function payload(User $user, string $token, array $changes = []): array
    {
        return array_replace(['email' => $user->email, 'token' => $token, 'password' => 'New-Password-456!', 'password_confirmation' => 'New-Password-456!'], $changes);
    }

    #[TestWith(['Admin'])] #[TestWith(['Manager'])] #[TestWith(['Staff'])]
    public function test_all_roles_receive_secure_links(string $role): void
    {
        $user = $this->account($role);
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('message', PasswordSecurity::REQUEST_MESSAGE)->assertJsonMissingPath('token');
        Notification::assertSentTo($user, PasswordRecovery::class, function ($notification) use ($user): bool {
            $mail = $notification->toMail($user);
            parse_str(parse_url($mail->actionUrl, PHP_URL_QUERY), $query);
            $this->assertSame($user->email, $query['email']);
            $this->assertNotSame($query['token'], DB::table('password_reset_tokens')->value('token'));
            $this->assertStringStartsWith(config('app.frontend_url').'/reset-password?', $mail->actionUrl);

            return true;
        });
        $this->assertTrue(Hash::check('Old-Password-123!', $user->fresh()->password));
    }

    public function test_unknown_inactive_and_broker_throttled_emails_have_identical_responses(): void
    {
        $user = $this->account();
        $inactive = User::factory()->create(['status' => 'inactive']);
        foreach ([$user->email, $user->email, 'missing@example.test', $inactive->email] as $email) {
            $this->postJson('/api/v1/forgot-password', ['email' => $email])->assertOk()->assertJsonPath('message', PasswordSecurity::REQUEST_MESSAGE);
        }
        Notification::assertSentToTimes($user, PasswordRecovery::class, 1);
        Notification::assertNotSentTo($inactive, PasswordRecovery::class);
    }

    public function test_valid_reset_is_single_use_and_revokes_old_authentication(): void
    {
        $user = $this->account();
        $token = Password::createToken($user);
        DB::table('sessions')->insert(['id' => 'old', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => $user->getMorphClass(), 'tokenable_id' => $user->id, 'name' => 'old', 'token' => hash('sha256', 'old'), 'abilities' => '["*"]']);
        $this->postJson('/api/reset-password', $this->payload($user, $token))->assertOk();
        $this->assertTrue(Hash::check('New-Password-456!', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'password.recovered']);
        Notification::assertSentTo($user, PasswordChanged::class);
        $this->assertStringNotContainsString($token, json_encode(DB::table('audit_logs')->get()));
        $this->postJson('/api/reset-password', $this->payload($user, $token, ['password' => 'Other-Password-789!', 'password_confirmation' => 'Other-Password-789!']))->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertFalse(Auth::guard('web')->attempt(['email' => $user->email, 'password' => 'Old-Password-123!']));
        $this->assertTrue(Auth::guard('web')->attempt(['email' => $user->email, 'password' => 'New-Password-456!']));
    }

    #[TestWith(['invalid'])] #[TestWith(['expired'])] #[TestWith(['wrong-email'])]
    public function test_bad_links_do_not_change_passwords(string $kind): void
    {
        $user = $this->account();
        $before = $user->password;
        $token = Password::createToken($user);
        if ($kind === 'invalid') {
            $token = 'bad';
        }if ($kind === 'expired') {
            $this->travel(61)->minutes();
        }
        $input = $this->payload($user, $token);
        if ($kind === 'wrong-email') {
            $input['email'] = 'missing@example.test';
        }
        $this->postJson('/api/reset-password', $input)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertSame($before, $user->fresh()->password);
    }

    #[TestWith(['weak', 'weak'])] #[TestWith(['New-Password-456!', 'mismatch'])] #[TestWith(['Old-Password-123!', 'Old-Password-123!'])]
    public function test_strength_confirmation_and_reuse(string $password, string $confirmation): void
    {
        $user = $this->account();
        $token = Password::createToken($user);
        $this->postJson('/api/reset-password', $this->payload($user, $token, ['password' => $password, 'password_confirmation' => $confirmation]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Password::tokenExists($user, $token));
    }

    #[TestWith(['Admin'])] #[TestWith(['Manager'])] #[TestWith(['Staff'])]
    public function test_every_role_can_change_password_and_is_logged_out(string $role): void
    {
        $user = $this->account($role);
        $this->actingAs($user, 'web');
        $token = Password::createToken($user);
        $this->putJson('/api/profile/change-password', ['current_password' => 'Old-Password-123!', 'password' => 'New-Password-456!', 'password_confirmation' => 'New-Password-456!'])->assertOk();
        $this->assertGuest('web');
        $this->assertFalse(Password::tokenExists($user, $token));
        $this->assertTrue(Hash::check('New-Password-456!', $user->fresh()->password));
        Notification::assertSentTo($user, PasswordChanged::class);
    }

    public function test_wrong_current_password_and_unauthenticated_change_are_rejected(): void
    {
        $this->putJson('/api/profile/change-password', [])->assertUnauthorized();
        $user = $this->account();
        $this->actingAs($user, 'web');
        $this->putJson('/api/profile/change-password', ['current_password' => 'wrong', 'password' => 'New-Password-456!', 'password_confirmation' => 'New-Password-456!'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('Old-Password-123!', $user->fresh()->password));
    }

    #[TestWith(['Manager'])] #[TestWith(['Staff'])]
    public function test_non_admin_reset_is_forbidden_even_with_direct_permission(string $role): void
    {
        $actor = $this->account($role);
        $actor->givePermissionTo('users.manage');
        $this->actingAs($actor, 'web');
        $target = User::factory()->create();
        $this->postJson('/api/v1/users/'.$target->id.'/send-password-reset')->assertForbidden();
        Notification::assertNothingSent();
    }

    public function test_admin_sends_registered_email_and_audits_without_changing_password(): void
    {
        $actor = $this->account('Admin');
        $target = $this->account();
        $before = $target->password;
        $this->actingAs($actor, 'web');
        $this->postJson('/api/v1/users/'.$target->id.'/send-password-reset')->assertOk();
        Notification::assertSentTo($target, PasswordRecovery::class);
        $this->assertSame($before, $target->fresh()->password);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $actor->id, 'action' => 'password.reset_requested_for_user:'.$target->id]);
        $this->postJson('/api/v1/users/'.$target->id.'/send-password-reset', ['email' => 'attacker@example.test'])->assertUnprocessable();
    }

    public function test_workspace_connection_boundary_and_tenant_injection_are_denied(): void
    {
        $actor = $this->account('Admin');
        $target = $this->account();
        $this->actingAs($actor, 'web');
        $this->postJson('/api/users/'.$target->id.'/send-password-reset', ['tenant_id' => 999])->assertUnprocessable();
        config(['database.connections.foreign_workspace' => ['driver' => 'sqlite', 'database' => 'foreign-workspace']]);
        $target->setConnection('foreign_workspace');
        $this->assertFalse((new UserPolicy)->sendPasswordReset($actor, $target));
        Notification::assertNothingSent();
    }

    public function test_requests_are_rate_limited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'missing@example.test'])->assertOk();
        }
        $this->postJson('/api/forgot-password', ['email' => 'missing@example.test'])->assertTooManyRequests();
        $actor = $this->account('Admin');
        $target = $this->account();
        $this->actingAs($actor, 'web');
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/users/'.$target->id.'/send-password-reset')->assertOk();
        }
        $this->postJson('/api/users/'.$target->id.'/send-password-reset')->assertTooManyRequests();
    }

    public function test_mail_renders_and_refuses_log_transport(): void
    {
        Notification::swap(new ChannelManager($this->app));
        $user = $this->account();
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Reset SmartBiz password', $messages[0]->getOriginalMessage()->getHtmlBody());
        config(['passwords.mailer' => 'log']);
        $this->expectException(\RuntimeException::class);
        PasswordSecurity::mailer();
    }

    public function test_inactive_account_cannot_use_an_existing_reset_token(): void
    {
        $user = $this->account();
        $token = Password::createToken($user);
        $user->update(['status' => 'inactive']);
        $this->postJson('/api/reset-password', $this->payload($user, $token))->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertTrue(Hash::check('Old-Password-123!', $user->fresh()->password));
    }

    public function test_multibyte_passwords_cannot_exceed_the_bcrypt_byte_limit(): void
    {
        $user = $this->account();
        $password = 'Valid-123!'.str_repeat(chr(0xC3).chr(0xA9), 35);
        $this->postJson('/api/reset-password', $this->payload($user, Password::createToken($user), ['password' => $password, 'password_confirmation' => $password]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_admin_recovery_requires_authentication_and_rejects_password_injection(): void
    {
        $user = $this->account();
        $this->postJson('/api/users/'.$user->id.'/send-password-reset')->assertUnauthorized();
        $this->actingAs($this->account('Admin'), 'web');
        $this->postJson('/api/users/'.$user->id.'/send-password-reset', ['password' => 'Arbitrary-Password-123!'])->assertUnprocessable();
        Notification::assertNothingSent();
    }

    public function test_change_password_cannot_reuse_the_current_password(): void
    {
        $user = $this->account();
        $this->actingAs($user, 'web');
        $this->putJson('/api/profile/change-password', ['current_password' => 'Old-Password-123!', 'password' => 'Old-Password-123!', 'password_confirmation' => 'Old-Password-123!'])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_delivery_failure_returns_generic_response_and_logs_no_diagnostic(): void
    {
        $user = $this->account();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('secret-token-in-diagnostic'));
        Log::shouldReceive('warning')->once()->with('Password recovery delivery failed. Check the password mail transport.');
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('message', PasswordSecurity::REQUEST_MESSAGE);
    }
}
