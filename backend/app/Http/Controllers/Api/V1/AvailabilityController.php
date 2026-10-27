<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailabilityRequest;
use App\Http\Resources\AvailabilityResource;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function show(Request $request, int $user): JsonResponse
    {
        abort_unless($request->user()->can('appointments.manage') || ($request->user()->id === $user && $request->user()->can('appointments.view_assigned')), 404);
        User::findOrFail($user);
        $schedule = StaffSchedule::with('windows')->find($user);
        if (! $schedule) {
            $schedule = new StaffSchedule(['user_id' => $user, 'timezone' => 'UTC']);
            $schedule->version = 0;
            $schedule->setRelation('windows', collect());
        }

        return response()->json(['success' => true, 'message' => 'Staff availability', 'data' => new AvailabilityResource($schedule)]);
    }

    public function update(AvailabilityRequest $request, int $user, AvailabilityService $service): JsonResponse
    {
        User::where('status', 'active')->findOrFail($user);
        $schedule = $service->save($user, $request->validated(), $request->user());

        return response()->json(['success' => true, 'message' => 'Availability updated', 'data' => new AvailabilityResource($schedule)]);
    }
}
