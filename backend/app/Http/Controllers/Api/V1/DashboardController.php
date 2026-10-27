<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardRequest;
use App\Services\DashboardService;
use App\Services\DateRange;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $service) {}

    public function summary(DashboardRequest $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Dashboard summary', 'data' => $this->service->summary($request->user(), DateRange::from($request->validated()))]);
    }

    public function analytics(DashboardRequest $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Dashboard analytics', 'data' => $this->service->analytics($request->user(), DateRange::from($request->validated()))]);
    }
}
