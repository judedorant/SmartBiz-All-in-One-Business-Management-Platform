<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Sale;
use App\Models\StaffSchedule;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Faker\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SmartBizQADemoSeeder extends Seeder
{
    public const REFERENCE = '2026-10-28 12:00:00 Asia/Manila';

    public const MANIFEST = 'smartbiz-usd-demo-manifest.json';

    public function run(): void
    {
        $connection = DB::connection();
        $memory = app()->environment('testing') && $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        $demo = $connection->getDriverName() === 'mysql' && $connection->getDatabaseName() === 'smartbiz_demo' && in_array($connection->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true);
        if (! app()->environment(['local', 'testing']) || (! $memory && ! $demo)) {
            throw new RuntimeException('USD QA data may only be added to local smartbiz_demo or testing :memory:.');
        }
        if (DB::table('audit_logs')->where('action', 'qa.usd.demo.v1')->exists()) {
            if (! Storage::disk('local')->exists(self::MANIFEST)) {
                throw new RuntimeException('USD QA marker exists without its manifest; review required.');
            }
            $this->command?->info('USD QA batch already exists; nothing was changed.');

            return;
        }
        if (Customer::withTrashed()->where('customer_code', 'like', 'USDQA2026-%')->exists() || Storage::disk('local')->exists(self::MANIFEST)) {
            throw new RuntimeException('Unmarked USD QA data exists; refusing duplicates.');
        }
        $actor = User::where('email', 'qa2026.admin@example.test')->where('status', 'active')->firstOrFail();
        if (! $actor->can('sales.manage') || ! $actor->can('appointments.manage') || ! $actor->can('tasks.assign')) {
            throw new RuntimeException('Existing QA administrator lacks required canonical permissions.');
        }
        $staff = User::role('Staff')->where('status', 'active')->orderBy('id')->get();
        $managers = User::role('Manager')->where('status', 'active')->orderBy('id')->get();
        if ($staff->isEmpty() || $managers->isEmpty() || ! $actor->can('view', $actor)) {
            throw new RuntimeException('Existing demo team is required.');
        }
        $schedule = StaffSchedule::where('user_id', $actor->id)->firstOrFail();
        if ($schedule->timezone !== 'Asia/Manila') {
            throw new RuntimeException('Existing QA administrator must have Asia/Manila availability.');
        }
        $reference = CarbonImmutable::parse(self::REFERENCE);
        $fake = Factory::create('en_PH');
        $fake->seed(2026102801);
        Model::withoutEvents(function () use ($actor, $staff, $managers, $reference, $fake): void {
            DB::transaction(function () use ($actor, $staff, $managers, $reference, $fake): void {
                $customers = collect();
                for ($index = 0; $index < 100; $index++) {
                    $date = CarbonImmutable::create(2026, intdiv($index, 10) + 1, 3 + $index % 10, 9, 0, 0, 'Asia/Manila');
                    $customer = Customer::factory()->create(['customer_code' => sprintf('USDQA2026-%03d', $index + 1), 'first_name' => $fake->firstName(), 'last_name' => $fake->randomElement(['Santos', 'Reyes', 'Cruz', 'Garcia', 'Ramos', 'Aquino']), 'email' => sprintf('usdqa.customer%03d@example.test', $index + 1), 'phone' => sprintf('+63 000 001 %04d', $index + 1), 'company' => 'USDQA2026 '.$fake->randomElement(['Office Trading', 'Retail Supplies', 'Business Services', 'Logistics']).' '.($index + 1), 'address' => $fake->address(), 'status' => $index % 5 ? 'active' : 'inactive', 'created_by' => $actor->id, 'created_at' => $date->utc(), 'updated_at' => $date->utc()]);
                    $customer->assignees()->attach($staff[$index % $staff->count()]->id);
                    $customers->push($customer);
                }
                $stages = PipelineStage::orderBy('position')->get();
                if ($stages->count() !== 7) {
                    throw new RuntimeException('The canonical seven-stage pipeline is required.');
                }
                $leads = collect();
                for ($index = 0; $index < 80; $index++) {
                    $customer = $customers[($index % 10) * 10 + intdiv($index, 10)];
                    $stage = $stages[$index % 7];
                    $registered = CarbonImmutable::parse($customer->created_at);
                    $date = $stage->slug === 'won' ? $registered->subDay() : $registered->addHour();
                    $leads->push(Lead::factory()->create(['title' => 'USDQA2026 '.$customer->company.' agreement '.($index + 1), 'source' => $fake->randomElement(['Referral', 'Website', 'Trade fair', 'Walk-in']), 'customer_id' => $customer->id, 'assigned_to' => $staff[$index % $staff->count()]->id, 'created_by' => $actor->id, 'pipeline_stage_id' => $stage->id, 'estimated_value' => sprintf('%d.00', 1500 + $index * 125), 'expected_close_date' => $date->addDays(14)->toDateString(), 'converted_at' => $stage->slug === 'won' ? $registered : null, 'created_at' => $date, 'updated_at' => $registered]));
                }
                $won = $leads->filter(fn (Lead $lead): bool => $lead->stage->slug === 'won')->values();
                $sales = [];
                $prices = [500, 800, 1200, 1100, 1500, 1000, 1400, 1800, 2000, 1700];
                for ($month = 1; $month <= 10; $month++) {
                    $eligible = $won->filter(fn (Lead $lead): bool => $lead->created_at->setTimezone('Asia/Manila')->month === $month)->values();
                    for ($index = 0; $index < 20; $index++) {
                        $lead = $eligible[$index % $eligible->count()];
                        $date = CarbonImmutable::create(2026, $month, 15 + $index % 12, 10 + intdiv($index, 12), 0, 0, 'Asia/Manila');
                        if ($month === 10 && $index === 19) {
                            $date = $reference->setHour(11);
                        }
                        $void = $index % 10 === 0;
                        $cents = $prices[$month - 1] * 100 + $index * 1713 + $month * 225;
                        $sale = Sale::factory()->create(['customer_id' => $lead->customer_id, 'lead_id' => $lead->id, 'recorded_by' => $managers[$index % $managers->count()]->id, 'currency' => 'USD', 'amount' => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100), 'status' => $void ? 'voided' : 'completed', 'closed_at' => $date->utc(), 'voided_at' => $void ? $date->addHour()->utc() : null, 'voided_by' => $void ? $actor->id : null, 'void_reason' => $void ? 'USDQA2026: Duplicate payment correction.' : null, 'created_at' => $date->utc(), 'updated_at' => $date->utc()]);
                        $sales[] = $sale->id;
                    }
                }
                $tasks = [];
                $statuses = ['completed', 'pending', 'in_progress', 'completed', 'pending', 'cancelled'];
                for ($index = 0; $index < 60; $index++) {
                    $customer = $customers[$index];
                    $date = $index >= 40 ? $reference->startOfDay()->subDays(1 + $index % 7) : CarbonImmutable::create(2026, $index % 10 + 1, 16, 9, 0, 0, 'Asia/Manila');
                    $date = $date->max(CarbonImmutable::parse($customer->created_at)->addHour());
                    $status = $statuses[$index % 6];
                    $task = Task::factory()->create(['title' => 'USDQA2026 '.$fake->randomElement(['Prepare quotation', 'Confirm delivery', 'Call customer', 'Review agreement']).' '.($index + 1), 'description' => 'USDQA2026: Record requirements and the follow-up outcome.', 'customer_id' => $customer->id, 'assigned_to' => $staff[$index % $staff->count()]->id, 'created_by' => $actor->id, 'priority' => Task::PRIORITIES[$index % 4], 'status' => $status, 'due_date' => $index >= 55 ? $reference->addDays(7)->utc() : $date->addDay()->utc(), 'completed_at' => $status === 'completed' ? $date->addHours(12)->utc() : null, 'created_at' => $date->utc(), 'updated_at' => $date->utc()]);
                    $tasks[] = $task->id;
                }
                $appointments = [];
                for ($index = 0; $index < 20; $index++) {
                    $date = CarbonImmutable::create(2026, $index % 10 + 1, 14 + intdiv($index, 10), 9 + intdiv($index, 10) * 2, 0, 0, 'Asia/Manila');
                    if ($date->isWeekend()) {
                        $date = $date->previousWeekday();
                    }
                    $appointment = Appointment::factory()->create(['customer_id' => $customers[($index % 10) * 10 + intdiv($index, 10)]->id, 'assigned_to' => $actor->id, 'created_by' => $actor->id, 'title' => 'USDQA2026 Historical consultation '.($index + 1), 'description' => 'USDQA2026: Historical customer review.', 'start_at' => $date->utc(), 'end_at' => $date->addHour()->utc(), 'status' => $index % 4 ? 'completed' : 'cancelled', 'created_at' => $date->subDay()->utc(), 'updated_at' => $date->utc()]);
                    $appointments[] = $appointment->id;
                }
                $cursor = $reference->addWeekday()->startOfDay()->setHour(9);
                for ($index = 0; $index < 20; $index++) {
                    while (Appointment::where('assigned_to', $actor->id)->whereIn('status', ['scheduled', 'confirmed'])->where('start_at', '<', $cursor->addHour()->utc())->where('end_at', '>', $cursor->utc())->exists()) {
                        $cursor = $this->nextSlot($cursor);
                    }
                    $appointment = Appointment::factory()->create(['customer_id' => $customers[20 + $index]->id, 'assigned_to' => $actor->id, 'created_by' => $actor->id, 'title' => 'USDQA2026 Upcoming consultation '.($index + 1), 'description' => 'USDQA2026: Scheduled weekday customer review.', 'start_at' => $cursor->utc(), 'end_at' => $cursor->addHour()->utc(), 'status' => $index % 2 ? 'confirmed' : 'scheduled', 'created_at' => $reference->utc(), 'updated_at' => $reference->utc()]);
                    $appointments[] = $appointment->id;
                    $cursor = $this->nextSlot($cursor);
                }
                $manifest = ['batch' => 'usd-demo-v1', 'database' => DB::connection()->getDatabaseName(), 'currency' => 'USD', 'reference' => self::REFERENCE, 'actor_id' => $actor->id, 'customers' => $customers->pluck('id')->all(), 'leads' => $leads->pluck('id')->all(), 'sales' => $sales, 'tasks' => $tasks, 'appointments' => $appointments];
                if (! Storage::disk('local')->put(self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('Could not save USD QA manifest; records rolled back.');
                }
                DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => 'qa.usd.demo.v1', 'created_at' => $reference->utc()]);
            });
        });
        $this->command?->info('Added 100 customers, 80 staged leads, 200 USD sales, 60 tasks and 40 appointments to the demo only.');
    }

    private function nextSlot(CarbonImmutable $date): CarbonImmutable
    {
        return $date->hour < 15 ? $date->addHours(2) : $date->addWeekday()->startOfDay()->setHour(9);
    }
}
