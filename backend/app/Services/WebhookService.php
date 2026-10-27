<?php

namespace App\Services;

use App\Events\AutomationTriggered;
use App\Models\AutomationStep;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebhookService
{
    public function __construct(private SafeWebhookUrl $urls) {}

    public static function signature(string $secret, string $timestamp, string $nonce, string $body, string $deliveryId): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$deliveryId.'.'.$body, $secret);
    }

    public function send(AutomationStep $step, array $context): void
    {
        $endpoint = WebhookEndpoint::where('direction', 'outbound')->where('is_active', true)->find($step->configuration['webhook_endpoint_id']);
        if (! $endpoint) {
            throw new ActionFailure('webhook_unavailable');
        }
        $options = $this->urls->options($endpoint->endpoint_url);
        $delivery = WebhookDelivery::where('step_id', $step->id)->first();
        if (! $delivery) {
            $delivery = new WebhookDelivery;
            $delivery->delivery_id = (string) Str::uuid();
            $delivery->step_id = $step->id;
            $delivery->webhook_endpoint_id = $endpoint->id;
            $delivery->event_type = $context['trigger'];
            $delivery->payload = ['id' => $delivery->delivery_id, 'event' => $context['trigger'], 'origin' => 'smartbiz', 'depth' => ($context['depth'] ?? 0) + 1, 'data' => $context];
            $delivery->save();
        }
        if ($delivery->status === 'succeeded') {
            return;
        }
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $delivery->attempts++;
        $delivery->last_attempt_at = now();
        $delivery->status = 'processing';
        $delivery->save();
        try {
            $response = Http::withOptions($options)->connectTimeout(3)->timeout(8)->withHeaders(['X-SmartBiz-Id' => $delivery->delivery_id, 'X-SmartBiz-Timestamp' => $stamp, 'X-SmartBiz-Nonce' => $nonce, 'X-SmartBiz-Signature' => self::signature($endpoint->secret, $stamp, $nonce, $body, $delivery->delivery_id)])->withBody($body, 'application/json')->post($endpoint->endpoint_url);
        } catch (\Throwable) {
            $delivery->status = 'uncertain';
            $delivery->save();
            throw new ActionFailure('webhook_outcome_unknown', false, true);
        }
        if ($response->successful()) {
            $delivery->status = 'succeeded';
            $delivery->save();

            return;
        }
        $delivery->status = 'failed';
        $delivery->save();
        throw new ActionFailure('webhook_http_'.$response->status(), $response->status() === 429 || $response->serverError());
    }

    public function receive(Request $request, int $id): bool
    {
        abort_if(strlen($request->getContent()) > 65536, 413, 'Webhook payload too large.');
        $endpoint = WebhookEndpoint::where('direction', 'inbound')->where('is_active', true)->findOrFail($id);
        $stamp = (string) $request->header('X-SmartBiz-Timestamp');
        $nonce = (string) $request->header('X-SmartBiz-Nonce');
        $delivery = (string) $request->header('X-SmartBiz-Id');
        $signature = (string) $request->header('X-SmartBiz-Signature');
        $body = $request->getContent();
        abort_unless(ctype_digit($stamp) && abs(now()->timestamp - (int) $stamp) <= 300 && Str::isUuid($nonce) && Str::isUuid($delivery) && hash_equals(self::signature($endpoint->secret, $stamp, $nonce, $body, $delivery), $signature), 401, 'Invalid webhook signature.');
        $payload = json_decode($body, true);
        abort_unless(is_array($payload) && isset($payload['event']) && is_string($payload['event']) && strlen($payload['event']) <= 100, 422, 'Invalid webhook body.');

        $depth = ($payload['origin'] ?? null) === 'smartbiz' ? ($payload['depth'] ?? null) : 0;
        abort_unless(is_int($depth) && $depth >= 0 && $depth <= 3, 422, 'Webhook hop limit exceeded.');

        return DB::transaction(function () use ($endpoint, $nonce, $delivery, $body, $depth): bool {
            $current = WebhookEndpoint::whereKey($endpoint->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->is_active && hash_equals($current->secret, $endpoint->secret), 401, 'Webhook endpoint changed.');
            $prior = WebhookReceipt::where('webhook_endpoint_id', $endpoint->id)->where('delivery_id', $delivery)->lockForUpdate()->first();
            if ($prior) {
                abort_unless(hash_equals($prior->body_hash, hash('sha256', $body)), 409, 'Delivery ID reused with different content.');

                return true;
            }
            abort_if(WebhookReceipt::where('webhook_endpoint_id', $endpoint->id)->where('nonce', $nonce)->lockForUpdate()->first() !== null, 409, 'Webhook replay rejected.');
            $receipt = new WebhookReceipt;
            $receipt->webhook_endpoint_id = $endpoint->id;
            $receipt->nonce = $nonce;
            $receipt->delivery_id = $delivery;
            $receipt->body_hash = hash('sha256', $body);
            $receipt->save();
            AutomationTriggered::dispatch('webhook_received', 'webhook', $receipt->id, 1, ['depth' => $depth]);

            return false;
        }, 5);
    }
}
