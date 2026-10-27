<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutomationListRequest;
use App\Http\Requests\AutomationRequest;
use App\Http\Requests\AutomationStateRequest;
use App\Http\Resources\AutomationLogResource;
use App\Http\Resources\AutomationWorkflowResource;
use App\Models\AutomationLog;
use App\Models\AutomationWorkflow;
use App\Services\AutomationCatalog;
use App\Services\AutomationService;
use App\Services\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AutomationController extends Controller
{
    public function catalog(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AutomationWorkflow::class);

        return response()->json(['success' => true, 'message' => 'Automation options', 'data' => ['triggers' => AutomationCatalog::TRIGGERS, 'actions' => AutomationCatalog::ACTIONS, 'placeholders' => TemplateRenderer::TOKENS]]);
    }

    public function index(AutomationListRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AutomationWorkflow::class);
        $query = AutomationWorkflow::query();
        if ($term = $request->validated('search')) {
            $query->where('name', 'like', '%'.$term.'%');
        }if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

return AutomationWorkflowResource::collection($query->orderBy($request->validated('sort') ?? 'created_at', $request->validated('direction') ?? 'desc')->orderBy('id')->paginate($request->integer('per_page', 20)))->additional(['success' => true, 'message' => 'Workflows retrieved']);
    }

    public function store(AutomationRequest $request): JsonResponse
    {
        Gate::authorize('create', AutomationWorkflow::class);
        $workflow = DB::transaction(function () use ($request): AutomationWorkflow {
            $workflow = new AutomationWorkflow($request->validated());
            $workflow->created_by = $request->user()->id;
            $workflow->save();
            app(AutomationService::class)->audit($request->user(), 'automations.create', $workflow->id);

            return $workflow;
        }, 5);

        return $this->response($workflow, 'Workflow created', 201);
    }

    public function update(AutomationRequest $request, int $automation): JsonResponse
    {
        $workflow = DB::transaction(function () use ($request, $automation): AutomationWorkflow {
            $workflow = AutomationWorkflow::lockForUpdate()->findOrFail($automation);
            Gate::authorize('update', $workflow);
            abort_if($workflow->version !== $request->integer('version'), 409, 'Workflow changed. Reload before saving.');
            abort_unless($workflow->created_by === $request->user()->id, 403, 'Only the owner can change workflow integration settings.');
            $workflow->fill($request->validated());
            $workflow->version++;
            $workflow->save();
            app(AutomationService::class)->audit($request->user(), 'automations.update', $workflow->id);

            return $workflow;
        }, 5);

        return $this->response($workflow, 'Workflow updated');
    }

    public function active(AutomationStateRequest $request, int $automation): JsonResponse
    {
        $workflow = DB::transaction(function () use ($request, $automation): AutomationWorkflow {
            $workflow = AutomationWorkflow::lockForUpdate()->findOrFail($automation);
            Gate::authorize('update', $workflow);
            abort_if($workflow->version !== $request->integer('version'), 409, 'Workflow changed. Reload before saving.');
            $workflow->is_active = $request->boolean('is_active');
            $workflow->version++;
            $workflow->save();
            app(AutomationService::class)->audit($request->user(), 'automations.toggle', $automation);

            return $workflow;
        }, 5);

        return $this->response($workflow, 'Workflow state updated');
    }

    public function destroy(Request $request, int $automation): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $automation, $data): void {
            $workflow = AutomationWorkflow::lockForUpdate()->findOrFail($automation);
            Gate::authorize('delete', $workflow);
            abort_if($workflow->version !== (int) $data['version'], 409, 'Workflow changed.');
            $workflow->is_active = false;
            $workflow->save();
            $workflow->delete();
            app(AutomationService::class)->audit($request->user(), 'automations.archive', $automation);
        }, 5);

        return response()->json(['success' => true, 'message' => 'Workflow archived', 'data' => null]);
    }

    public function logs(AutomationListRequest $request, int $automation): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AutomationWorkflow::class);
        AutomationWorkflow::withTrashed()->findOrFail($automation);
        $query = AutomationLog::where('workflow_id', $automation)->with(['steps' => fn ($q) => $q->orderBy('action_index')]);
        if ($status = $request->validated('execution_status')) {
            $query->where('execution_status', $status);
        }

return AutomationLogResource::collection($query->latest('id')->paginate($request->integer('per_page', 20)))->additional(['success' => true, 'message' => 'Execution logs']);
    }

    public function retry(Request $request, int $automation, int $log): JsonResponse
    {
        Gate::authorize('viewAny', AutomationWorkflow::class);
        DB::transaction(function () use ($automation, $log, $request): void {
            $entry = AutomationLog::where('workflow_id', $automation)->lockForUpdate()->findOrFail($log);
            abort_unless($entry->execution_status === 'failed' && $entry->workflow->is_active && ! $entry->workflow->trashed(), 409, 'Only safe failed executions can be retried.');
            $step = $entry->steps()->where('status', 'failed')->first();
            abort_unless($step && $step->retryable, 409, 'This outcome requires operator review.');
            $step->attempts = 0;
            $step->status = 'pending';
            $step->save();
            $entry->execution_status = 'pending';
            $entry->save();
            app(AutomationService::class)->queueRun($entry->id);
            app(AutomationService::class)->audit($request->user(), 'automations.retry', $entry->id);
        }, 5);

        return response()->json(['success' => true, 'message' => 'Retry queued', 'data' => null]);
    }

    private function response(AutomationWorkflow $workflow,string $message,int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => new AutomationWorkflowResource($workflow)],$status);
    }
}
