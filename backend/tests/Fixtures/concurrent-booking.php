<?php

use App\Models\User;
use App\Services\AppointmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
    exit(2);
}
[$script, $actorId, $staffId, $customerId, $start, $end, $barrier] = $argv;
try {
    DB::transaction(function () use ($actorId, $staffId, $customerId, $start, $end, $barrier): void {
        if ($barrier !== '-') {
            User::whereKey($staffId)->lockForUpdate()->firstOrFail();
            file_put_contents($barrier, 'locked');
            usleep(700000);
        }
        app(AppointmentService::class)->store(User::findOrFail($actorId), [
            'title' => 'Concurrent test booking', 'assigned_to' => (int) $staffId,
            'customer_id' => (int) $customerId, 'start_at' => $start,
            'end_at' => $end, 'status' => 'scheduled',
        ]);
    });
    echo json_encode(['status' => 201]);
} catch (Throwable $exception) {
    echo json_encode(['status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500]);
}
