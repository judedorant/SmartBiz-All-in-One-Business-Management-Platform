<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Qa2026Audit;
use Carbon\CarbonImmutable;
use Database\Seeders\PipelineStageSeeder;
use Database\Seeders\Qa2026ScenarioSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class Qa2026ScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([RolesAndPermissionsSeeder::class, PipelineStageSeeder::class]);
        User::factory()->create()->assignRole('Staff');
    }

    public function test_fixed_scenario_preserves_existing_rows_is_repeatable_and_executes_real_local_automations(): void
    {
        $audit = app(Qa2026Audit::class);
        $before = $audit->snapshot(DB::getDefaultConnection());
        $this->seed(Qa2026ScenarioSeeder::class);
        $manifestText = Storage::disk('local')->get(Qa2026ScenarioSeeder::MANIFEST);
        $manifest = json_decode($manifestText, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(100, $manifest['sales']);
        $this->assertCount(20, $manifest['customers']);
        $this->assertCount(50, $manifest['automation_logs']);
        $this->assertTrue(Hash::check($manifest['account']['password'], User::findOrFail($manifest['account']['id'])->password));
        $this->assertSame([], $audit->preserved($before, $audit->snapshot(DB::getDefaultConnection()), true));
        $this->assertSame(50, DB::table('automation_logs')->where('execution_status', 'succeeded')->count());
        $this->assertSame(50, DB::table('tasks')->whereNotNull('automation_log_id')->count());
        $this->assertSame(0, DB::table('automation_workflows')->where('is_active', true)->count());
        $this->assertSame(0, DB::table('sales')->where('status', 'completed')->where('closed_at', '>', CarbonImmutable::parse(Qa2026ScenarioSeeder::REFERENCE)->utc())->count());
        $after = $audit->snapshot(DB::getDefaultConnection());
        $this->seed(Qa2026ScenarioSeeder::class);
        $this->assertSame($manifestText, Storage::disk('local')->get(Qa2026ScenarioSeeder::MANIFEST));
        $this->assertSame([], $audit->preserved($after, $audit->snapshot(DB::getDefaultConnection()), false));
    }

    public function test_production_and_wrong_database_are_refused_before_writes(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        app(Qa2026ScenarioSeeder::class)->run();
    }

    public function test_existing_qa_account_is_not_overwritten_without_a_marker(): void
    {
        $user = User::factory()->create(['email' => 'qa2026.admin@example.test']);
        $password = $user->password;
        try {
            app(Qa2026ScenarioSeeder::class)->run();
            $this->fail('Duplicate QA account must be refused.');
        } catch (RuntimeException) {
            $this->assertSame($password, $user->fresh()->password);
        }
    }

    public function test_preservation_detects_changed_or_missing_rows_and_unexpected_original_additions(): void
    {
        $audit = app(Qa2026Audit::class);
        $before = $audit->snapshot(DB::getDefaultConnection());
        $user = User::firstOrFail();
        $user->forceFill(['remember_token' => 'qa-rotated-token'])->save();
        $this->assertSame([], $audit->preserved($before, $audit->snapshot(DB::getDefaultConnection()), true));
        $user->update(['name' => 'Changed QA identity']);
        $this->assertNotEmpty($audit->preserved($before, $audit->snapshot(DB::getDefaultConnection()), true));
        $current = $audit->snapshot(DB::getDefaultConnection());
        User::factory()->create();
        $this->assertNotEmpty($audit->preserved($current, $audit->snapshot(DB::getDefaultConnection()), false));
        $this->assertSame([], $audit->preserved($current, $audit->snapshot(DB::getDefaultConnection()), true));
    }
}
