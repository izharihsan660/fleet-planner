<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\HighUsageFlag;
use App\Models\InspectionLog;
use App\Models\PlanningItem;
use App\Models\Region;
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
        $regions = $this->regions();
        $sites = $this->sites($regions);
        $users = $this->users($sites);
        $units = $this->units($sites);

        $this->logs($units, $users, $today);
        $this->specials($units, $users, $today);
    }

    private function regions(): array
    {
        foreach (['kalimantan' => 'Kalimantan', 'sulawesi' => 'Sulawesi'] as $slug => $name) {
            $regions[$slug] = Region::query()->updateOrCreate(['name' => $name]);
        }

        return $regions ?? [];
    }

    private function sites(array $regions): array
    {
        $rows = [
            'adaro' => ['ADARO', 'Kalimantan Selatan', 'kalimantan'],
            'bpn' => ['BPN', 'Kalimantan Timur', 'kalimantan'],
            'gorontalo' => ['GORONTALO', 'Gorontalo', 'sulawesi'],
            'kendari' => ['KENDARI', 'Sulawesi Tenggara', 'sulawesi'],
            'loa-kulu' => ['LOA KULU', 'Kalimantan Timur', 'kalimantan'],
            'loajanan' => ['LOAJANAN', 'Kalimantan Timur', 'kalimantan'],
            'loreh' => ['LOREH', 'Kalimantan Utara', 'kalimantan'],
            'm-lawa' => ['M. LAWA', 'Kalimantan Timur', 'kalimantan'],
            'makassar' => ['MAKASSAR', 'Sulawesi Selatan', 'sulawesi'],
            'manado' => ['MANADO', 'Sulawesi Utara', 'sulawesi'],
            'mks' => ['MKS', 'Sulawesi Selatan', 'sulawesi'],
            'sanga-sanga' => ['SANGA SANGA', 'Kalimantan Timur', 'kalimantan'],
            'sangatta' => ['SANGATTA', 'Kalimantan Timur', 'kalimantan'],
            'smd' => ['SMD', 'Kalimantan Timur', 'kalimantan'],
            'soroako' => ['SOROAKO', 'Sulawesi Selatan', 'sulawesi'],
            'tabang' => ['TABANG', 'Kalimantan Timur', 'kalimantan'],
            'tgr' => ['TGR', 'Banten', 'kalimantan'],
            'tj-redeb' => ['TJ. REDEB', 'Kalimantan Timur', 'kalimantan'],
        ];

        Site::query()->whereNotIn('name', array_column($rows, 0))->delete();

        foreach ($rows as $slug => [$name, $region, $regionSlug]) {
            $sites[$slug] = Site::query()->updateOrCreate(['name' => $name], ['region' => $region, 'region_id' => $regions[$regionSlug]->id]);
        }

        return $sites ?? [];
    }

    private function users(array $sites): array
    {
        User::query()->where('role', UserRole::PlannerArea)->where('email', 'like', 'planner.%@example.com')->delete();

        User::query()->updateOrCreate(
            ['email' => 'superadmin@example.com'],
            ['name' => 'Superadmin Demo', 'password' => Hash::make(self::PASSWORD), 'role' => UserRole::Superadmin, 'site_id' => null, 'region_id' => null],
        );
        User::query()->updateOrCreate(
            ['email' => 'spv_ho@example.com'],
            ['name' => 'Spv HO Demo', 'password' => Hash::make(self::PASSWORD), 'role' => UserRole::SpvHo, 'site_id' => null, 'region_id' => null],
        );

        foreach (Region::query()->whereIn('name', ['Kalimantan', 'Sulawesi'])->get() as $region) {
            $slug = Str::of($region->name)->lower()->toString();
            $users[$slug]['admin'] = User::query()->updateOrCreate(
                ['email' => "planner.{$slug}@example.com"],
                ['name' => "Planner {$region->name}", 'password' => Hash::make(self::PASSWORD), 'role' => UserRole::PlannerArea, 'site_id' => null, 'region_id' => $region->id],
            );
        }

        foreach ($sites as $slug => $site) {
            $label = Str::of($slug)->replace('-', ' ')->headline()->toString();
            $users[$slug]['admin'] = User::query()->where('role', UserRole::PlannerArea)->where('region_id', $site->region_id)->firstOrFail();
            $users[$slug]['mechanic'] = User::query()->updateOrCreate(
                ['email' => "mekanik.{$slug}@example.com"],
                ['name' => "Mekanik {$label}", 'password' => Hash::make(self::PASSWORD), 'role' => UserRole::Mekanik, 'site_id' => $site->id, 'region_id' => null],
            );
        }

        return $users ?? [];
    }

    private function units(array $sites): array
    {
        $rows = [
            ['BPN-001', 'bpn', 48200, 78, 'pickup_suv'], ['BPN-002', 'bpn', 76300, 92, 'pickup_suv'], ['BPN-003', 'bpn', 128400, 105, 'truk_ringan'],
            ['SMD-001', 'smd', 91800, 260, 'truk_ringan'], ['SMD-002', 'smd', 43200, 69, 'pickup_suv'],
            ['MLW-001', 'm-lawa', 110500, 88, 'pickup_suv'], ['MLW-002', 'm-lawa', 35500, 54, 'pickup_suv'], ['MLW-003', 'm-lawa', 84200, 73, 'bus'],
            ['LOR-001', 'loreh', 67800, 82, 'pickup_suv'], ['LOR-002', 'loreh', 119300, 97, 'truk_ringan'],
            ['MKS-001', 'makassar', 96400, 112, 'pickup_suv'], ['MKS-002', 'makassar', 40100, 58, 'pickup_suv'],
            ['MND-001', 'manado', 102200, 94, 'pickup_suv'], ['MND-002', 'manado', 28700, 47, 'pickup_suv'],
            ['KDI-001', 'kendari', 73400, 86, 'pickup_suv'], ['KDI-002', 'kendari', 51600, 72, 'pickup_suv'],
            ['LOJ-001', 'loajanan', 88400, 240, 'truk_ringan'], ['LOJ-002', 'loajanan', 36200, 63, 'pickup_suv'],
            ['LOK-001', 'loa-kulu', 79400, 91, 'pickup_suv'], ['LOK-002', 'loa-kulu', 45200, 70, 'pickup_suv'],
            ['SSG-001', 'sanga-sanga', 98600, 225, 'truk_ringan'], ['SSG-002', 'sanga-sanga', 57600, 76, 'pickup_suv'],
            ['TGR-001', 'tgr', 69400, 83, 'pickup_suv'], ['TGR-002', 'tgr', 33300, 51, 'pickup_suv'],
            ['GTO-001', 'gorontalo', 61200, null, 'pickup_suv'], ['GTO-002', 'gorontalo', 47800, 65, 'pickup_suv'],
            ['TRD-001', 'tj-redeb', 72400, 80, 'pickup_suv'], ['TRD-002', 'tj-redeb', 52100, 74, 'pickup_suv'],
            ['ADR-001', 'adaro', 46300, 62, 'truk_ringan'], ['ADR-002', 'adaro', 83300, 89, 'pickup_suv'],
            ['TBN-001', 'tabang', 54800, 68, 'pickup_suv'], ['TBN-002', 'tabang', 39200, 57, 'pickup_suv'], ['SGT-001', 'sangatta', 91500, 93, 'pickup_suv'],
        ];

        foreach ($rows as [$plate, $site, $odo, $avg, $category]) {
            $units[$plate] = Unit::query()->updateOrCreate(
                ['current_plate' => $plate],
                ['site_id' => $sites[$site]->id, 'customer' => 'PT Demo UAT', 'type' => 'Operasional', 'brand' => 'Toyota/Hino/Isuzu', 'vehicle_category' => $category, 'year' => 2022, 'current_odo' => $odo, 'avg_km_per_day' => $avg, 'status' => 'active'],
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

        foreach (['BPN-001', 'SMD-001'] as $plate) {
            $this->dueSoon($units[$plate], 'PM Check / Reguler Services', $today);
            app(MaintenanceTriggerService::class)->checkAndTrigger($units[$plate]->refresh());
        }

        $this->previewPlanning($units['BPN-002'], 'Service A', $today->addDays(24));
        $this->previewPlanning($units['LOK-002'], 'Service A', $today->addDays(12));

        $this->highUsage($units['SMD-001'], $today);
        $this->highUsage($units['LOJ-001'], $today, true, $admin($units['LOJ-001']));

        $item = $this->woItem($units['MLW-001'], 'Service B', 'in_progress', $admin($units['MLW-001']), $today);
        app(BlockedBreakdownService::class)->markBreakdown($units['MLW-001']->refresh(), $admin($units['MLW-001']), 'Demo UAT: unit breakdown dan WO item freeze.');

        $blocked = $this->woItem($units['MKS-001'], 'Accu', 'on_hold', $admin($units['MKS-001']), $today);
        app(BlockedBreakdownService::class)->markBlocked($blocked, $admin($units['MKS-001']), 'Demo UAT: sparepart belum tersedia.');

        $complete = $this->woItem($units['LOR-001'], 'Greasing', 'complete', $admin($units['LOR-001']), $today);
        $complete->update(['action' => 'completed', 'completed_odo' => $units['LOR-001']->current_odo, 'completed_date' => $today->subDay(), 'approved_by' => $admin($units['LOR-001'])->id, 'approved_at' => now()->subDay()]);
        $complete->workOrder()->update(['status' => 'complete', 'approved_by' => $admin($units['LOR-001'])->id, 'approved_at' => now()->subDay()]);

        $overduePlanning = $this->dueSoon($units['MND-001'], 'Wiper Blade', $today->subDays(14));
        $overduePlanning->update(['next_due_km' => $units['MND-001']->current_odo - 100, 'next_due_date' => $today->subDays(3)]);
        $this->woItem($units['MND-001'], 'Wiper Blade', 'overdue', $admin($units['MND-001']), $today);
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
        $planning = $this->dueSoon($unit, 'Ban Depan', $today->addDays(10));
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
        return Str::of($unit->site->name)->lower()->replace(['.', ' '], ['', '-'])->toString();
    }
}
