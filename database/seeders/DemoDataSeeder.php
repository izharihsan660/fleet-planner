<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\HighUsageFlag;
use App\Models\InspectionLog;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\BlockedBreakdownService;
use App\Services\MaintenanceTriggerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    private const PASSWORD = '123123';

    public function run(): void
    {
        $this->call([
            PlanningItemSeeder::class,
            SystemThresholdSeeder::class,
        ]);

        $today = CarbonImmutable::today();
        $sites = $this->sites();
        $users = $this->users($sites);
        $units = $this->units($sites);

        $this->logs($units, $users, $today);
        $this->specials($units, $users, $today);
    }

    private function sites(): array
    {
        $rows = [
            'balikpapan' => ['Site Balikpapan', 'Kalimantan Timur'],
            'samarinda' => ['Site Samarinda', 'Kalimantan Timur'],
            'jakarta' => ['Site Jakarta', 'DKI Jakarta'],
            'surabaya' => ['Site Surabaya', 'Jawa Timur'],
            'makassar' => ['Site Makassar', 'Sulawesi Selatan'],
            'medan' => ['Site Medan', 'Sumatera Utara'],
            'palembang' => ['Site Palembang', 'Sumatera Selatan'],
            'pekanbaru' => ['Site Pekanbaru', 'Riau'],
            'banjarmasin' => ['Site Banjarmasin', 'Kalimantan Selatan'],
            'pontianak' => ['Site Pontianak', 'Kalimantan Barat'],
            'manado' => ['Site Manado', 'Sulawesi Utara'],
            'denpasar' => ['Site Denpasar', 'Bali'],
            'semarang' => ['Site Semarang', 'Jawa Tengah'],
            'bandung' => ['Site Bandung', 'Jawa Barat'],
            'yogyakarta' => ['Site Yogyakarta', 'DI Yogyakarta'],
        ];

        foreach ($rows as $slug => [$name, $region]) {
            $sites[$slug] = Site::query()->updateOrCreate(['name' => $name], ['region' => $region]);
        }

        return $sites ?? [];
    }

    private function users(array $sites): array
    {
        User::query()->whereIn('email', [
            'superadmin@example.com', 'planner.ho@example.com', 'admin.site@example.com',
            'spv.ops@example.com', 'logistik@example.com', 'mekanik@example.com',
        ])->update(['password' => Hash::make(self::PASSWORD)]);

        User::query()->updateOrCreate(
            ['email' => 'superadmin@example.com'],
            ['name' => 'Superadmin Demo', 'password' => Hash::make(self::PASSWORD), 'role' => UserRole::Superadmin, 'site_id' => null],
        );
        User::query()->updateOrCreate(
            ['email' => 'planner.ho@example.com'],
            ['name' => 'Planner HO Demo', 'password' => Hash::make(self::PASSWORD), 'role' => UserRole::PlannerHo, 'site_id' => null],
        );
        User::query()->updateOrCreate(
            ['email' => 'spv.ops@example.com'],
            ['name' => 'SPV Ops Demo', 'password' => Hash::make(self::PASSWORD), 'role' => UserRole::SpvOps, 'site_id' => null],
        );
        User::query()->updateOrCreate(
            ['email' => 'logistik@example.com'],
            ['name' => 'Logistik Demo', 'password' => Hash::make(self::PASSWORD), 'role' => UserRole::Logistik, 'site_id' => null],
        );

        foreach ($sites as $slug => $site) {
            $label = Str::of($slug)->replace('-', ' ')->headline()->toString();
            $users[$slug]['admin'] = User::query()->updateOrCreate(
                ['email' => "admin.{$slug}@example.com"],
                ['name' => "Admin {$label}", 'password' => Hash::make(self::PASSWORD), 'role' => UserRole::AdminSite, 'site_id' => $site->id],
            );
            $users[$slug]['mechanic'] = User::query()->updateOrCreate(
                ['email' => "mekanik.{$slug}@example.com"],
                ['name' => "Mekanik {$label}", 'password' => Hash::make(self::PASSWORD), 'role' => UserRole::Mekanik, 'site_id' => $site->id],
            );
        }

        return $users ?? [];
    }

    private function units(array $sites): array
    {
        $rows = [
            ['BPN-001', 'balikpapan', 48200, 78], ['BPN-002', 'balikpapan', 76300, 92], ['BPN-003', 'balikpapan', 128400, 105],
            ['SMR-001', 'samarinda', 91800, 260], ['SMR-002', 'samarinda', 43200, 69],
            ['JKT-001', 'jakarta', 110500, 88], ['JKT-002', 'jakarta', 35500, 54], ['JKT-003', 'jakarta', 84200, 73],
            ['SBY-001', 'surabaya', 67800, 82], ['SBY-002', 'surabaya', 119300, 97],
            ['MKS-001', 'makassar', 96400, 112], ['MKS-002', 'makassar', 40100, 58],
            ['MDN-001', 'medan', 102200, 94], ['MDN-002', 'medan', 28700, 47],
            ['PLM-001', 'palembang', 73400, 86], ['PLM-002', 'palembang', 51600, 72],
            ['PKU-001', 'pekanbaru', 88400, 240], ['PKU-002', 'pekanbaru', 36200, 63],
            ['BJM-001', 'banjarmasin', 79400, 91], ['BJM-002', 'banjarmasin', 45200, 70],
            ['PTK-001', 'pontianak', 98600, 225], ['PTK-002', 'pontianak', 57600, 76],
            ['MND-001', 'manado', 69400, 83], ['MND-002', 'manado', 33300, 51],
            ['DPS-001', 'denpasar', 61200, null], ['DPS-002', 'denpasar', 47800, 65],
            ['SMG-001', 'semarang', 72400, 80], ['SMG-002', 'semarang', 52100, 74],
            ['BDG-001', 'bandung', 46300, 62], ['BDG-002', 'bandung', 83300, 89],
            ['YGY-001', 'yogyakarta', 54800, 68], ['YGY-002', 'yogyakarta', 39200, 57], ['YGY-003', 'yogyakarta', 91500, 93],
        ];

        foreach ($rows as [$plate, $site, $odo, $avg]) {
            $units[$plate] = Unit::query()->updateOrCreate(
                ['current_plate' => $plate],
                ['site_id' => $sites[$site]->id, 'customer' => 'PT Demo UAT', 'type' => 'Operasional', 'brand' => 'Toyota/Hino/Isuzu', 'year' => 2022, 'current_odo' => $odo, 'avg_km_per_day' => $avg, 'status' => 'active'],
            )->refresh();
        }

        return $units ?? [];
    }

    private function logs(array $units, array $users, CarbonImmutable $today): void
    {
        foreach ($units as $plate => $unit) {
            InspectionLog::query()->where('unit_id', $unit->id)->delete();
            $slug = $this->slug($unit);
            $days = $plate === 'DPS-001' ? 2 : 15;
            $avg = (int) ($unit->avg_km_per_day ?? 60);
            $start = max(1000, $unit->current_odo - ($avg * ($days - 1)));

            for ($i = 0; $i < $days; $i++) {
                InspectionLog::query()->create([
                    'unit_id' => $unit->id,
                    'mechanic_id' => $users[$slug]['mechanic']->id,
                    'inspection_date' => $today->subDays(($days - 1) - $i)->toDateString(),
                    'odometer' => $start + ($avg * $i) + (($i % 3) * 4),
                ]);
            }
        }
    }

    private function specials(array $units, array $users, CarbonImmutable $today): void
    {
        $admin = fn (Unit $unit): User => $users[$this->slug($unit)]['admin'];

        foreach (['BPN-001', 'SMR-001'] as $plate) {
            $this->dueSoon($units[$plate], 'PM Check / Reguler Services', $today);
            app(MaintenanceTriggerService::class)->checkAndTrigger($units[$plate]->refresh());
        }

        $this->previewPlanning($units['BPN-002'], 'Service A', $today->addDays(24));
        $this->previewPlanning($units['BJM-002'], 'Service A', $today->addDays(12));

        $this->highUsage($units['SMR-001'], $today);
        $this->highUsage($units['PKU-001'], $today, true, $admin($units['PKU-001']));

        $item = $this->woItem($units['JKT-001'], 'Service B', 'in_progress', $admin($units['JKT-001']), $today);
        app(BlockedBreakdownService::class)->markBreakdown($units['JKT-001']->refresh(), $admin($units['JKT-001']), 'Demo UAT: unit breakdown dan WO item freeze.');

        $blocked = $this->woItem($units['MKS-001'], 'Accu', 'on_hold', $admin($units['MKS-001']), $today);
        app(BlockedBreakdownService::class)->markBlocked($blocked, $admin($units['MKS-001']), 'Demo UAT: sparepart belum tersedia.');

        $complete = $this->woItem($units['SBY-001'], 'Greasing', 'complete', $admin($units['SBY-001']), $today);
        $complete->update(['action' => 'completed', 'completed_odo' => $units['SBY-001']->current_odo, 'completed_date' => $today->subDay(), 'approved_by' => $admin($units['SBY-001'])->id, 'approved_at' => now()->subDay()]);
        $complete->workOrder()->update(['status' => 'complete', 'approved_by' => $admin($units['SBY-001'])->id, 'approved_at' => now()->subDay()]);

        $overduePlanning = $this->dueSoon($units['MDN-001'], 'Wiper Blade', $today->subDays(14));
        $overduePlanning->update(['next_due_km' => $units['MDN-001']->current_odo - 100, 'next_due_date' => $today->subDays(3)]);
        $this->woItem($units['MDN-001'], 'Wiper Blade', 'overdue', $admin($units['MDN-001']), $today);
    }

    private function dueSoon(Unit $unit, string $name, CarbonImmutable $today): UnitPlanning
    {
        $item = PlanningItem::query()->where('name', $name)->firstOrFail();

        return UnitPlanning::query()->updateOrCreate(
            ['unit_id' => $unit->id, 'planning_item_id' => $item->id],
            ['last_done_km' => $unit->current_odo - $item->interval_km + 350, 'last_done_date' => $today->subDays($item->interval_days - 4), 'next_due_km' => $unit->current_odo + 350, 'next_due_date' => $today->addDays(4), 'freeze_start' => null],
        );
    }

    private function previewPlanning(Unit $unit, string $name, CarbonImmutable $dueDate): UnitPlanning
    {
        $item = PlanningItem::query()->where('name', $name)->firstOrFail();

        return UnitPlanning::query()->updateOrCreate(
            ['unit_id' => $unit->id, 'planning_item_id' => $item->id],
            [
                'last_done_km' => max(0, $unit->current_odo - $item->interval_km),
                'last_done_date' => $dueDate->subDays($item->interval_days)->toDateString(),
                'next_due_km' => $unit->current_odo + 5000,
                'next_due_date' => $dueDate->toDateString(),
                'freeze_start' => null,
            ],
        );
    }

    private function highUsage(Unit $unit, CarbonImmutable $today, bool $windowTwo = false, ?User $actor = null): void
    {
        $planning = $this->dueSoon($unit, 'Ban', $today->addDays(10));
        HighUsageFlag::query()->updateOrCreate(
            ['unit_planning_id' => $planning->id, 'resolved_at' => null],
            [
                'unit_id' => $unit->id,
                'planning_item_id' => $planning->planning_item_id,
                'avg_km_per_day' => $unit->avg_km_per_day,
                'estimated_due_days' => 2,
                'flagged_at' => $windowTwo ? now()->subDays(6) : now(),
                'action_taken' => $windowTwo ? 'deferred' : null,
                'action_taken_at' => $windowTwo ? now()->subDays(6) : null,
                'action_taken_by' => $windowTwo ? $actor?->id : null,
            ],
        );
    }

    private function woItem(Unit $unit, string $name, string $status, User $actor, CarbonImmutable $today): WorkOrderItem
    {
        $item = PlanningItem::query()->where('name', $name)->firstOrFail();
        $planning = UnitPlanning::query()->firstOrCreate(
            ['unit_id' => $unit->id, 'planning_item_id' => $item->id],
            ['last_done_km' => max(0, $unit->current_odo - $item->interval_km), 'last_done_date' => $today->subDays($item->interval_days), 'next_due_km' => $unit->current_odo + 500, 'next_due_date' => $today->addDays(5)],
        );
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $unit->site_id, 'trigger_type' => 'normal', 'status' => $status === 'complete' ? 'complete' : 'open', 'submitted_by' => $actor->id, 'notes' => 'Demo UAT generated work order.']);

        return WorkOrderItem::query()->create(['work_order_id' => $workOrder->id, 'unit_planning_id' => $planning->id, 'planning_item_id' => $item->id, 'status' => $status, 'submitted_by' => $actor->id]);
    }

    private function slug(Unit $unit): string
    {
        return Str::of($unit->site->name)->after('Site ')->lower()->replace(' ', '-')->toString();
    }
}
