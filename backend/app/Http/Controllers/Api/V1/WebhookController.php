<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebhookRequest;
use App\Http\Resources\WebhookEndpointResource;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AutomationService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('integrations.manage'), 403);
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);

        return WebhookEndpointResource::collection(WebhookEndpoint::latest('id')->paginate((int) ($data['per_page'] ?? 20)))->additional(['success' => true, 'message' => 'Webhook endpoints']);
    }

    public function store(WebhookRequest $request): JsonResponse
    {
        $endpoint = DB::transaction(function () use ($request): WebhookEndpoint {
            $endpoint = new WebhookEndpoint($request->validated());
            $endpoint->secret = $request->validated('secret');
            $endpoint->created_by = $request->user()->id;
            $endpoint->save();
            app(AutomationService::class)->audit($request->user(), 'webhooks.create', $endpoint->id);

            return $endpoint;
        }, 5);

        return $this->response($endpoint, 201);
    }

    public function update(WebhookRequest $request, int $endpoint): JsonResponse
    {
        $model = DB::transaction(function () use ($request, $endpoint): WebhookEndpoint {
            $model = WebhookEndpoint::lockForUpdate()->findOrFail($endpoint);
            abort_if($model->version !== $request->integer('version'), 409, 'Webhook changed.');
            abort_unless($model->direction === $request->validated('direction'), 422, 'Webhook direction cannot change.');
            $model->fill($request->validated());
            if ($request->has('secret')) {
                $model->secret = $request->validated('secret');
            }$model->version++;
            $model->save();
            app(AutomationService::class)->audit($request->user(), 'webhooks.update', $model->id);

            return $model;
        }, 5);

        return $this->response($model);
    }

    public function destroy(Request $request, int $endpoint): JsonResponse
    {
        abort_unless($request->user()->can('integrations.manage'), 403);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $endpoint, $data): void {
            $model = WebhookEndpoint::lockForUpdate()->findOrFail($endpoint);
            abort_if($model->version !== (int) $data['version'], 409, 'Webhook changed.');
            $model->is_active = false;
            $model->save();
            $model->delete();
            app(AutomationService::class)->audit($request->user(), 'webhooks.archive', $endpoint);
        }, 5);

        return response()->json(['success' => true, 'message' => 'Webhook archived', 'data' => null]);
    }

    public function deliveries(Request $request, int $endpoint): JsonResponse
    {
        abort_unless($request->user()->can('integrations.manage'), 403);
        WebhookEndpoint::withTrashed()->findOrFail($endpoint);
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = WebhookDelivery::where('webhook_endpoint_id', $endpoint)->latest('id')->paginate(20, ['id', 'delivery_id', 'event_type', 'status', 'attempts', 'last_attempt_at', 'created_at']);

        return response()->json(['success' => true, 'message' => 'Webhook deliveries', 'data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]]);
    }

    public function receive(Request $request, int $endpoint, WebhookService $webhooks): JsonResponse
    {
        $duplicate = $webhooks->receive($request, $endpoint);

        return response()->json(['success' => true, 'message' => $duplicate ? 'Delivery already accepted' : 'Webhook accepted', 'data' => ['duplicate' => $duplicate]]);
    }

    private function response(WebhookEndpoint $endpoint, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Webhook saved', 'data' => new WebhookEndpointResource($endpoint)],$status);
    }
}
