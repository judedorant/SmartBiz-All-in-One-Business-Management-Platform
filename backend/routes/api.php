<?php

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomationController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\CrmMetadataController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

foreach (['', 'v1'] as $passwordPrefix) {
    Route::prefix($passwordPrefix)->middleware('throttle:api')->group(function (): void {
        Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:password-recovery');
        Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:password-reset');
        Route::put('profile/change-password', [PasswordController::class, 'change'])->middleware(['auth:sanctum', 'active', 'throttle:password-change']);
        Route::post('users/{user}/send-password-reset', [PasswordController::class, 'admin'])->whereNumber('user')->middleware(['auth:sanctum', 'active', 'throttle:password-admin']);
    });
}

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function (): void {
    Route::post('webhooks/{endpoint}/receive', [WebhookController::class, 'receive'])->whereNumber('endpoint')->middleware('throttle:30,1')->name('webhooks.receive');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->middleware('throttle:20,1')->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->whereNumber('user')->middleware('throttle:20,1')->name('users.update');
        Route::get('reports', [ReportController::class, 'show'])->name('reports.show');
        Route::get('reports/export', [ReportController::class, 'export'])->middleware('throttle:10,1')->name('reports.export');
        Route::get('automations/catalog', [AutomationController::class, 'catalog'])->name('automations.catalog');
        Route::apiResource('automations', AutomationController::class)->except('show')->whereNumber('automation');
        Route::patch('automations/{automation}/active', [AutomationController::class, 'active'])->whereNumber('automation')->name('automations.active');
        Route::get('automations/{automation}/logs', [AutomationController::class, 'logs'])->whereNumber('automation')->name('automations.logs');
        Route::post('automations/{automation}/logs/{log}/retry', [AutomationController::class, 'retry'])->whereNumber(['automation', 'log'])->name('automations.retry');
        Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations.index');
        Route::post('integrations/google/{provider}/connect', [IntegrationController::class, 'connect'])->name('integrations.connect');
        Route::delete('integrations/{integration}', [IntegrationController::class, 'disconnect'])->whereNumber('integration')->name('integrations.disconnect');
        Route::get('webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
        Route::post('webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
        Route::put('webhooks/{endpoint}', [WebhookController::class, 'update'])->whereNumber('endpoint')->name('webhooks.update');
        Route::delete('webhooks/{endpoint}', [WebhookController::class, 'destroy'])->whereNumber('endpoint')->name('webhooks.destroy');
        Route::get('webhooks/{endpoint}/deliveries', [WebhookController::class, 'deliveries'])->whereNumber('endpoint')->name('webhooks.deliveries');

        Route::get('tasks/staff', [TaskController::class, 'staff'])->name('tasks.staff');
        Route::apiResource('tasks', TaskController::class)->whereNumber('task');
        Route::patch('tasks/{task}/status', [TaskController::class, 'status'])->whereNumber('task')->name('tasks.status');
        Route::get('tasks/{task}/comments', [TaskController::class, 'comments'])->whereNumber('task')->name('tasks.comments.index');
        Route::post('tasks/{task}/comments', [TaskController::class, 'storeComment'])->whereNumber('task')->name('tasks.comments.store');
        Route::put('tasks/{task}/comments/{comment}', [TaskController::class, 'updateComment'])->whereNumber(['task', 'comment'])->name('tasks.comments.update');
        Route::delete('tasks/{task}/comments/{comment}', [TaskController::class, 'destroyComment'])->whereNumber(['task', 'comment'])->name('tasks.comments.destroy');
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'count'])->name('notifications.count');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
        Route::get('appointments/calendar', [AppointmentController::class, 'calendar'])->name('appointments.calendar');
        Route::get('appointments/staff', [AppointmentController::class, 'staff'])->name('appointments.staff');
        Route::apiResource('appointments', AppointmentController::class)->whereNumber('appointment');
        Route::patch('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->whereNumber('appointment')->name('appointments.reschedule');
        Route::patch('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->whereNumber('appointment')->name('appointments.cancel');
        Route::get('staff/{user}/availability', [AvailabilityController::class, 'show'])->whereNumber('user')->name('availability.show');
        Route::put('staff/{user}/availability', [AvailabilityController::class, 'update'])->whereNumber('user')->name('availability.update');
        Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
        Route::get('dashboard/analytics', [DashboardController::class, 'analytics'])->name('dashboard.analytics');
        Route::get('sales/options', [SaleController::class, 'options'])->name('sales.options');
        Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
        Route::post('sales', [SaleController::class, 'store'])->name('sales.store');
        Route::patch('sales/{sale}/void', [SaleController::class, 'void'])->whereNumber('sale')->name('sales.void');
        Route::get('crm/assignees', [CrmMetadataController::class, 'assignees'])->name('crm.assignees');
        Route::get('pipeline-stages', [CrmMetadataController::class, 'stages'])->name('pipeline-stages.index');
        Route::apiResource('customers', CustomerController::class)->whereNumber('customer');
        Route::get('customers/{customer}/activities', [CustomerController::class, 'activities'])->whereNumber('customer')->name('customers.activities');
        Route::get('customers/{customer}/notes', [CustomerController::class, 'notes'])->whereNumber('customer')->name('customers.notes.index');
        Route::post('customers/{customer}/notes', [CustomerController::class, 'storeNote'])->whereNumber('customer')->name('customers.notes.store');
        Route::put('customers/{customer}/notes/{note}', [CustomerController::class, 'updateNote'])->whereNumber(['customer', 'note'])->name('customers.notes.update');
        Route::delete('customers/{customer}/notes/{note}', [CustomerController::class, 'destroyNote'])->whereNumber(['customer', 'note'])->name('customers.notes.destroy');
        Route::apiResource('leads', LeadController::class)->whereNumber('lead');
        Route::patch('leads/{lead}/stage', [LeadController::class, 'stage'])->whereNumber('lead')->name('leads.stage');
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->whereNumber('lead')->name('leads.convert');
        Route::get('leads/{lead}/activities', [LeadController::class, 'activities'])->whereNumber('lead')->name('leads.activities.index');
        Route::post('leads/{lead}/activities', [LeadController::class, 'storeActivity'])->whereNumber('lead')->name('leads.activities.store');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('dashboard/access', function (Request $request) {
            return response()->json(['success' => true, 'message' => 'Workspace access', 'data' => ['permissions' => $request->user()->getAllPermissions()->pluck('name')->sort()->values()]]);
        })->middleware('permission:dashboard.view')->name('dashboard.access');
        Route::get('team/access', fn () => response()->json(['success' => true, 'message' => 'Team management access granted', 'data' => ['can_assign_tasks' => true]]))
            ->middleware('permission:tasks.assign')->name('team.access');
        Route::get('admin/access', function () {
            Gate::authorize('viewAny', User::class);

            return response()->json(['success' => true, 'message' => 'User administration access granted', 'data' => ['can_manage_users' => true]]);
        })->name('admin.access');
    });
});
