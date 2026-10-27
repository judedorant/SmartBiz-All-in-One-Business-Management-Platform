<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\IntegrationAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleIntegrationService
{
    public function ready(): bool
    {
        return filled(config('automation.google.client_id')) && filled(config('automation.google.client_secret')) && filled(config('automation.google.redirect_uri'));
    }

    public function start(Request $request, string $provider): string
    {
        abort_unless($request->hasSession(), 422, 'Start OAuth from the first-party web application.');
        abort_unless($this->ready(), 422, 'Configure Google OAuth credentials first.');
        abort_unless(isset(AutomationCatalog::PROVIDERS[$provider]), 422, 'Unknown Google service.');
        $state = Str::random(64);
        $verifier = Str::random(96);
        DB::table('oauth_states')->insert(['state_hash' => hash('sha256', $state), 'user_id' => $request->user()->id, 'provider' => $provider, 'session_hash' => hash('sha256', $request->session()->getId()), 'verifier' => Crypt::encryptString($verifier), 'expires_at' => now()->addMinutes(10)]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => config('automation.google.client_id'), 'redirect_uri' => config('automation.google.redirect_uri'), 'response_type' => 'code', 'scope' => 'openid email '.AutomationCatalog::PROVIDERS[$provider], 'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256', 'access_type' => 'offline', 'prompt' => 'consent']);
    }

    public function complete(Request $request): IntegrationAccount
    {
        $state = (string) $request->query('state', '');
        abort_unless(strlen($state) === 64, 422, 'OAuth state is invalid.');
        $pending = DB::transaction(function () use ($request, $state): object {
            $row = DB::table('oauth_states')->where('state_hash', hash('sha256', $state))->lockForUpdate()->first();
            abort_unless($row && ! $row->used_at && now()->lt($row->expires_at) && $row->user_id === $request->user()->id && hash_equals($row->session_hash, hash('sha256', $request->session()->getId())), 422, 'OAuth state is invalid or expired.');
            DB::table('oauth_states')->where('id', $row->id)->update(['used_at' => now()]);

            return $row;
        }, 5);
        abort_if($request->has('error') || ! is_string($request->query('code')) || strlen($request->query('code')) > 4096, 422, 'Google consent was not completed.');
        $token = $this->token(['grant_type' => 'authorization_code', 'code' => $request->query('code'), 'redirect_uri' => config('automation.google.redirect_uri'), 'code_verifier' => Crypt::decryptString($pending->verifier)]);
        if (! in_array(AutomationCatalog::PROVIDERS[$pending->provider], explode(' ', $token['scope'] ?? ''), true)) {
            throw new ActionFailure('google_scope_missing');
        }
        try {
            $profile = Http::withToken($token['access_token'])->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(8)->get('https://openidconnect.googleapis.com/v1/userinfo');
        } catch (\Throwable) {
            throw new ActionFailure('google_identity_unavailable');
        }
        $identity = $profile->json();
        if (! $profile->successful() || ! is_array($identity) || ! is_string($identity['sub'] ?? null) || strlen($identity['sub']) > 191 || empty($identity['email_verified']) || ! filter_var($identity['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new ActionFailure('google_identity_invalid');
        }

        return DB::transaction(function () use ($pending, $identity, $token): IntegrationAccount {
            $account = IntegrationAccount::where('user_id', $pending->user_id)->where('provider', $pending->provider)->where('external_account_id', $identity['sub'])->lockForUpdate()->first() ?? new IntegrationAccount;
            $old = $account->exists ? ($account->encrypted_credentials ?? []) : [];
            $refresh = $token['refresh_token'] ?? $old['refresh_token'] ?? null;
            if (! $refresh) {
                throw new ActionFailure('google_offline_access_missing');
            }
            $account->user_id = $pending->user_id;
            $account->provider = $pending->provider;
            $account->external_account_id = $identity['sub'];
            $account->display_email = $identity['email'];
            $account->status = 'connected';
            $account->encrypted_credentials = ['access_token' => $token['access_token'], 'refresh_token' => $refresh, 'expires_at' => now()->addSeconds((int) $token['expires_in'])->timestamp, 'scope' => $token['scope']];
            $account->save();

            return $account;
        }, 5);
    }

    private function token(array $payload): array
    {
        try {
            $response = Http::asForm()->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(8)->post('https://oauth2.googleapis.com/token', [...$payload, 'client_id' => config('automation.google.client_id'), 'client_secret' => config('automation.google.client_secret')]);
        } catch (\Throwable) {
            throw new ActionFailure('google_token_unavailable', true);
        }
        if (! $response->successful()) {
            throw new ActionFailure('google_token_rejected', $response->status() === 429 || $response->serverError());
        }
        $data = $response->json();
        if (! is_array($data) || ! is_string($data['access_token'] ?? null) || ! is_numeric($data['expires_in'] ?? null) || (int) $data['expires_in'] < 1) {
            throw new ActionFailure('google_token_invalid');
        }

return $data;
    }

    private function access(IntegrationAccount $account, bool $force = false): string
    {
        return DB::transaction(function () use ($account, $force): string {
            $current = IntegrationAccount::lockForUpdate()->findOrFail($account->id);
            $credentials = $current->encrypted_credentials;
            if ($current->status !== 'connected' || ! is_array($credentials)) {
                throw new ActionFailure('integration_disconnected');
            }
            if (! $force && ($credentials['expires_at'] ?? 0) > now()->addMinute()->timestamp) {
                return $credentials['access_token'];
            }
            try {
                $new = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token']]);
            } catch (ActionFailure $error) {
                throw $error;
            }
            $credentials['access_token'] = $new['access_token'];
            $credentials['refresh_token'] = $new['refresh_token'] ?? $credentials['refresh_token'];
            $credentials['expires_at'] = now()->addSeconds((int) $new['expires_in'])->timestamp;
            $current->encrypted_credentials = $credentials;
            $current->save();

            return $credentials['access_token'];
        }, 1);
    }

    private function call(IntegrationAccount $account, string $provider, string $method, string $url, array $body, bool $idempotent = false): Response
    {
        if ($account->provider !== $provider) {
            throw new ActionFailure('google_account_service_mismatch');
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->access($account, $attempt === 1);
            try {
                $response = Http::withToken($token)->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(8)->send($method, $url, ['json' => $body]);
            } catch (\Throwable) {
                throw new ActionFailure('google_outcome_unknown', false, true);
            }
            if ($response->status() === 401 && $attempt === 0) {
                continue;
            }
            if ($response->successful() || ($method === 'DELETE' && in_array($response->status(), [404, 410], true)) || ($idempotent && $method === 'POST' && $response->status() === 409)) {
                return $response;
            }
            if ($response->status() === 429) {
                throw new ActionFailure('google_rate_limited', true);
            }
            throw new ActionFailure('google_http_'.$response->status(), $idempotent && $response->serverError(), ! $idempotent && $response->serverError());
        }
        throw new ActionFailure('google_reauthorization_required');
    }

    public function gmail(IntegrationAccount $account, string $to, string $subject, string $body): void
    {
        $raw = 'To: '.$to."\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body));
        $this->call($account, 'google_gmail', 'POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')]);
    }

    public function sheets(IntegrationAccount $account, string $spreadsheet, string $range, array $row): void
    {
        $this->call($account, 'google_sheets', 'POST', 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheet).'/values/'.rawurlencode($range).':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS', ['values' => [$row]]);
    }

    public function calendar(IntegrationAccount $account, string $calendar, Appointment $appointment): void
    {
        $id = 'sb'.hash('sha256', 'smartbiz:appointment:'.$appointment->id);
        $url = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendar).'/events';
        $body = ['id' => $id, 'summary' => $appointment->title, 'start' => ['dateTime' => $appointment->start_at->toISOString()], 'end' => ['dateTime' => $appointment->end_at->toISOString()], 'extendedProperties' => ['private' => ['smartbiz_appointment_id' => (string) $appointment->id]]];
        if ($appointment->trashed() || $appointment->status === 'cancelled') {
            $this->call($account, 'google_calendar', 'DELETE', $url.'/'.$id, [], true);

            return;
        }
        $response = $this->call($account, 'google_calendar', 'POST', $url, $body, true);
        if ($response->status() === 409) {
            unset($body['id']);
            $this->call($account, 'google_calendar', 'PUT', $url.'/'.$id, $body, true);
        }
    }

    public function disconnect(IntegrationAccount $account): bool
    {
        $credentials = $account->encrypted_credentials;
        $account->encrypted_credentials = null;
        $account->status = 'disconnected';
        $account->save();
        $revoked = false;
        if ($credentials) {
            try {
                $revoked = Http::asForm()->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(8)->post('https://oauth2.googleapis.com/revoke',['token' => $credentials['refresh_token'] ?? $credentials['access_token']])->successful();
            } catch (\Throwable) {
            }
        }

        return $revoked;
    }
}
