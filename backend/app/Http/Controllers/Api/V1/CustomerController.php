<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrmListRequest;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\NoteRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\NoteResource;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(CrmListRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);
        $query = Customer::visibleTo($request->user())->with(['assignees', 'creator']);
        if ($term = $request->validated('search')) {
            $query->where(function (Builder $q) use ($term): void {
                foreach (['first_name', 'last_name', 'email', 'company', 'phone', 'customer_code'] as $field) {
                    $q->orWhere($field, 'like', '%'.$term.'%');
                }
            });
        }
        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }
        if ($assigned = $request->validated('assigned_to')) {
            $query->whereHas('assignees', fn (Builder $q) => $q->where('users.id', $assigned));
        }
        $sort = $request->validated('sort') ?: 'created_at';
        if (! in_array($sort, ['created_at', 'updated_at', 'first_name', 'last_name', 'company'], true)) {
            throw ValidationException::withMessages(['sort' => 'Invalid customer sort column.']);
        }

        return CustomerResource::collection($query->orderBy($sort, $request->validated('direction') ?: 'desc')->orderBy('id')->paginate($request->integer('per_page', 20))->withQueryString())->additional(['success' => true, 'message' => 'Customers retrieved']);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        Gate::authorize('create', Customer::class);
        $customer = DB::transaction(function () use ($request): Customer {
            $customer = new Customer($request->validated());
            $customer->created_by = $request->user()->id;
            $customer->save();
            $customer->assignees()->sync($request->validated('assigned_user_ids'));
            $this->record($customer, $request->user(), 'created', 'Customer created.');

            return $customer;
        }, 5);

        return $this->response($customer, 'Customer created successfully', 201);
    }

    public function show(Request $request, int $customer): JsonResponse
    {
        $model = $this->find($request, $customer);
        Gate::authorize('view', $model);

        return $this->response($model, 'Customer retrieved');
    }

    public function update(CustomerRequest $request, int $customer): JsonResponse
    {
        $model = DB::transaction(function () use ($request, $customer): Customer {
            $model = Customer::visibleTo($request->user())->lockForUpdate()->findOrFail($customer);
            Gate::authorize('update', $model);
            abort_if($model->version !== $request->integer('version'), 409, 'This customer changed. Reload it before saving again.');
            $model->fill($request->validated());
            $model->version++;
            $model->save();
            $model->assignees()->sync($request->validated('assigned_user_ids'));
            $this->record($model, $request->user(), 'updated', 'Customer details and staff assignments updated.');

            return $model;
        }, 5);

        return $this->response($model, 'Customer updated successfully');
    }

    public function destroy(Request $request, int $customer): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $customer, $data): void {
            $model = Customer::visibleTo($request->user())->lockForUpdate()->findOrFail($customer);
            Gate::authorize('delete', $model);
            abort_if($model->version !== (int) $data['version'], 409, 'This customer changed. Reload it before deleting.');
            $this->record($model, $request->user(), 'deleted', 'Customer archived.');
            $model->delete();
            DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'customers.delete:'.$model->id, 'created_at' => now()]);
        }, 5);

        return response()->json(['success' => true, 'message' => 'Customer deleted successfully', 'data' => null]);
    }

    public function activities(CrmListRequest $request, int $customer): AnonymousResourceCollection
    {
        $model = $this->find($request, $customer);
        Gate::authorize('view', $model);

        return ActivityResource::collection($model->activities()->with('user')->latest('id')->paginate($request->integer('per_page', 20)))->additional(['success' => true, 'message' => 'Customer activity']);
    }

    public function notes(CrmListRequest $request, int $customer): AnonymousResourceCollection
    {
        $model = $this->find($request, $customer);
        Gate::authorize('view', $model);

        return NoteResource::collection($model->notes()->with('user')->latest('id')->paginate($request->integer('per_page', 20)))->additional(['success' => true, 'message' => 'Customer notes']);
    }

    public function storeNote(NoteRequest $request, int $customer): JsonResponse
    {
        $note = DB::transaction(function () use ($request, $customer) {
            $model = Customer::visibleTo($request->user())->lockForUpdate()->findOrFail($customer);
            Gate::authorize('update', $model);
            $note = $model->notes()->create(['user_id' => $request->user()->id, 'body' => $request->validated('body')]);
            $this->record($model, $request->user(), 'note_added', 'A customer note was added.');

            return $note;
        }, 5);

        return response()->json(['success' => true, 'message' => 'Note created', 'data' => new NoteResource($note->load('user'))], 201);
    }

    public function updateNote(NoteRequest $request, int $customer, int $note): JsonResponse
    {
        $note = DB::transaction(function () use ($request, $customer, $note) {
            $model = Customer::visibleTo($request->user())->lockForUpdate()->findOrFail($customer);
            Gate::authorize('update', $model);
            $entry = $model->notes()->findOrFail($note);
            $entry->update(['body' => $request->validated('body')]);
            $this->record($model, $request->user(), 'note_updated', 'A customer note was updated.');

            return $entry;
        }, 5);

        return response()->json(['success' => true, 'message' => 'Note updated', 'data' => new NoteResource($note->load('user'))]);
    }

    public function destroyNote(Request $request, int $customer, int $note): JsonResponse
    {
        DB::transaction(function () use ($request, $customer, $note): void {
            $model = Customer::visibleTo($request->user())->lockForUpdate()->findOrFail($customer);
            Gate::authorize('update', $model);
            $model->notes()->findOrFail($note)->delete();
            $this->record($model, $request->user(), 'note_deleted', 'A customer note was deleted.');
        }, 5);

        return response()->json(['success' => true, 'message' => 'Note deleted', 'data' => null]);
    }

    private function find(Request $request, int $id): Customer
    {
        return Customer::visibleTo($request->user())->findOrFail($id);
    }

    private function response(Customer $customer, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => new CustomerResource($customer->load('assignees', 'creator'))], $status);
    }

    private function record(Customer $customer, User $user, string $type, string $description): void
    {
        $customer->activities()->create(['user_id' => $user->id, 'activity_type' => $type, 'description' => $description]);
    }
}
