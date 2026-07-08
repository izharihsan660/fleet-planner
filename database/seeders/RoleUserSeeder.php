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
        $sites = Site::query()->orderBy('name')->get();

        if ($sites->isEmpty()) {
            $sites = collect([
                Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur']),
            ]);
        }

        $users = [
            ['name' => 'Superadmin', 'email' => 'superadmin@example.com', 'role' => UserRole::Superadmin, 'site_id' => null],
            ['name' => 'Spv HO', 'email' => 'spv_ho@example.com', 'role' => UserRole::SpvHo, 'site_id' => null],
        ];

        foreach ($sites as $site) {
            $slug = str($site->name)->slug()->toString();
            $label = str($slug)->replace('-', ' ')->headline()->toString();

            $users[] = ['name' => "Planner {$label}", 'email' => "planner.{$slug}@example.com", 'role' => UserRole::PlannerArea, 'site_id' => $site->id];
            $users[] = ['name' => "Mekanik {$label}", 'email' => "mekanik.{$slug}@example.com", 'role' => UserRole::Mekanik, 'site_id' => $site->id];
        }

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
