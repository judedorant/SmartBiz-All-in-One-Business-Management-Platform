<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateLocalAccounts extends Command
{
    protected $signature = 'smartbiz:local-accounts';

    protected $description = 'Create local-only Admin, Manager and Staff accounts with random passwords in a private file';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This command is only available in the local environment.');

            return self::FAILURE;
        }
        $path = storage_path('app/private/local-accounts.json');
        if (file_exists($path) || User::whereIn('email', ['admin@smartbiz.test', 'manager@smartbiz.test', 'staff@smartbiz.test'])->exists()) {
            $this->error('Local accounts or credentials already exist. Existing users will not be overwritten.');

            return self::FAILURE;
        }
        $accounts = [];
        foreach (['Admin', 'Manager', 'Staff'] as $role) {
            $accounts[] = ['role' => $role, 'email' => strtolower($role).'@smartbiz.test', 'password' => Str::password(24)];
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $handle = fopen($path, 'x');
        if ($handle === false) {
            $this->error('Unable to create credentials file.');

            return self::FAILURE;
        }
        chmod($path, 0600);
        try {
            fwrite($handle, json_encode($accounts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            DB::transaction(function () use ($accounts): void {
                $this->callSilent('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
                foreach ($accounts as $account) {
                    User::create(['name' => 'Local '.$account['role'], 'email' => $account['email'], 'password' => $account['password'], 'status' => 'active'])->assignRole($account['role']);
                }
            });
        } catch (\Throwable $exception) {
            unlink($path);
            throw $exception;
        } finally {
            fclose($handle);
        }
        $this->info('Created local accounts. Read credentials privately from '.$path.'. Do not commit or share this file.');

        return self::SUCCESS;
    }
}
