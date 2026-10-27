<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationListRequest;
use App\Http\Resources\WorkspaceNotificationResource;
use App\Models\Task;
use App\Notifications\TaskChangedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function index(NotificationListRequest $request): AnonymousResourceCollection
    {
        $query = $request->user()->notifications();
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->integer('per_page', 20));
        $ids = $page->getCollection()->filter(fn ($n) => $n->type === TaskChangedNotification::class)->map(fn ($n) => $n->data['task_id'] ?? null)->filter(fn ($id) => is_int($id) && $id > 0)->unique()->all();
        $tasks = Task::visibleTo($request->user())->whereIn('id', $ids)->get(['id', 'title'])->keyBy('id');
        foreach ($page->getCollection() as $notification) {
            $id = $notification->type === TaskChangedNotification::class ? ($notification->data['task_id'] ?? null) : null;
            $notification->setRelation('task_context', is_int($id) ? $tasks->get($id) : null);
        }

        return WorkspaceNotificationResource::collection($page)->additional(['success' => true, 'message' => 'Notifications retrieved']);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Unread notification count', 'data' => ['unread_count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $entry = $request->user()->notifications()->findOrFail($notification);
        $entry->markAsRead();

        return response()->json(['success' => true, 'message' => 'Notification marked as read', 'data' => null]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Notifications marked as read', 'data' => null]);
    }
}
