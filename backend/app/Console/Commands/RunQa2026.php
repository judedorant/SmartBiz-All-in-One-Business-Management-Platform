<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Qa2026Audit;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Qa2026ScenarioSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class RunQa2026 extends Command
{
    protected $signature = 'smartbiz:qa-2026 {--seed : Append the identifiable fixed-date scenario once} {--currency=PHP : Currency to reconcile independently}';

    protected $description = 'Preserve existing records, append optional QA2026 fixtures, and independently validate reports in smartbiz_demo';

    public function handle(Qa2026Audit $audit): int
    {
        $source = config('database.connections.mysql');
        $currency = (string) $this->option('currency');
        if (! in_array($currency, config('smartbiz.currencies'), true)) {
            $this->error('Unsupported QA currency.');

            return self::FAILURE;
        }
        if (! app()->environment(['local', 'testing']) || ! in_array($source['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true) || ! empty($source['url']) || app()->configurationIsCached()) {
            $this->error('Requires uncached local/testing configuration and local MySQL without DB_URL.');

            return self::FAILURE;
        }
        config(['database.connections.qa_original' => [...$source, 'database' => 'smartbiz'], 'database.connections.qa_demo' => [...$source, 'database' => 'smartbiz_demo'], 'database.default' => 'qa_demo', 'cache.default' => 'array', 'mail.default' => 'log']);
        DB::purge('qa_demo');
        if (DB::connection()->getDatabaseName() !== 'smartbiz_demo') {
            return self::FAILURE;
        }
        $file = 'qa-2026-baseline.json';
        if (! Storage::disk('local')->exists($file)) {
            if (DB::table('audit_logs')->where('action', 'qa.2026.seed.v1')->exists()) {
                $this->error('QA scenario exists without its preservation baseline; manual review required.');

                return self::FAILURE;
            }
            Storage::disk('local')->put($file, json_encode(['original' => $audit->snapshot('qa_original'), 'demo' => $audit->snapshot('qa_demo')], JSON_THROW_ON_ERROR));
        }
        $baseline = json_decode(Storage::disk('local')->get($file), true, flags: JSON_THROW_ON_ERROR);
        if ($this->option('seed')) {
            $this->call('db:seed', ['--class' => Qa2026ScenarioSeeder::class, '--force' => true, '--no-interaction' => true]);
        }
        if (! Storage::disk('local')->exists(Qa2026ScenarioSeeder::MANIFEST)) {
            $this->error('No QA2026 scenario yet. Run this command with --seed to append it safely.');

            return self::FAILURE;
        }
        $previous = Carbon::getTestNow();
        $previousImmutable = CarbonImmutable::getTestNow();
        $reference = CarbonImmutable::parse(Qa2026ScenarioSeeder::REFERENCE);
        Carbon::setTestNow($reference);
        CarbonImmutable::setTestNow($reference);
        try {
            $result = $audit->validate(User::where('email', 'qa2026.admin@example.test')->firstOrFail(), $currency);
        } finally {
            Carbon::setTestNow($previous);
            CarbonImmutable::setTestNow($previousImmutable);
        }
        $result['preservation'] = ['original' => $audit->preserved($baseline['original'], $audit->snapshot('qa_original'), false), 'existing_demo' => $audit->preserved($baseline['demo'], $audit->snapshot('qa_demo'), true)];
        $result['authentication_user_row_deltas'] = array_values(array_filter($result['preservation']['existing_demo'], fn (string $delta): bool => str_starts_with($delta, 'users:')));
        $result['existing_credential_failures'] = [];
        $accounts = json_decode(Storage::disk('local')->get('demo-accounts-smartbiz_demo.json'), true, flags: JSON_THROW_ON_ERROR)['accounts'];
        foreach ($accounts as $account) {
            $user = User::where('email', $account['email'])->first();
            if (! $user || $user->status !== 'active' || ! $user->hasRole($account['role']) || ! Hash::check($account['password'], $user->password)) {
                $result['existing_credential_failures'][] = 'Existing account failed credential/role/status verification.';
            }
        }
        $result['authentication_note'] = 'Login/logout changes last-login timestamps and remember tokens. Original user-row digest deltas are retained above; business/authorization row preservation is checked separately. Existing passwords and role/status values were independently verified.';
        if (! isset($baseline['demo']['users']['stable_user_rows'])) {
            $result['preservation']['existing_demo'] = array_values(array_filter($result['preservation']['existing_demo'], fn (string $delta): bool => ! str_starts_with($delta, 'users:')));
        }
        $resultFile = $currency === 'PHP' ? 'qa-2026-validation.json' : 'qa-2026-validation-'.strtolower($currency).'.json';
        Storage::disk('local')->put($resultFile, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->line(json_encode(['database' => 'smartbiz_demo', 'reference' => $result['reference'], 'counts' => $result['counts'], 'summary' => $result['summary']['metrics'], 'checks' => $result['check_count'], 'failures' => $result['failures'], 'preservation' => $result['preservation'], 'authentication_user_row_deltas' => $result['authentication_user_row_deltas'], 'existing_credential_failures' => $result['existing_credential_failures']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $result['failures'] || $result['preservation']['original'] || $result['preservation']['existing_demo'] || $result['existing_credential_failures'] ? self::FAILURE : self::SUCCESS;
    }
}
