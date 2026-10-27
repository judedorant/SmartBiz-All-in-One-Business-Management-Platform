<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentCalendarRequest;
use App\Http\Requests\AppointmentListRequest;
use App\Http\Requests\AppointmentRequest;
use App\Http\Requests\CrmListRequest;
use App\Http\Requests\RescheduleRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\PersonResource;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $service) {}

    public function index(AppointmentListRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Appointment::class);
        $q = Appointment::visibleTo($request->user())->with('customer', 'assignee');
        foreach (['status', 'assigned_to', 'customer_id'] as $key) {
            if ($value = $request->validated($key)) {
                $q->where($key, $value);
            }
        }
        if ($search = $request->validated('search')) {
            $q->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%'));
        }

        return AppointmentResource::collection($q->orderBy($request->validated('sort') ?? 'start_at', $request->validated('direction') ?? 'asc')->orderBy('id')->paginate($request->integer('per_page', 20))->withQueryString())->additional(['success' => true, 'message' => 'Appointments retrieved']);
    }

    public function calendar(AppointmentCalendarRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Appointment::class);
        $q = Appointment::visibleTo($request->user())->with('customer', 'assignee')->where('start_at', '<', CarbonImmutable::parse($request->validated('end_at')))->where('end_at', '>', CarbonImmutable::parse($request->validated('start_at')));
        if ($owner = $request->validated('assigned_to')) {
            $q->where('assigned_to', $owner);
        }$rows = $q->orderBy('start_at')->limit(1001)->get();
        if ($rows->count() > 1000) {
            throw ValidationException::withMessages(['start_at' => ['Too many appointments. Filter by staff or select a smaller calendar view.']]);
        }

        return response()->json(['success' => true, 'message' => 'Calendar appointments', 'data' => AppointmentResource::collection($rows)]);
    }

    public function show(Request $request, int $appointment): JsonResponse
    {
        $model = Appointment::visibleTo($request->user())->findOrFail($appointment);
        Gate::authorize('view', $model);

        return $this->response($model, 'Appointment retrieved');
    }

    public function store(AppointmentRequest $request): JsonResponse
    {
        return $this->response($this->service->store($request->user(), $request->validated()), 'Appointment created', 201);
    }

    public function update(AppointmentRequest $request, int $appointment): JsonResponse
    {
        return $this->response($this->service->update($request->user(), $appointment, $request->validated()), 'Appointment updated');
    }

    public function reschedule(RescheduleRequest $request, int $appointment): JsonResponse
    {
        return $this->response($this->service->reschedule($request->user(), $appointment, $request->validated()), 'Appointment rescheduled');
    }

    public function cancel(Request $request, int $appointment): JsonResponse
    {
        abort_unless($request->user()->can('appointments.manage'), 403);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->response($this->service->cancel($request->user(), $appointment, (int) $data['version']), 'Appointment cancelled');
    }

    public function destroy(Request $request, int $appointment): JsonResponse
    {
        abort_unless($request->user()->can('appointments.manage'), 403);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->service->delete($request->user(), $appointment, (int) $data['version']);

        return response()->json(['success' => true, 'message' => 'Appointment archived', 'data' => null]);
    }

    public function staff(CrmListRequest $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('appointments.manage'), 403);
        $q = User::where('status', 'active')->where(fn ($q) => $q->whereHas('roles.permissions', fn ($p) => $p->whereIn('name', ['appointments.manage', 'appointments.view_assigned']))->orWhereHas('permissions', fn ($p) => $p->whereIn('name', ['appointments.manage', 'appointments.view_assigned'])));
        if ($term = $request->validated('search')) {
            $q->where('name', 'like', '%'.$term.'%');
        }

        return PersonResource::collection($q->orderBy('name')->paginate($request->integer('per_page', 20)))->additional(['success' => true, 'message' => 'Appointment staff']);
    }

    private function response(Appointment $model, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => new AppointmentResource($model->load('customer', 'assignee'))], $status);
    }
}
