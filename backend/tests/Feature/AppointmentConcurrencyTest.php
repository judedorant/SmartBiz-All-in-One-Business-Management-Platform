<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\StaffAvailability;
use App\Models\StaffSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AppointmentConcurrencyTest extends TestCase
{
    public function test_two_mysql_processes_cannot_claim_the_same_empty_staff_slot(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row-lock concurrency is verified with the native MySQL suite.');
        }
        if (! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
            $this->markTestSkipped('A dedicated database ending in _test is required.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $barrier = sys_get_temp_dir().'/smartbiz-booking-'.Str::uuid();
        $processes = [];
        try {
            $this->seed(RolesAndPermissionsSeeder::class);
            $manager = User::factory()->create();
            $manager->assignRole('Manager');
            $staff = User::factory()->create();
            $staff->assignRole('Staff');
            $customer = Customer::factory()->create();
            $start = CarbonImmutable::now('UTC')->addDays(2)->startOfDay()->setHour(10);
            StaffSchedule::factory()->create(['user_id' => $staff->id]);
            StaffAvailability::factory()->create(['user_id' => $staff->id, 'day_of_week' => $start->dayOfWeek]);
            $config = config('database.connections.mysql');
            $environment = [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_DEBUG' => 'false',
                'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                'DB_SOCKET' => $config['unix_socket'], 'DB_USERNAME' => $config['username'],
                'DB_PASSWORD' => $config['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array',
                'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            ];
            $arguments = [PHP_BINARY, base_path('tests/Fixtures/concurrent-booking.php'),
                (string) $manager->id, (string) $staff->id, (string) $customer->id,
                $start->format('Y-m-d\TH:i:s\Z'), $start->addHour()->format('Y-m-d\TH:i:s\Z')];
            $first = new Process([...$arguments, $barrier], base_path(), $environment);
            $processes[] = $first;
            $first->setTimeout(25)->start();
            $deadline = microtime(true) + 15;
            while (! file_exists($barrier) && $first->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($barrier);
            $second = new Process([...$arguments, '-'], base_path(), $environment);
            $processes[] = $second;
            $second->setTimeout(25)->start();
            $first->wait();
            $second->wait();
            $this->assertSame(201, json_decode($first->getOutput(), true)['status'] ?? null);
            $this->assertSame(409, json_decode($second->getOutput(), true)['status'] ?? null);
            $this->assertDatabaseCount('appointments', 1);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
        }
    }
}
