<?php

namespace App\Console\Commands;

use Database\Seeders\SmartBizDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupDemoDatabase extends Command
{
    protected $signature = 'smartbiz:demo-setup {--database=smartbiz_demo : Dedicated demo database name}';

    protected $description = 'Create/migrate and safely seed a separate local MySQL demo database without changing .env';

    public function handle(): int
    {
        $database = (string) $this->option('database');
        $source = config('database.connections.'.config('database.default'));
        if (! app()->environment(['local', 'testing']) || ($source['driver'] ?? '') !== 'mysql' || ! in_array($source['host'], ['127.0.0.1', 'localhost', '::1'], true) || ! preg_match('/^smartbiz_demo(?:_[a-z0-9]+)?$/D', $database)) {
            $this->error('Requires local/testing, local MySQL, and a dedicated smartbiz_demo database name (optionally suffixed).');

            return self::FAILURE;
        }
        if (app()->configurationIsCached() || ! empty($source['url'])) {
            $this->error('Use uncached configuration without a DB_URL override for demo setup.');

            return self::FAILURE;
        }
        DB::statement('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.demo' => [...$source, 'database' => $database], 'database.default' => 'demo', 'cache.default' => 'array', 'mail.default' => 'log', 'queue.default' => 'database']);
        DB::purge('demo');
        $this->info('Demo target: '.$database.'. Your .env and original database are unchanged.');
        if ($this->call('migrate', ['--database' => 'demo', '--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('db:seed', ['--class' => SmartBizDemoSeeder::class, '--force' => true, '--no-interaction' => true]);
    }
}
