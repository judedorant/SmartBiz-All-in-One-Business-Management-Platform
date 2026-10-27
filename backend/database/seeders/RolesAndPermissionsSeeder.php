<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::transaction(function (): void {
            $staff = ['dashboard.view', 'customers.view_assigned', 'leads.update_assigned', 'tasks.manage_assigned', 'appointments.view_assigned'];
            $manager = [...$staff, 'customers.manage', 'leads.manage', 'tasks.assign', 'appointments.manage', 'reports.team', 'sales.manage'];
            $admin = [...$manager, 'users.manage', 'roles.manage', 'integrations.manage', 'automations.manage', 'reports.all'];
            foreach ($admin as $name) {
                Permission::findOrCreate($name, 'web');
            }
            foreach (['Admin' => $admin, 'Manager' => $manager, 'Staff' => $staff] as $name => $permissions) {
                Role::findOrCreate($name, 'web')->syncPermissions($permissions);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
