<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\IntegrationAccountResource;
use App\Models\IntegrationAccount;
use App\Services\AutomationCatalog;
use App\Services\AutomationService;
use App\Services\GoogleIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function index(Request $request, GoogleIntegrationService $google): JsonResponse
    {
        abort_unless($request->user()->can('integrations.manage'), 403);

        return response()->json(['success' => true, 'message' => 'Integration status', 'data' => ['google_configured' => $google->ready(), 'mail_mode' => in_array(config('mail.default'), ['log', 'array'], true) ? 'local' : 'provider', 'providers' => array_keys(AutomationCatalog::PROVIDERS), 'accounts' => IntegrationAccountResource::collection(IntegrationAccount::where('user_id', $request->user()->id)->orderBy('id')->get())]]);
    }

    public function connect(Request $request, string $provider, GoogleIntegrationService $google): JsonResponse
    {
        abort_unless($request->user()->can('integrations.manage'), 403);

        return response()->json(['success' => true, 'message' => 'Google authorization URL', 'data' => ['url' => $google->start($request, $provider)]]);
    }

    public function callback(Request $request, GoogleIntegrationService $google): RedirectResponse
    {
        $success = false;
        if ($request->user() && $request->user()->status === 'active' && $request->user()->can('integrations.manage')) {
            try {
                $account = $google->complete($request);
                app(AutomationService::class)->audit($request->user(), 'integrations.connect', $account->id);
                $success = true;
            } catch (\Throwable) {
            }
        }

        return redirect()->away(rtrim(config('app.frontend_url'), '/').'/settings/integrations?oauth='.($success ? 'connected' : 'failed'), 303);
    }

    public function disconnect(Request $request, int $integration, GoogleIntegrationService $google): JsonResponse
    {
        abort_unless($request->user()->can('integrations.manage'), 403);
        $account = IntegrationAccount::where('user_id', $request->user()->id)->findOrFail($integration);
        $confirmed = $google->disconnect($account);
        app(AutomationService::class)->audit($request->user(), 'integrations.disconnect', $account->id);

        return response()->json(['success' => true, 'message' => $confirmed ? 'Google account disconnected' : 'Disconnected locally; review the grant in your Google account to confirm revocation.', 'data' => ['revocation_confirmed' => $confirmed]]);
    }
}
