<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;

final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['orders.view', 'orders.refund', 'orders.cancel', 'orders.delete'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('support', 'web')->givePermissionTo('orders.view');
        Role::findOrCreate('finance_manager', 'web');
        Role::findOrCreate('administrator', 'web');
        foreach (['support', 'finance_manager', 'administrator'] as $index => $role) {
            $user = User::query()->firstOrCreate(['email' => $role.'@example.test'], ['name' => $role, 'password' => Hash::make('demo-only'), 'department' => 'finance']);
            $user->syncRoles([$role]);
        }
        Order::query()->firstOrCreate(['id' => 431], ['amount_minor' => 20000, 'currency' => 'PLN']);
    }
}
