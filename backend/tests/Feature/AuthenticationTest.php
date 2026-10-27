<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost:3000']);
    }

    public function test_returns_401_without_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('success', false);
        $this->postJson('/api/v1/logout')->assertUnauthorized();
    }

    public function test_login_sets_session_and_returns_safe_profile(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['email' => 'admin@example.test', 'password' => 'Test-only-Secret-123!']);
        $user->assignRole('Admin');
        $this->travelTo('2026-10-26 12:00:00');

        $response = $this->postJson('/api/v1/login', ['email' => ' ADMIN@example.test ', 'password' => 'Test-only-Secret-123!']);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles.0', 'Admin')->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame('2026-10-26 12:00:00', $user->fresh()->last_login_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.login']);
        $this->assertTrue(Hash::check('Test-only-Secret-123!', $user->fresh()->password));
    }

    public function test_returns_422_for_wrong_password_without_audit_or_login_time(): void
    {
        $user = User::factory()->create(['password' => 'Correct-test-password']);

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable()
            ->assertJsonPath('message', 'Validation failed')->assertJsonValidationErrors('email');

        $this->assertGuest('web');
        $this->assertNull($user->fresh()->last_login_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_returns_422_for_inactive_account(): void
    {
        $user = User::factory()->create(['status' => 'inactive', 'password' => 'Correct-test-password']);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Correct-test-password'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest('web');
    }

    public function test_returns_422_for_missing_credentials(): void
    {
        $this->postJson('/api/v1/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_returns_422_for_invalid_email(): void
    {
        $this->postJson('/api/v1/login', ['email' => 'invalid', 'password' => 'example'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_returns_429_after_five_login_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/login', ['email' => 'unknown@example.test', 'password' => 'invalid'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/login', ['email' => 'unknown@example.test', 'password' => 'invalid'])->assertTooManyRequests()->assertJsonPath('success', false);
    }

    public function test_logout_invalidates_authentication_and_records_audit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->postJson('/api/v1/logout')->assertOk()->assertJsonPath('data', null);

        $this->assertGuest('web');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.logout']);
    }

    public function test_me_returns_authenticated_user_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $user->email)->assertJsonMissingPath('data.password');
    }

    public function test_revoked_account_gets_403_on_existing_session(): void
    {
        $user = User::factory()->create(['status' => 'inactive']);
        $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_returns_419_without_csrf_for_stateful_mutation(): void
    {
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/login', ['email' => 'user@example.test', 'password' => 'example'])->assertStatus(419)->assertJsonPath('success', false);
    }

    public function test_csrf_endpoint_sets_cookie_and_exact_cors_origin(): void
    {
        $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN')->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_untrusted_origin_is_not_reflected_in_cors_permission(): void
    {
        $this->withHeaders(['Origin' => 'https://untrusted.example'])->get('/sanctum/csrf-cookie')->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }
}
