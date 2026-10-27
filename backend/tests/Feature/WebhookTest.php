<?php

namespace Tests\Feature;

use App\Models\AutomationEvent;
use App\Models\AutomationStep;
use App\Models\AutomationWorkflow;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\ActionFailure;
use App\Services\AutomationService;
use App\Services\SafeWebhookUrl;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;

class WebhookTest extends CrmTestCase
{
    private function signed(WebhookEndpoint $endpoint, string $body, ?string $delivery = null, ?string $nonce = null, ?int $timestamp = null): array
    {
        $delivery ??= (string) Str::uuid();
        $nonce ??= (string) Str::uuid();
        $stamp = (string) ($timestamp ?? now()->timestamp);

        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SMARTBIZ_ID' => $delivery, 'HTTP_X_SMARTBIZ_NONCE' => $nonce, 'HTTP_X_SMARTBIZ_TIMESTAMP' => $stamp, 'HTTP_X_SMARTBIZ_SIGNATURE' => WebhookService::signature($endpoint->secret, $stamp, $nonce, $body, $delivery)];
    }

    private function receive(WebhookEndpoint $endpoint, string $body, array $headers): TestResponse
    {
        return $this->call('POST', '/api/v1/webhooks/'.$endpoint->id.'/receive', [], [], [], $headers, $body);
    }

    public function test_valid_signatures_are_accepted_once_and_payload_is_not_stored(): void
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'trigger_type' => 'webhook_received', 'is_active' => true]);
        $endpoint = WebhookEndpoint::factory()->create(['created_by' => $admin->id]);
        $body = json_encode(['event' => 'lead.created', 'secret_body' => 'not retained']);
        $headers = $this->signed($endpoint, $body);
        $this->receive($endpoint, $body, $headers)->assertOk()->assertJsonPath('data.duplicate', false);
        $this->receive($endpoint, $body, $headers)->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertDatabaseCount('webhook_receipts', 1);
        $this->assertDatabaseCount('automation_events', 1);
        $this->assertStringNotContainsString('not retained', json_encode(AutomationEvent::firstOrFail()->toArray()));
    }

    public function test_invalid_expired_and_altered_signatures_are_rejected(): void
    {
        $this->signIn('Admin');
        $endpoint = WebhookEndpoint::factory()->create();
        $body = '{"event":"lead.created"}';
        $this->receive($endpoint, $body, $this->signed($endpoint, $body, null, null, now()->subMinutes(6)->timestamp))->assertUnauthorized();
        $headers = $this->signed($endpoint, $body);
        $headers['HTTP_X_SMARTBIZ_SIGNATURE'] = 'v1=invalid';
        $this->receive($endpoint, $body, $headers)->assertUnauthorized();
        $this->receive($endpoint, '{"event":"tampered"}', $this->signed($endpoint, $body))->assertUnauthorized();
        $this->assertDatabaseCount('webhook_receipts', 0);
    }

    public function test_nonce_reuse_and_delivery_id_content_changes_are_rejected(): void
    {
        $this->signIn('Admin');
        $endpoint = WebhookEndpoint::factory()->create();
        $body = '{"event":"lead.created"}';
        $delivery = (string) Str::uuid();
        $nonce = (string) Str::uuid();
        $this->receive($endpoint, $body, $this->signed($endpoint, $body, $delivery, $nonce))->assertOk();
        $this->receive($endpoint, $body, $this->signed($endpoint, $body, null, $nonce))->assertConflict();
        $changed = '{"event":"changed"}';
        $this->receive($endpoint, $changed, $this->signed($endpoint, $changed, $delivery))->assertConflict();
    }

    public function test_secret_is_encrypted_and_never_returned_from_management_api(): void
    {
        $admin = $this->signIn('Admin');
        $secret = Str::random(48);
        $id = $this->postJson('/api/v1/webhooks', ['name' => 'Incoming CRM', 'direction' => 'inbound', 'secret' => $secret, 'is_active' => true, 'endpoint_url' => null])->assertCreated()->assertJsonMissingPath('data.secret')->json('data.id');
        $this->assertNotSame($secret, WebhookEndpoint::findOrFail($id)->getRawOriginal('secret'));
        $this->getJson('/api/v1/webhooks')->assertOk()->assertJsonMissingPath('data.0.secret');
        $this->putJson('/api/v1/webhooks/'.$id, ['name' => 'Updated', 'direction' => 'inbound', 'is_active' => false, 'version' => 1])->assertOk();
        $this->putJson('/api/v1/webhooks/'.$id, ['name' => 'Updated', 'direction' => 'inbound', 'is_active' => true, 'version' => 1])->assertConflict();
    }

    #[TestWith(['https://127.0.0.1/a'])] #[TestWith(['http://hooks.example.test/a'])] #[TestWith(['https://user:pass@hooks.example.test/a'])] #[TestWith(['https://hooks.example.test:8443/a'])] #[TestWith(['https://unapproved.example.test/a'])] #[TestWith(['https://hooks.example.test/a#fragment'])]
    public function test_unsafe_urls_are_rejected(string $url): void
    {
        config(['automation.webhook_hosts' => ['hooks.example.test']]);
        $guard = new SafeWebhookUrl;
        $this->expectException(ActionFailure::class);
        $guard->options($url);
    }

    #[TestWith(['127.0.0.1'])] #[TestWith(['169.254.169.254'])] #[TestWith(['100.100.100.200'])] #[TestWith(['10.0.0.1'])] #[TestWith(['::1'])] #[TestWith(['::ffff:127.0.0.1'])]
    public function test_dns_private_reserved_and_metadata_addresses_are_rejected(string $address): void
    {
        config(['automation.webhook_hosts' => ['hooks.example.test']]);
        $guard = \Mockery::mock(SafeWebhookUrl::class)->makePartial();
        $guard->shouldReceive('addresses')->with('hooks.example.test')->andReturn([$address]);
        $this->expectException(ActionFailure::class);
        $guard->options('https://hooks.example.test/receive');
    }

    public function test_outbound_delivery_is_signed_uses_stable_id_and_suppresses_completed_retry(): void
    {
        $this->signIn('Admin');
        Http::preventStrayRequests();
        Http::fake(['hooks.example.test/*' => Http::response([], 200)]);
        $endpoint = WebhookEndpoint::factory()->create(['direction' => 'outbound', 'endpoint_url' => 'https://hooks.example.test/receive']);
        $step = AutomationStep::factory()->create(['action_type' => 'webhook', 'configuration' => ['type' => 'webhook', 'webhook_endpoint_id' => $endpoint->id]]);
        $guard = \Mockery::mock(SafeWebhookUrl::class);
        $guard->shouldReceive('options')->twice()->andReturn(['allow_redirects' => false]);
        $service = new WebhookService($guard);
        $context = ['id' => 1, 'title' => 'Test', 'status' => 'new', 'trigger' => 'lead_created'];
        $service->send($step, $context);
        $service->send($step, $context);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('X-SmartBiz-Signature', WebhookService::signature($endpoint->secret, $request->header('X-SmartBiz-Timestamp')[0], $request->header('X-SmartBiz-Nonce')[0], $request->body(), $request->header('X-SmartBiz-Id')[0])));
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertSame('succeeded', WebhookDelivery::firstOrFail()->status);
    }

    public function test_delivery_id_header_is_covered_by_signature(): void
    {
        $this->signIn('Admin');
        $endpoint = WebhookEndpoint::factory()->create();
        $body = '{"event":"lead.created"}';
        $headers = $this->signed($endpoint, $body);
        $headers['HTTP_X_SMARTBIZ_ID'] = (string) Str::uuid();
        $this->receive($endpoint, $body, $headers)->assertUnauthorized();
        $this->assertDatabaseCount('webhook_receipts', 0);
    }

    public function test_forwarded_webhook_chains_are_bounded(): void
    {
        $this->signIn('Admin');
        $endpoint = WebhookEndpoint::factory()->create();
        $body = json_encode(['event' => 'lead.created', 'origin' => 'smartbiz', 'depth' => 4]);
        $this->receive($endpoint, $body, $this->signed($endpoint, $body))->assertUnprocessable();
        $this->assertDatabaseCount('webhook_receipts', 0);
    }

    public function test_forwarded_depth_is_retained_for_the_next_outbound_action(): void
    {
        Queue::fake();
        $admin = $this->signIn('Admin');
        AutomationWorkflow::factory()->create(['created_by' => $admin->id, 'trigger_type' => 'webhook_received', 'is_active' => true]);
        $endpoint = WebhookEndpoint::factory()->create();
        $body = json_encode(['event' => 'lead.created', 'origin' => 'smartbiz', 'depth' => 2]);
        $this->receive($endpoint, $body, $this->signed($endpoint, $body))->assertOk();
        $event = AutomationEvent::firstOrFail();
        $this->assertSame(2, $event->context['depth']);
        $this->assertSame(2, app(AutomationService::class)->context($event, null)['depth']);
    }
}
