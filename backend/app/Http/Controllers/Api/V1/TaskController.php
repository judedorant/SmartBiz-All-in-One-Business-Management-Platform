<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrmListRequest;
use App\Http\Requests\TaskCommentRequest;
use App\Http\Requests\TaskListRequest;
use App\Http\Requests\TaskRequest;
use App\Http\Requests\TaskStatusRequest;
use App\Http\Resources\PersonResource;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TaskController extends Controller
{
    public function __construct(private TaskService $service) {}

    public function index(TaskListRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Task::class);
        $query = $this->query($request->user());
        foreach (['status', 'priority', 'assigned_to', 'customer_id', 'lead_id'] as $key) {
            if ($value = $request->validated($key)) {
                $query->where($key, $value);
            }
        }
        if ($request->boolean('overdue')) {
            $query->whereIn('status', ['pending', 'in_progress'])->where('due_date', '<', now());
        }
        if ($term = $request->validated('search')) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$term.'%')->orWhere('description', 'like', '%'.$term.'%'));
        }
        $sort = $request->validated('sort') ?? 'due_date';
        $direction = $request->validated('direction') ?? 'asc';
        if ($sort === 'priority') {
            $query->orderByRaw("CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END ".$direction);
        } else {
            if ($sort === 'due_date') {
                $query->orderByRaw('due_date IS NULL');
            }
            $query->orderBy($sort, $direction);
        }

        return TaskResource::collection($query->orderBy('id')->paginate($request->integer('per_page', 20))->withQueryString())->additional(['success' => true, 'message' => 'Tasks retrieved']);
    }

    public function show(Request $request, int $task): JsonResponse
    {
        $model = $this->query($request->user())->findOrFail($task);
        Gate::authorize('view', $model);

        return $this->response($request, $model, 'Task retrieved');
    }

    public function store(TaskRequest $request): JsonResponse
    {
        return $this->response($request, $this->service->store($request->user(), $request->validated()), 'Task created', 201);
    }

    public function update(TaskRequest $request, int $task): JsonResponse
    {
        return $this->response($request, $this->service->update($request->user(), $task, $request->validated()), 'Task updated');
    }

    public function status(TaskStatusRequest $request, int $task): JsonResponse
    {
        return $this->response($request, $this->service->update($request->user(), $task, $request->validated()), 'Task status updated');
    }

    public function destroy(Request $request, int $task): JsonResponse
    {
        abort_unless($request->user()->can('tasks.assign'), 403);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->service->archive($request->user(), $task, (int) $data['version']);

        return response()->json(['success' => true, 'message' => 'Task archived', 'data' => null]);
    }

    public function comments(CrmListRequest $request, int $task): AnonymousResourceCollection
    {
        $model = Task::visibleTo($request->user())->findOrFail($task);
        Gate::authorize('view', $model);

        return TaskCommentResource::collection($model->comments()->with('user')->latest('id')->paginate($request->integer('per_page', 20)))->additional(['success' => true, 'message' => 'Task comments']);
    }

    public function storeComment(TaskCommentRequest $request, int $task): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Comment added', 'data' => new TaskCommentResource($this->service->comment($request->user(), $task, $request->validated())->load('user'))], 201);
    }

    public function updateComment(TaskCommentRequest $request, int $task, int $comment): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Comment updated', 'data' => new TaskCommentResource($this->service->comment($request->user(), $task, $request->validated(), $comment)->load('user'))]);
    }

    public function destroyComment(Request $request, int $task, int $comment): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->service->deleteComment($request->user(), $task, $comment, (int) $data['version']);

        return response()->json(['success' => true, 'message' => 'Comment deleted', 'data' => null]);
    }

    public function staff(CrmListRequest $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('tasks.assign'), 403);
        $query = User::where('status', 'active')->where(fn ($q) => $q->whereHas('roles.permissions', fn ($p) => $p->whereIn('name', ['tasks.assign', 'tasks.manage_assigned']))->orWhereHas('permissions', fn ($p) => $p->whereIn('name', ['tasks.assign', 'tasks.manage_assigned'])));
        if ($term = $request->validated('search')) {
            $query->where('name', 'like', '%'.$term.'%');
        }

        return PersonResource::collection($query->orderBy('name')->orderBy('id')->paginate($request->integer('per_page', 20)))->additional(['success' => true, 'message' => 'Task staff']);
    }

    private function query(User $user): Builder
    {
        return Task::visibleTo($user)->with(['assignee', 'creator', 'customer' => fn ($q) => $q->visibleTo($user), 'lead' => fn ($q) => $q->visibleTo($user)]);
    }

    private function response(Request $request, Task $task, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => new TaskResource($this->query($request->user())->findOrFail($task->id))], $status);
    }
}
