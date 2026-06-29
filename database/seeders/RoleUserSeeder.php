<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleUserSeeder extends Seeder
{
    /**
     * Seed role-based example users.
     */
    public function run(): void
    {
        $site = Site::query()->firstOrCreate(
            ['name' => 'Site Balikpapan'],
            ['region' => 'Kalimantan Timur'],
        );

        $users = [
            ['name' => 'Superadmin', 'email' => 'superadmin@example.com', 'role' => UserRole::Superadmin, 'site_id' => null],
            ['name' => 'Planner HO', 'email' => 'planner.ho@example.com', 'role' => UserRole::PlannerHo, 'site_id' => null],
            ['name' => 'Admin Site', 'email' => 'admin.site@example.com', 'role' => UserRole::AdminSite, 'site_id' => $site->id],
            ['name' => 'SPV Ops', 'email' => 'spv.ops@example.com', 'role' => UserRole::SpvOps, 'site_id' => null],
            ['name' => 'Logistik', 'email' => 'logistik@example.com', 'role' => UserRole::Logistik, 'site_id' => null],
            ['name' => 'Mekanik', 'email' => 'mekanik@example.com', 'role' => UserRole::Mekanik, 'site_id' => $site->id],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make('password'),
                    'role' => $user['role'],
                    'site_id' => $user['site_id'],
                ],
            );
        }
    }
}
