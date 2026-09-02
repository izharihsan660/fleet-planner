<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aplikasi menyimpan "unit ini di site mana" di dua kolom: papan Perintah Kerja
 * dan Proyeksi membaca units.site_id, sedangkan Daftar Kerja, Ringkasan,
 * Antrian Approval, dan Laporan membaca work_orders.site_id. Memindahkan unit
 * tanpa menyamakan keduanya membuat unit itu muncul di dua site sekaligus,
 * tergantung halaman mana yang dibuka.
 */
class UnitSiteRelocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_site_from_master_data_moves_active_work_orders_and_releases_the_mechanic(): void
    {
        [$superadmin, $oldSite, $newSite] = $this->sites();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $oldSite->id]);
        [$unit, $workOrder, $item] = $this->scheduledWorkOrder($oldSite, $mechanic);

        $this->actingAs($superadmin)
            ->patch(route('units.update', $unit), $this->unitPayload($unit, $newSite->id))
            ->assertRedirect(route('units.index'));

        $this->assertSame($newSite->id, $unit->refresh()->site_id);
        $this->assertSame($newSite->id, $workOrder->refresh()->site_id, 'WO aktif ikut pindah site.');
        $this->assertNull($workOrder->assigned_mechanic_id, 'Mekanik site lama dilepas.');
        $this->assertNull($item->refresh()->scheduled_date, 'Jadwal lama tidak dibawa ke site baru.');
        $this->assertSame('on_hold', $item->status, 'Item kembali menunggu penugasan planner site baru.');
        $this->assertSame('open', $workOrder->status, 'Status WO ikut dihitung ulang.');
    }

    public function test_moved_unit_is_no_longer_split_between_two_sites(): void
    {
        [$superadmin, $oldSite, $newSite] = $this->sites();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $oldSite->id]);
        [$unit, , $item] = $this->scheduledWorkOrder($oldSite, $mechanic);

        $this->actingAs($superadmin)
            ->patch(route('units.update', $unit), $this->unitPayload($unit, $newSite->id))
            ->assertRedirect(route('units.index'));

        // Daftar Kerja menyaring lewat work_orders.site_id ...
        $workListItems = collect($this->actingAs($superadmin)
            ->get(route('work-list.index', ['site_id' => $newSite->id]))
            ->assertOk()
            ->inertiaProps('items'));

        // ... sedangkan papan Perintah Kerja menyaring lewat units.site_id.
        $boardItems = collect($this->actingAs($superadmin)
            ->get(route('work-orders.index', ['site_id' => $newSite->id]))
            ->assertOk()
            ->inertiaProps('boardColumns.open.data'));

        $this->assertSame([$item->id], $workListItems->pluck('id')->all());
        $this->assertSame([$item->id], $boardItems->pluck('id')->all());

        $this->assertSame(
            [],
            collect($this->actingAs($superadmin)
                ->get(route('work-list.index', ['site_id' => $oldSite->id]))
                ->inertiaProps('items'))->pluck('id')->all(),
            'Item tidak lagi tertinggal di site lama.',
        );
    }

    public function test_finished_work_orders_stay_at_the_site_where_they_happened(): void
    {
        [$superadmin, $oldSite, $newSite] = $this->sites();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $oldSite->id]);
        [$unit, $workOrder] = $this->scheduledWorkOrder($oldSite, $mechanic);
        $workOrder->items()->update(['status' => 'complete']);
        $workOrder->update(['status' => 'complete']);

        $this->actingAs($superadmin)
            ->patch(route('units.update', $unit), $this->unitPayload($unit, $newSite->id))
            ->assertRedirect(route('units.index'));

        $this->assertSame(
            $oldSite->id,
            $workOrder->refresh()->site_id,
            'Riwayat pekerjaan tetap tercatat di site tempat kejadiannya, supaya rekap bulanan tidak berubah surut.',
        );
        $this->assertSame($mechanic->id, $workOrder->assigned_mechanic_id);
    }

    public function test_approved_site_transfer_also_releases_the_mechanic(): void
    {
        [, $oldSite, $newSite, $region] = $this->sites();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $oldSite->id]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $region->id, 'site_id' => null]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        [$unit, $workOrder, $item] = $this->scheduledWorkOrder($oldSite, $mechanic);

        $this->actingAs($planner)
            ->post(route('units.site-transfers.store', $unit), ['to_site_id' => $newSite->id, 'reason' => 'Unit dipindah operasional.'])
            ->assertRedirect();

        $this->actingAs($spv)
            ->post(route('unit-site-transfers.approve', $unit->siteTransfers()->firstOrFail()), ['decision_reason' => 'Disetujui.'])
            ->assertRedirect();

        $this->assertSame($newSite->id, $workOrder->refresh()->site_id);
        $this->assertNull($workOrder->assigned_mechanic_id, 'Jalur Pindah Site pun melepas mekanik site lama.');
        $this->assertNull($item->refresh()->scheduled_date);
    }

    public function test_updating_a_unit_without_moving_it_leaves_the_assignment_alone(): void
    {
        [$superadmin, $oldSite] = $this->sites();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $oldSite->id]);
        [$unit, $workOrder, $item] = $this->scheduledWorkOrder($oldSite, $mechanic);

        $this->actingAs($superadmin)
            ->patch(route('units.update', $unit), $this->unitPayload($unit, $oldSite->id) + ['customer' => 'Customer Baru'])
            ->assertRedirect(route('units.index'));

        $this->assertSame($mechanic->id, $workOrder->refresh()->assigned_mechanic_id);
        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertNotNull($item->scheduled_date);
    }

    public function test_repair_command_moves_work_orders_left_behind_before_the_fix(): void
    {
        [, $oldSite, $newSite] = $this->sites();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $oldSite->id]);
        [$unit, $workOrder, $item] = $this->scheduledWorkOrder($oldSite, $mechanic);

        // Mass update melewati observer, jadi ini mereproduksi kerusakan lama:
        // unit sudah di site baru, WO-nya tertinggal di site lama.
        Unit::query()->whereKey($unit->id)->update(['site_id' => $newSite->id]);

        $this->artisan('fleet:sync-work-order-sites --dry-run')
            ->expectsOutput('1 work order akan dipindah ke site unitnya. Tidak ada data yang diubah.')
            ->assertSuccessful();

        $this->assertSame($oldSite->id, $workOrder->refresh()->site_id);

        $this->artisan('fleet:sync-work-order-sites --execute')
            ->expectsOutput('1 work order berhasil dipindah ke site unitnya.')
            ->assertSuccessful();

        $this->assertSame($newSite->id, $workOrder->refresh()->site_id);
        $this->assertNull($workOrder->assigned_mechanic_id);
        $this->assertSame('on_hold', $item->refresh()->status);
        $this->assertNull($item->scheduled_date);

        $this->artisan('fleet:sync-work-order-sites --execute')
            ->expectsOutput('Tidak ada work order aktif yang site-nya berbeda dari unitnya.')
            ->assertSuccessful();
    }

    /**
     * @return array{0: User, 1: Site, 2: Site, 3: Region}
     */
    private function sites(): array
    {
        $region = Region::query()->create(['name' => 'Kalimantan']);
        $oldSite = Site::query()->create(['name' => 'Site Lama', 'region' => 'Kalimantan', 'region_id' => $region->id]);
        $newSite = Site::query()->create(['name' => 'Site Baru', 'region' => 'Kalimantan', 'region_id' => $region->id]);
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);

        return [$superadmin, $oldSite, $newSite, $region];
    }

    /**
     * @return array{0: Unit, 1: WorkOrder, 2: WorkOrderItem}
     */
    private function scheduledWorkOrder(Site $site, User $mechanic): array
    {
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => 'KT 8404 YR',
            'type' => 'Truck',
            'brand' => 'Hino',
            'vehicle_category' => 'truk_ringan',
            'year' => 2024,
            'current_odo' => 10000,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 5000, 'interval_days' => 90]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 5000,
            'last_done_date' => today()->subDays(80)->toDateString(),
            'next_due_km' => 50000,
            'next_due_date' => today()->addDays(5)->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'in_progress',
            'assigned_mechanic_id' => $mechanic->id,
            'approved_at' => now(),
        ]);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'in_progress',
            'scheduled_date' => today()->toDateString(),
            'approved_at' => now(),
        ]);

        return [$unit, $workOrder, $item];
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(Unit $unit, int $siteId): array
    {
        return [
            'site_id' => $siteId,
            'customer' => $unit->customer,
            'current_plate' => $unit->current_plate,
            'type' => $unit->type,
            'brand' => $unit->brand,
            'vehicle_category' => 'truk_ringan',
            'year' => $unit->year,
            'current_odo' => $unit->current_odo,
            'status' => 'active',
        ];
    }
}
