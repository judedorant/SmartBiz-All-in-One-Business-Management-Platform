<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertLeadRequest;
use App\Http\Requests\CrmListRequest;
use App\Http\Requests\LeadRequest;
use App\Http\Requests\NoteRequest;
use App\Http\Requests\StageRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Services\LeadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public function __construct(private LeadService $service) {}

    public function index(CrmListRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Lead::class);
        $q = Lead::visibleTo($request->user())->with(['stage', 'assignee', 'customer']);
        if ($search = $request->validated('search')) {
            $q->where(fn (Builder $q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('source', 'like', '%'.$search.'%'));
        }
        foreach (['assigned_to', 'customer_id', 'pipeline_stage_id'] as $field) {
            if ($value = $request->validated($field)) {
                $q->where($field, $value);
            }
        }
        $sort = $request->validated('sort') ?: 'created_at';
        if (! in_array($sort, ['created_at', 'updated_at', 'title', 'estimated_value', 'expected_close_date'], true)) {
            throw ValidationException::withMessages(['sort' => 'Invalid lead sort column.']);
        }

        return LeadResource::collection($q->orderBy($sort, $request->validated('direction') ?: 'desc')->orderBy('id')->paginate($request->integer('per_page', 20))->withQueryString())->additional(['success' => true, 'message' => 'Leads retrieved']);
    }

    public function store(LeadRequest $request): JsonResponse
    {
        return $this->response($this->service->create($request->user(), $request->validated()), 'Lead created successfully', 201);
    }

    public function show(Request $request, int $lead): JsonResponse
    {
        $model = Lead::visibleTo($request->user())->findOrFail($lead);
        Gate::authorize('view', $model);

        return $this->response($model, 'Lead retrieved');
    }

    public function update(LeadRequest $request, int $lead): JsonResponse
    {
        return $this->response($this->service->update($request->user(), $lead, $request->validated()), 'Lead updated successfully');
    }

    public function stage(StageRequest $request, int $lead): JsonResponse
    {
        return $this->response($this->service->changeStage($request->user(), $lead, $request->integer('pipeline_stage_id'), $request->integer('version')), 'Lead stage updated');
    }

    public function convert(ConvertLeadRequest $request, int $lead): JsonResponse
    {
        return $this->response($this->service->convert($request->user(), $lead, $request->validated()), 'Lead converted successfully', 201);
    }

    public function destroy(Request $request, int $lead): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->service->delete($request->user(), $lead, (int) $data['version']);

        return response()->json(['success' => true, 'message' => 'Lead deleted successfully', 'data' => null]);
    }

    public function activities(CrmListRequest $request, int $lead): AnonymousResourceCollection
    {
        $model = Lead::visibleTo($request->user())->findOrFail($lead);
        Gate::authorize('view', $model);

        return ActivityResource::collection($model->activities()->with('user')->latest('id')->paginate($request->integer('per_page', 20)))->additional(['success' => true, 'message' => 'Lead activity']);
    }

    public function storeActivity(NoteRequest $request, int $lead): JsonResponse
    {
        $this->service->addActivity($request->user(), $lead, $request->validated('body'));

        return response()->json(['success' => true, 'message' => 'Activity recorded', 'data' => null], 201);
    }

    private function response(Lead $lead, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => new LeadResource($lead->load('stage', 'assignee', 'customer'))], $status);
    }
}
