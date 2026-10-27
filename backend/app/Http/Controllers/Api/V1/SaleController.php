<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleRequest;
use App\Http\Requests\SalesListRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SaleController extends Controller
{
    public function __construct(private SaleService $service) {}

    public function index(SalesListRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Sale::class);
        $q = Sale::visibleTo($request->user())->with('customer', 'lead', 'recorder');
        foreach (['currency', 'status', 'customer_id'] as $filter) {
            if ($value = $request->validated($filter)) {
                $q->where($filter, $value);
            }
        }

        return SaleResource::collection($q->orderBy($request->validated('sort') ?? 'closed_at', $request->validated('direction') ?? 'desc')->orderBy('id')->paginate($request->integer('per_page', 20))->withQueryString())->additional(['success' => true, 'message' => 'Sales ledger']);
    }

    public function store(SaleRequest $request): JsonResponse
    {
        $sale = $this->service->record($request->user(), $request->validated());

        return response()->json(['success' => true, 'message' => $sale->wasRecentlyCreated ? 'Sale recorded' : 'Existing sale returned', 'data' => new SaleResource($sale->load('customer', 'lead', 'recorder'))], $sale->wasRecentlyCreated ? 201 : 200);
    }

    public function void(Request $request, int $sale): JsonResponse
    {
        abort_unless($request->user()->can('sales.manage'), 403);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']]);
        $model = $this->service->void($request->user(), $sale, (int) $data['version'], $data['reason']);

        return response()->json(['success' => true, 'message' => 'Sale voided', 'data' => new SaleResource($model->load('customer', 'lead', 'recorder'))]);
    }

    public function options(): JsonResponse
    {
        Gate::authorize('viewAny', Sale::class);

        return response()->json(['success' => true, 'message' => 'Sales options', 'data' => ['currencies' => config('smartbiz.currencies'), 'default_currency' => config('smartbiz.default_currency')]]);
    }
}
