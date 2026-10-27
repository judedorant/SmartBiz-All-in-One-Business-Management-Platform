<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_role_seeding_does_not_create_demo_users(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_production_environment_cannot_create_local_accounts(): void
    {
        $this->app->instance('env', 'production');

        $this->artisan('smartbiz:local-accounts')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_accounts_use_generated_private_credentials_and_cannot_be_overwritten(): void
    {
        $directory = sys_get_temp_dir().'/smartbiz-test-'.Str::uuid();
        mkdir($directory, 0700);
        $this->app->useStoragePath($directory);
        $this->app->instance('env', 'local');

        try {
            $this->artisan('smartbiz:local-accounts')->assertSuccessful();

            $path = $directory.'/app/private/local-accounts.json';
            $accounts = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(3, $accounts);
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertDatabaseCount('users', 3);
            foreach ($accounts as $account) {
                $user = User::where('email', $account['email'])->firstOrFail();
                $this->assertTrue(Hash::check($account['password'], $user->password));
                $this->assertTrue($user->hasRole($account['role']));
                $this->assertSame(24, strlen($account['password']));
            }

            $this->artisan('smartbiz:local-accounts')->assertFailed();

            $this->assertDatabaseCount('users', 3);
            $this->assertSame($accounts, json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
