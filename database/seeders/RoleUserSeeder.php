<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Region;
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
        $regions = collect(['Kalimantan', 'Sulawesi'])->mapWithKeys(
            fn (string $name): array => [$name => Region::query()->firstOrCreate(['name' => $name])],
        );

        $sites = Site::query()->orderBy('name')->get();

        if ($sites->isEmpty()) {
            $sites = collect([
                Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur', 'region_id' => $regions['Kalimantan']->id]),
            ]);
        }

        User::query()->where('role', UserRole::PlannerArea)->where('email', 'like', 'planner.%@example.com')->delete();

        $users = [
            ['name' => 'Superadmin', 'email' => 'superadmin@example.com', 'role' => UserRole::Superadmin, 'site_id' => null, 'region_id' => null],
            ['name' => 'Spv HO', 'email' => 'spv_ho@example.com', 'role' => UserRole::SpvHo, 'site_id' => null, 'region_id' => null],
            ['name' => 'Planner Kalimantan', 'email' => 'planner.kalimantan@example.com', 'role' => UserRole::PlannerArea, 'site_id' => null, 'region_id' => $regions['Kalimantan']->id],
            ['name' => 'Planner Sulawesi', 'email' => 'planner.sulawesi@example.com', 'role' => UserRole::PlannerArea, 'site_id' => null, 'region_id' => $regions['Sulawesi']->id],
        ];

        foreach ($sites as $site) {
            $slug = str($site->name)->slug()->toString();
            $label = str($slug)->replace('-', ' ')->headline()->toString();

            $users[] = ['name' => "Mekanik {$label}", 'email' => "mekanik.{$slug}@example.com", 'role' => UserRole::Mekanik, 'site_id' => $site->id, 'region_id' => null];
        }

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make('123123'),
                    'role' => $user['role'],
                    'site_id' => $user['site_id'],
                    'region_id' => $user['region_id'],
                ],
            );
        }
    }
}
