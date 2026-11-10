<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AutomationWorkflow;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Sale;
use App\Models\StaffAvailability;
use App\Models\StaffSchedule;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskChangedNotification;
use Carbon\CarbonImmutable;
use Faker\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SmartBizDemoSeeder extends Seeder
{
    public const COUNTS = ['users' => 14, 'customers' => 500, 'leads' => 300, 'sales' => 200, 'appointments' => 250, 'tasks' => 300];

    public function run(): void
    {
        $database = DB::connection()->getDatabaseName();
        $testingMemory = app()->environment('testing') && DB::connection()->getDriverName() === 'sqlite' && $database === ':memory:';
        $localMysql = DB::connection()->getDriverName() === 'mysql' && in_array(DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true) && preg_match('/^smartbiz_demo(?:_[a-z0-9]+)?$/D', $database);
        if (! app()->environment(['local', 'testing']) || (! $testingMemory && ! $localMysql)) {
            throw new RuntimeException('Demo seeding requires local/testing and a dedicated smartbiz_demo database (optionally suffixed).');
        }
        if (DB::table('audit_logs')->where('action', 'demo.seed.v1')->exists()) {
            $this->command?->info('Demo data already exists; no accounts or records were changed.');

            return;
        }
        foreach (array_keys(self::COUNTS) as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Refusing to seed a nonempty database. Existing records will not be changed.');
            }
        }
        $credentialFile = 'demo-accounts-'.($testingMemory ? 'testing' : $database).'.json';
        if (Storage::disk('local')->exists($credentialFile)) {
            throw new RuntimeException('Demo credentials already exist without a seed marker; review them before proceeding.');
        }
        $fake = Factory::create('en_PH');
        $fake->seed(20261027);
        $reference = CarbonImmutable::now('Asia/Manila')->startOfDay();
        $start = $reference->startOfMonth()->subMonths(11);
        $this->call([RolesAndPermissionsSeeder::class, PipelineStageSeeder::class]);
        $accounts = Model::withoutEvents(fn () => DB::transaction(function () use ($fake, $reference, $start): array {
            $accounts = [];
            $users = collect();
            foreach (['Admin' => 1, 'Manager' => 3, 'Staff' => 10] as $role => $count) {
                for ($index = 1; $index <= $count; $index++) {
                    $email = strtolower($role).$index.'@demo.smartbiz.test';
                    $password = Str::password(24);
                    $user = User::factory()->create(['name' => 'Demo '.$fake->firstName().' '.$fake->randomElement(['Santos', 'Reyes', 'Cruz', 'Garcia', 'Mendoza', 'Ramos', 'Aquino', 'Bautista']), 'email' => $email, 'password' => $password, 'status' => 'active', 'created_at' => $start->utc()]);
                    $user->assignRole($role);
                    $users->push($user);
                    $accounts[] = ['role' => $role, 'email' => $email, 'password' => $password];
                }
            }
            $admin = $users->first();
            $managers = $users->filter(fn (User $user): bool => $user->hasRole('Manager'))->values();
            $staff = $users->filter(fn (User $user): bool => $user->hasRole('Staff'))->values();
            foreach ($staff as $person) {
                StaffSchedule::create(['user_id' => $person->id, 'timezone' => 'Asia/Manila']);
                foreach (range(1, 5) as $day) {
                    StaffAvailability::create(['user_id' => $person->id, 'day_of_week' => $day, 'start_time' => '09:00', 'end_time' => '17:00']);
                }
            }
            $customers = collect();
            for ($index = 0; $index < 500; $index++) {
                $date = $start->addMonths($index % 12)->addDays(intdiv($index, 12) % 25)->min($reference);
                $customer = Customer::factory()->create(['customer_code' => sprintf('DEMO-CUS-%05d', $index + 1), 'first_name' => $fake->firstName(), 'last_name' => $fake->randomElement(['Santos', 'Reyes', 'Cruz', 'Garcia', 'Mendoza', 'Ramos', 'Aquino', 'Bautista']), 'email' => sprintf('customer%04d@demo.smartbiz.test', $index + 1), 'phone' => '+639'.str_pad((string) (170000000 + $index), 9, '0', STR_PAD_LEFT), 'company' => $fake->randomElement(['Luzon', 'Visayas', 'Mindanao', 'Manila', 'Cebu']).' '.$fake->randomElement(['Trading', 'Foods', 'Retail', 'Services', 'Logistics']).' '.($index + 1), 'address' => $fake->address(), 'status' => $index % 5 === 0 ? 'inactive' : 'active', 'created_by' => $managers[$index % 3]->id, 'created_at' => $date->utc(), 'updated_at' => $date->utc()]);
                $customer->assignees()->attach($staff[$index % 10]->id);
                if ($index < 20) {
                    $customer->notes()->create(['user_id' => $admin->id, 'body' => 'DEMO: Prefers weekday follow-up about stock and service requirements.']);
                }
                $customers->push($customer);
            }
            $stages = PipelineStage::orderBy('position')->get();
            $leads = collect();
            for ($index = 0; $index < 300; $index++) {
                $customer = $customers[$index];
                $date = CarbonImmutable::parse($customer->created_at)->addDay()->min($reference->utc());
                $stage = $stages[$index % 7];
                $leads->push(Lead::factory()->create(['title' => 'DEMO '.($index < 200 ? 'Opportunity' : 'Lead').' '.($index + 1).' - '.$customer->company, 'customer_id' => $customer->id, 'assigned_to' => $staff[$index % 10]->id, 'created_by' => $managers[$index % 3]->id, 'pipeline_stage_id' => $stage->id, 'source' => $fake->randomElement(['Referral', 'Facebook', 'Trade fair', 'Website', 'Walk-in']), 'estimated_value' => $this->amount($fake->numberBetween(100000, 25000000)), 'expected_close_date' => $date->addDays(14)->toDateString(), 'notes' => 'DEMO: Contact details are on the linked customer. Follow up using the linked task.', 'converted_at' => $stage->slug === 'won' ? $date : null, 'created_at' => $date, 'updated_at' => $date]));
            }
            $won = $leads->filter(fn (Lead $lead): bool => $lead->stage->slug === 'won')->values();
            for ($index = 0; $index < 200; $index++) {
                $lead = $won[$index % $won->count()];
                $date = CarbonImmutable::parse($lead->created_at)->addDays(1 + $index % 20)->min($reference->utc());
                $voided = $index % 10 === 0;
                Sale::factory()->create(['customer_id' => $lead->customer_id, 'lead_id' => $lead->id, 'recorded_by' => $managers[$index % 3]->id, 'amount' => $this->amount($fake->numberBetween(100000, 25000000)), 'currency' => 'PHP', 'status' => $voided ? 'voided' : 'completed', 'closed_at' => $date, 'voided_at' => $voided ? $date : null, 'voided_by' => $voided ? $admin->id : null, 'void_reason' => $voided ? 'DEMO: Duplicate payment entry corrected.' : null, 'created_at' => $date, 'updated_at' => $date]);
            }
            for ($index = 0; $index < 250; $index++) {
                $future = $index >= 200;
                $date = $future ? $reference->addWeekdays(1 + intdiv($index - 200, 10)) : $start->addDays((int) floor($index * $start->diffInDays($reference) / 200));
                if ($date->isWeekend()) {
                    $date = $date->nextWeekday();
                }
                $date = $date->setHour(9);
                $eligible = $customers->filter(fn (Customer $customer): bool => $customer->created_at->lessThanOrEqualTo($date))->values();
                $customer = $eligible[$index % $eligible->count()];
                Appointment::factory()->create(['customer_id' => $customer->id, 'assigned_to' => $staff[$index % 10]->id, 'created_by' => $managers[$index % 3]->id, 'title' => 'DEMO '.$fake->randomElement(['Stock review', 'Service consultation', 'Product demonstration', 'Contract discussion']).' '.($index + 1), 'description' => 'DEMO: Review business requirements and next steps.', 'start_at' => $date->utc(), 'end_at' => $date->addHour()->utc(), 'status' => $future ? ($index % 2 ? 'confirmed' : 'scheduled') : ($index % 5 ? 'completed' : 'cancelled'), 'created_at' => $date->subDays(1)->utc()->min($reference->utc()), 'updated_at' => $date->utc()->min($reference->utc())]);
            }
            for ($index = 0; $index < 300; $index++) {
                $lead = $leads[$index];
                $date = CarbonImmutable::parse($lead->created_at)->min($reference->utc());
                $status = Task::STATUSES[($index + intdiv($index, 12)) % 4];
                $task = Task::factory()->create(['title' => 'DEMO '.$fake->randomElement(['Send quotation', 'Confirm delivery', 'Call customer', 'Prepare proposal', 'Review contract']).' '.($index + 1), 'description' => 'DEMO: Coordinate with '.$customers[$index]->company.' and record the outcome.', 'assigned_to' => $lead->assigned_to, 'created_by' => $managers[$index % 3]->id, 'customer_id' => $lead->customer_id, 'lead_id' => $lead->id, 'priority' => Task::PRIORITIES[intdiv($index, 4) % 4], 'status' => $status, 'due_date' => $index >= 280 ? $reference->addDays(7)->utc() : $date->addDays(3), 'completed_at' => $status === 'completed' ? $date->addDays(2)->min($reference->utc()) : null, 'created_at' => $date, 'updated_at' => $date]);
                if ($index < 30) {
                    $task->comments()->create(['user_id' => $task->assigned_to, 'comment' => 'DEMO: Customer contacted; requirements reviewed.']);
                }
                if ($index < 10) {
                    $staff[$index]->notifications()->create(['id' => (string) Str::uuid(), 'type' => TaskChangedNotification::class, 'data' => ['task_id' => $task->id, 'kind' => 'task_assigned']]);
                }
            }
            AutomationWorkflow::factory()->create(['name' => 'DEMO - Disabled lead follow-up example', 'created_by' => $admin->id, 'is_active' => false]);
            DB::table('audit_logs')->insert(['user_id' => $admin->id, 'action' => 'demo.seed.v1', 'created_at' => now()]);

            return $accounts;
        }));
        if (! Storage::disk('local')->put($credentialFile, json_encode(['database' => $database, 'reference_date' => $reference->toDateString(), 'start_date' => $start->toDateString(), 'accounts' => $accounts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Data was created but credentials could not be saved; do not reseed or overwrite accounts.');
        }
        $path = Storage::disk('local')->path($credentialFile);
        chmod($path, 0600);
        $this->command?->info('Created 1,564 primary demo records. Read account credentials privately from '.$path);
    }

    private function amount(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
