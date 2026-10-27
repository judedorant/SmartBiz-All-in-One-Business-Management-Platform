<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\IntegrationAccount;
use App\Services\ActionFailure;
use App\Services\AutomationCatalog;
use App\Services\GoogleIntegrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GoogleIntegrationTest extends CrmTestCase
{
    private function configure(): void
    {
        config(['automation.google.client_id' => 'test-client', 'automation.google.client_secret' => 'test-only-secret', 'automation.google.redirect_uri' => 'http://localhost:8000/integrations/google/callback']);
        Http::preventStrayRequests();
    }

    private function begin(string $provider = 'google_sheets'): array
    {
        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])->postJson('/api/v1/integrations/google/'.$provider.'/connect')->assertOk();
        $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
        parse_str(parse_url($response->json('data.url'), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_oauth_pkce_state_and_tokens_are_not_exposed_and_callback_cannot_replay(): void
    {
        $admin = $this->signIn('Admin');
        $this->configure();
        $query = $this->begin();
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertStringContainsString('spreadsheets', $query['scope']);
        $this->assertStringNotContainsString('gmail.send', $query['scope']);
        $this->assertNotSame($query['state'], DB::table('oauth_states')->first()->state_hash);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'private-access', 'refresh_token' => 'private-refresh', 'expires_in' => 3600, 'scope' => AutomationCatalog::PROVIDERS['google_sheets']]), 'openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'account-123', 'email' => 'owner@example.test', 'email_verified' => true])]);
        $callback = '/integrations/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']);
        $this->get($callback)->assertRedirect('http://localhost:3000/settings/integrations?oauth=connected');
        $account = IntegrationAccount::firstOrFail();
        $this->assertSame('private-access', $account->encrypted_credentials['access_token']);
        $this->assertStringNotContainsString('private-access', $account->getRawOriginal('encrypted_credentials'));
        $this->getJson('/api/v1/integrations')->assertOk()->assertJsonMissingPath('data.accounts.0.encrypted_credentials');
        $this->get($callback)->assertRedirect('http://localhost:3000/settings/integrations?oauth=failed');
        Http::assertSentCount(2);
    }

    public function test_expired_wrong_session_and_denied_consent_never_exchange_tokens(): void
    {
        $this->signIn('Admin');
        $this->configure();
        Http::fake();
        $query = $this->begin();
        DB::table('oauth_states')->update(['expires_at' => now()->subMinute()]);
        $this->get('/integrations/google/callback?state='.$query['state'].'&code=code')->assertRedirect('http://localhost:3000/settings/integrations?oauth=failed');
        $query = $this->begin();
        DB::table('oauth_states')->where('state_hash', hash('sha256', $query['state']))->update(['session_hash' => hash('sha256', 'different-session')]);
        $this->get('/integrations/google/callback?state='.$query['state'].'&code=code')->assertRedirect('http://localhost:3000/settings/integrations?oauth=failed');
        Http::assertNothingSent();
    }

    public function test_sheet_requests_use_raw_values_and_refresh_expired_tokens(): void
    {
        $admin = $this->signIn('Admin');
        $this->configure();
        $account = IntegrationAccount::factory()->create(['user_id' => $admin->id, 'encrypted_credentials' => ['access_token' => 'expired', 'refresh_token' => 'refresh', 'expires_at' => now()->subHour()->timestamp]]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'renewed', 'expires_in' => 3600]), 'sheets.googleapis.com/*' => Http::response(['updates' => ['updatedRows' => 1]])]);
        app(GoogleIntegrationService::class)->sheets($account, 'spreadsheet123', 'Sheet1!A:G', ['=HYPERLINK("bad")']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'valueInputOption=RAW') && $request->hasHeader('Authorization', 'Bearer renewed') && $request['values'][0][0] === '=HYPERLINK("bad")');
        $this->assertSame('renewed', $account->fresh()->encrypted_credentials['access_token']);
    }

    public function test_gmail_builds_safe_mime_and_calendar_uses_deterministic_event_ids(): void
    {
        $admin = $this->signIn('Admin');
        $this->configure();
        $gmail = IntegrationAccount::factory()->create(['user_id' => $admin->id, 'provider' => 'google_gmail']);
        $calendar = IntegrationAccount::factory()->create(['user_id' => $admin->id, 'provider' => 'google_calendar']);
        $appointment = Appointment::factory()->create();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => 'message-id']), 'www.googleapis.com/calendar/*' => Http::sequence()->push([], 409)->push([], 200)]);
        $google = app(GoogleIntegrationService::class);
        $google->gmail($gmail, 'recipient@example.test', 'Safe subject', 'Plain body');
        $google->calendar($calendar, 'primary', $appointment);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'messages/send') && str_contains(base64_decode(strtr($request['raw'], '-_', '+/')), 'Content-Type: text/plain'));
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_contains($request->url(), '/events/sb'.hash('sha256', 'smartbiz:appointment:'.$appointment->id)));
        Http::assertSentCount(3);
    }

    public function test_disconnect_removes_credentials_even_when_remote_revocation_fails_and_other_users_accounts_are_hidden(): void
    {
        $admin = $this->signIn('Admin');
        $this->configure();
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 500)]);
        $own = IntegrationAccount::factory()->create(['user_id' => $admin->id]);
        $other = IntegrationAccount::factory()->create();
        $this->getJson('/api/v1/integrations')->assertJsonCount(1, 'data.accounts');
        $this->deleteJson('/api/v1/integrations/'.$other->id)->assertNotFound();
        $this->deleteJson('/api/v1/integrations/'.$own->id)->assertOk()->assertJsonPath('data.revocation_confirmed', false);
        $this->assertNull($own->fresh()->encrypted_credentials);
        $this->assertSame('disconnected', $own->fresh()->status);
    }

    public function test_missing_oauth_scope_or_offline_grant_never_creates_account(): void
    {
        $this->signIn('Admin');
        $this->configure();
        $query = $this->begin();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'private', 'refresh_token' => 'refresh', 'expires_in' => 3600, 'scope' => 'openid email'])]);
        $this->get('/integrations/google/callback?state='.$query['state'].'&code=code')->assertRedirect('http://localhost:3000/settings/integrations?oauth=failed');
        $this->assertDatabaseCount('integration_accounts', 0);
        Http::assertSentCount(1);
    }

    public function test_provider_server_error_on_non_idempotent_append_is_uncertain(): void
    {
        $admin = $this->signIn('Admin');
        $this->configure();
        $account = IntegrationAccount::factory()->create(['user_id' => $admin->id]);
        Http::fake(['sheets.googleapis.com/*' => Http::response(['sensitive' => 'not exposed'], 503)]);
        try {
            app(GoogleIntegrationService::class)->sheets($account, 'spreadsheet123', 'Sheet1!A:G', ['value']);
            $this->fail('Expected uncertain provider outcome.');
        } catch (ActionFailure $error) {
            $this->assertTrue($error->uncertain);
            $this->assertFalse($error->retryable);
            $this->assertSame('google_http_503', $error->getMessage());
        }Http::assertSentCount(1);
    }

    public function test_calendar_cancellation_and_archiving_delete_the_stable_remote_event(): void
    {
        $admin = $this->signIn('Admin');
        $this->configure();
        $account = IntegrationAccount::factory()->create(['user_id' => $admin->id, 'provider' => 'google_calendar']);
        $appointment = Appointment::factory()->create(['status' => 'cancelled']);
        Http::fake(['www.googleapis.com/calendar/*' => Http::response([], 404)]);
        app(GoogleIntegrationService::class)->calendar($account, 'primary', $appointment);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(),'/events/sb'.hash('sha256','smartbiz:appointment:'.$appointment->id)));
        Http::assertSentCount(1);
    }
}
