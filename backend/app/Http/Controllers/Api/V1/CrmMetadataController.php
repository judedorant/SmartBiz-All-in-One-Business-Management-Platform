<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrmListRequest;
use App\Http\Resources\PersonResource;
use App\Http\Resources\PipelineStageResource;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CrmMetadataController extends Controller
{
    public function assignees(CrmListRequest $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('customers.manage') || $request->user()->can('leads.manage'), 403);
        $q = User::query()->where('status', 'active')->select('id', 'name');
        if ($search = $request->validated('search')) {
            $q->where('name', 'like', '%'.$search.'%');
        }

        return PersonResource::collection($q->orderBy('name')->orderBy('id')->paginate($request->integer('per_page', 20)))->additional(['success' => true, 'message' => 'Active assignees']);
    }

    public function stages(): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        return response()->json(['success' => true, 'message' => 'Pipeline stages', 'data' => PipelineStageResource::collection(PipelineStage::orderBy('position')->get())]);
    }
}
