<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AutomationEvent;
use App\Models\AutomationLog;
use App\Models\AutomationStep;
use App\Models\AutomationWorkflow;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Sale;
use App\Models\StaffAvailability;
use App\Models\StaffSchedule;
use App\Models\Task;
use App\Models\User;
use App\Services\AutomationRunner;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Faker\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

class Qa2026ScenarioSeeder extends Seeder
{
    public const REFERENCE = '2026-10-28 12:00:00 Asia/Manila';

    public const MANIFEST = 'qa-2026-manifest.json';

    public function run(): void
    {
        $connection = DB::connection();
        $memory = app()->environment('testing') && $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        $demo = $connection->getDriverName() === 'mysql' && $connection->getDatabaseName() === 'smartbiz_demo' && in_array($connection->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true);
        if (! app()->environment(['local', 'testing']) || (! $memory && ! $demo)) {
            throw new RuntimeException('QA scenarios require smartbiz_demo on local MySQL or an isolated testing :memory: database.');
        }
        if (DB::table('audit_logs')->where('action', 'qa.2026.seed.v1')->exists()) {
            if (! Storage::disk('local')->exists(self::MANIFEST)) {
                throw new RuntimeException('QA marker exists but its private manifest is missing; refusing to overwrite accounts.');
            }
            $this->command?->info('QA 2026 scenario already exists; no records or credentials changed.');

            return;
        }
        if (Storage::disk('local')->exists(self::MANIFEST) || User::where('email', 'qa2026.admin@example.test')->exists()) {
            throw new RuntimeException('Unmarked QA credentials/account already exist; refusing duplicate data.');
        }
        foreach (['Admin', 'Manager', 'Staff'] as $role) {
            if (! Role::where('name', $role)->where('guard_name', 'web')->exists()) {
                throw new RuntimeException('Canonical roles must already exist; this seeder does not modify permissions.');
            }
        }
        $previous = Carbon::getTestNow();
        $previousImmutable = CarbonImmutable::getTestNow();
        $reference = CarbonImmutable::parse(self::REFERENCE);
        Carbon::setTestNow($reference);
        CarbonImmutable::setTestNow($reference);
        try {
            Model::withoutEvents(function () use ($reference): void {
                DB::transaction(function () use ($reference): void {
                    $fake = Factory::create('en_PH');
                    $fake->seed(20261028);
                    $password = Str::password(24);
                    $admin = User::factory()->create(['name' => 'QA2026 Scenario Administrator', 'email' => 'qa2026.admin@example.test', 'password' => $password, 'status' => 'active']);
                    $admin->assignRole('Admin');
                    $staff = User::role('Staff')->where('status', 'active')->orderBy('id')->get();
                    if ($staff->isEmpty()) {
                        throw new RuntimeException('Existing active Staff accounts are required.');
                    }
                    StaffSchedule::create(['user_id' => $admin->id, 'timezone' => 'Asia/Manila']);
                    foreach (range(1, 5) as $day) {
                        StaffAvailability::create(['user_id' => $admin->id, 'day_of_week' => $day, 'start_time' => '09:00', 'end_time' => '17:00']);
                    }
                    $customers = collect();
                    $won = PipelineStage::where('slug', 'won')->firstOrFail();
                    $leads = collect();
                    $acquisition = [1 => 1, 2 => 2, 3 => 4, 4 => 1, 5 => 2, 6 => 1, 7 => 2, 8 => 3, 9 => 2, 10 => 2];
                    foreach ($acquisition as $month => $count) {
                        for ($index = 0; $index < $count; $index++) {
                            $number = $customers->count() + 1;
                            $date = CarbonImmutable::create(2026, $month, 1 + $index * 2, 9, 0, 0, 'Asia/Manila');
                            $customer = Customer::factory()->create(['customer_code' => sprintf('QA2026-CUS-%03d', $number), 'first_name' => $fake->firstName(), 'last_name' => $fake->randomElement(['Santos', 'Reyes', 'Cruz', 'Ramos']), 'email' => sprintf('qa2026.customer%02d@example.test', $number), 'phone' => sprintf('+63 000 000 %04d', $number), 'company' => 'QA2026 '.$fake->randomElement(['Sari-sari Supplies', 'Cebu Office Services', 'Luzon Trading', 'Visayas Logistics']).' '.$number, 'address' => $fake->address(), 'status' => $number % 5 ? 'active' : 'inactive', 'created_by' => $admin->id, 'created_at' => $date->utc(), 'updated_at' => $date->utc()]);
                            $owner = $staff[($number - 1) % $staff->count()];
                            $customer->assignees()->attach($owner->id);
                            $customer->activities()->create(['user_id' => $admin->id, 'activity_type' => 'created', 'description' => 'QA2026: Historical customer registration fixture.']);
                            $customers->push($customer);
                            $leadDate = $date->addHour();
                            $leads->push(Lead::factory()->create(['customer_id' => $customer->id, 'assigned_to' => $owner->id, 'created_by' => $admin->id, 'pipeline_stage_id' => $won->id, 'title' => 'QA2026 Won supply agreement '.$number, 'source' => $fake->randomElement(['Referral', 'Website', 'Trade fair', 'Facebook']), 'estimated_value' => (string) (20000 + $number * 7500).'.00', 'expected_close_date' => $leadDate->addDays(2)->toDateString(), 'created_at' => $leadDate->utc(), 'updated_at' => $leadDate->utc()]));
                        }
                    }
                    $sales = [];
                    $transactions = [1 => 4, 2 => 6, 3 => 10, 4 => 10, 5 => 14, 6 => 8, 7 => 10, 8 => 14, 9 => 14, 10 => 10];
                    $prices = [1 => 10000, 2 => 100000, 3 => 110000, 4 => 110000, 5 => 140000, 6 => 80000, 7 => 110000, 8 => 150000, 9 => 160000, 10 => 90000];
                    foreach ($transactions as $month => $count) {
                        for ($index = 0; $index < $count; $index++) {
                            $date = CarbonImmutable::create(2026, $month, 3 + $index, 15, 0, 0, 'Asia/Manila');
                            if ($month === 10 && $index === $count - 1) {
                                $date = $reference->setHour(11);
                            }
                            $eligible = $leads->filter(fn (Lead $lead): bool => $lead->created_at->lessThan($date))->values();
                            $lead = $eligible[($index + $month) % $eligible->count()];
                            $void = ($index === 0 && in_array($month, [4, 6, 9, 10], true)) || ($month === 10 && $index === 5);
                            $amount = $prices[$month] + $index * 137 + $month * 29;
                            $sale = Sale::factory()->create(['customer_id' => $lead->customer_id, 'lead_id' => $lead->id, 'recorded_by' => $admin->id, 'amount' => sprintf('%d.%02d', $amount, ($month * 7 + $index * 3) % 100), 'currency' => 'PHP', 'status' => $void ? 'voided' : 'completed', 'closed_at' => $date->utc(), 'voided_at' => $void ? $date->addHour()->utc() : null, 'voided_by' => $void ? $admin->id : null, 'void_reason' => $void ? 'QA2026: Duplicate payment correction fixture.' : null, 'created_at' => $date->utc(), 'updated_at' => $date->utc()]);
                            $sales[] = $sale->id;
                            $lead->customer->activities()->create(['user_id' => $admin->id, 'activity_type' => 'sale_recorded', 'description' => 'QA2026: Historical sale #'.$sale->id.'.']);
                        }
                    }
                    $plannedLeads = [];
                    for ($index = 0; $index < 10; $index++) {
                        $customer = $customers[$index];
                        $stage = PipelineStage::where('slug', ['qualified', 'proposal', 'negotiation'][$index % 3])->firstOrFail();
                        $lead = Lead::factory()->create(['customer_id' => $customer->id, 'assigned_to' => $staff[$index % $staff->count()]->id, 'created_by' => $admin->id, 'pipeline_stage_id' => $stage->id, 'title' => 'QA2026 Planned '.($index < 5 ? 'November' : 'December').' supply contract '.($index + 1), 'source' => 'Returning customer', 'estimated_value' => (string) (80000 + $index * 5500).'.00', 'expected_close_date' => '2026-'.($index < 5 ? '11' : '12').'-'.str_pad((string) (10 + $index), 2, '0', STR_PAD_LEFT), 'notes' => 'QA2026: Forecast only. No completed sale is recorded for this opportunity.']);
                        $plannedLeads[] = $lead->id;
                    }
                    $appointments = [];
                    $tasks = [];
                    for ($index = 0; $index < 22; $index++) {
                        $date = $index < 2 ? $reference->startOfDay()->setHour($index ? 14 : 10) : CarbonImmutable::create(2026, $index < 12 ? 11 : 12, 1, 9, 0, 0, 'Asia/Manila')->addWeekdays(1 + intdiv(($index - 2) % 10, 2))->addHours(($index % 2) * 2);
                        $customer = $customers[$index % $customers->count()];
                        $appointment = Appointment::factory()->create(['customer_id' => $customer->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'title' => 'QA2026 '.($index < 2 ? 'Current-day' : 'Planned').' review '.($index + 1), 'description' => 'QA2026: Local demonstration appointment.', 'start_at' => $date->utc(), 'end_at' => $date->addHour()->utc(), 'status' => $index === 0 ? 'completed' : ($index % 2 ? 'confirmed' : 'scheduled'), 'created_at' => $reference->subDay()->utc(), 'updated_at' => $reference->utc()]);
                        $appointments[] = $appointment->id;
                        $task = Task::factory()->create(['title' => 'QA2026 '.($index < 2 ? 'Current follow-up ' : 'Planned procurement ').($index + 1), 'description' => 'QA2026: Prepare requirements, confirm quantities, and record the outcome.', 'assigned_to' => $staff[$index % $staff->count()]->id, 'created_by' => $admin->id, 'customer_id' => $customer->id, 'lead_id' => $leads[$index % $leads->count()]->id, 'status' => $index === 0 ? 'completed' : 'pending', 'priority' => Task::PRIORITIES[$index % 4], 'due_date' => $index < 2 ? $reference->subHours(2)->utc() : $date->subDay()->utc(), 'completed_at' => $index === 0 ? $reference->subHour()->utc() : null, 'created_at' => $reference->subDay()->utc(), 'updated_at' => $reference->utc()]);
                        $tasks[] = $task->id;
                    }
                    $workflow = AutomationWorkflow::factory()->create(['name' => 'QA2026 Local task execution audit', 'created_by' => $admin->id, 'is_active' => true, 'configuration' => ['actions' => [['type' => 'create_task', 'title' => 'QA2026 Automated follow-up {{title}}', 'assigned_to' => $admin->id, 'priority' => 'medium', 'due_in_hours' => 24]]]]);
                    $logs = [];
                    for ($index = 0; $index < 50; $index++) {
                        $lead = $leads[$index % $leads->count()];
                        $event = AutomationEvent::factory()->create(['event_key' => 'qa2026-v1-'.($index + 1), 'trigger_type' => 'lead_created', 'subject_type' => 'lead', 'subject_id' => $lead->id, 'subject_version' => $lead->version, 'context' => ['workflow_ids' => [$workflow->id], 'qa_fixture' => true], 'occurred_at' => $reference->utc(), 'processed_at' => $reference->utc()]);
                        $log = AutomationLog::factory()->create(['workflow_id' => $workflow->id, 'event_id' => $event->id, 'workflow_version' => $workflow->version, 'execution_status' => 'pending', 'execution_data' => ['trigger' => 'lead_created', 'subject_type' => 'lead', 'subject_id' => $lead->id]]);
                        AutomationStep::factory()->create(['log_id' => $log->id, 'action_type' => 'create_task', 'configuration' => $workflow->configuration['actions'][0]]);
                        app(AutomationRunner::class)->run($log->id);
                        if ($log->fresh()->execution_status !== 'succeeded') {
                            throw new RuntimeException('A QA local automation did not execute successfully; scenario rolled back.');
                        }
                        $logs[] = $log->id;
                    }
                    $workflow->is_active = false;
                    $workflow->save();
                    $manifest = ['batch' => 'qa2026-v1', 'reference' => self::REFERENCE, 'database' => DB::connection()->getDatabaseName(), 'account' => ['id' => $admin->id, 'role' => 'Admin', 'email' => $admin->email, 'password' => $password], 'customers' => $customers->pluck('id')->all(), 'won_leads' => $leads->pluck('id')->all(), 'planned_leads' => $plannedLeads, 'sales' => $sales, 'appointments' => $appointments, 'manual_tasks' => $tasks, 'workflow' => $workflow->id, 'automation_logs' => $logs];
                    if (! Storage::disk('local')->put(self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                        throw new RuntimeException('Could not save private QA manifest; database changes rolled back.');
                    }
                    DB::table('audit_logs')->insert(['user_id' => $admin->id, 'action' => 'qa.2026.seed.v1', 'created_at' => $reference->utc()]);
                });
            });
        } finally {
            Carbon::setTestNow($previous);
            CarbonImmutable::setTestNow($previousImmutable);
        }
        chmod(Storage::disk('local')->path(self::MANIFEST), 0600);
        $this->command?->info('Appended QA2026 scenario and 50 actually executed local automation logs. Credentials remain in the private manifest.');
    }
}
