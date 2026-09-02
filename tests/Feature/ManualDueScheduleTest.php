<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\PlanningItemOverride;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jadwal yang ditetapkan SPV lewat Tunda atau High Usage Window 2 adalah
 * keputusan, bukan turunan interval. Interval hanyalah nilai default, jadi
 * perhitungan ulang — dari input KM harian maupun dari perubahan interval di
 * Master Data — tidak boleh menyetel baliknya.
 */
class ManualDueScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_km_input_keeps_a_due_km_that_was_brought_forward(): void
    {
        [$site, $unit, $planning, $planningItem] = $this->fleet(lastDoneKm: 10000, intervalKm: 5000);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        // High Usage Window 2: unit dipakai lebih berat, servis dimajukan ke
        // 13.000 — lebih cepat dari jadwal normal 15.000.
        $planning->update(['next_due_km' => 13000, 'due_manually_set' => true]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => today()->toDateString(),
            'odometer' => 10500,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame(
            13000,
            $planning->refresh()->next_due_km,
            'Pemajuan yang sudah disetujui tidak boleh dikembalikan ke jadwal interval.',
        );
    }

    public function test_km_input_still_repairs_a_due_km_behind_the_last_service(): void
    {
        [$site, $unit, $planning] = $this->fleet(lastDoneKm: 10000, intervalKm: 5000);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        // Nilai rusak: pengajuan Tunda dari Daftar Kerja menulis 0 saat unit
        // belum punya due KM.
        $planning->update(['next_due_km' => 0]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => today()->toDateString(),
            'odometer' => 10500,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame(15000, $planning->refresh()->next_due_km);
        $this->assertFalse($planning->due_manually_set);
    }

    public function test_approved_postpone_marks_the_due_as_manually_set(): void
    {
        [$site, $unit, $planning, $planningItem] = $this->fleet(lastDoneKm: 10000, intervalKm: 5000);
        $spv = User::factory()->create(['role' => UserRole::SpvHo, 'site_id' => null]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'open']);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'postpone',
            'action' => 'postpone',
            'new_due_km' => 13000,
            'new_due_date' => today()->addDays(20)->toDateString(),
        ]);

        $this->actingAs($spv)->post(route('work-orders.approve', $workOrder))->assertRedirect();

        $this->assertSame('postponed', $item->refresh()->status);
        $this->assertSame(13000, $planning->refresh()->next_due_km);
        $this->assertTrue($planning->due_manually_set);
    }

    public function test_changing_the_interval_leaves_manually_set_schedules_alone(): void
    {
        [$site, $unit, $manualPlanning, $planningItem] = $this->fleet(lastDoneKm: 10000, intervalKm: 5000);
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);

        // Unit kedua sudah otomatis dapat planning dari UnitObserver, jadi
        // cukup disamakan datanya dengan unit pertama.
        $otherUnit = $this->unit($site, 'KT 9999 ZZ');
        $normalPlanning = UnitPlanning::query()
            ->where('unit_id', $otherUnit->id)
            ->where('planning_item_id', $planningItem->id)
            ->firstOrFail();
        $normalPlanning->update([
            'last_done_km' => 10000,
            'last_done_date' => today()->subDays(30)->toDateString(),
            'next_due_km' => 15000,
            'next_due_date' => today()->addDays(60)->toDateString(),
        ]);

        $manualPlanning->update(['next_due_km' => 13000, 'due_manually_set' => true]);

        $this->actingAs($superadmin)->put(route('planning-items.update', $planningItem), [
            'name' => $planningItem->name,
            'interval_km' => 8000,
            'interval_days' => 120,
        ])->assertRedirect();

        $this->assertSame(18000, $normalPlanning->refresh()->next_due_km, 'Jadwal biasa ikut interval baru.');
        $this->assertSame(13000, $manualPlanning->refresh()->next_due_km, 'Keputusan SPV tidak ikut dihitung ulang.');
    }

    public function test_completion_returns_the_schedule_to_the_interval(): void
    {
        [$site, $unit, $planning, $planningItem] = $this->fleet(lastDoneKm: 10000, intervalKm: 5000);
        $planning->update(['next_due_km' => 13000, 'due_manually_set' => true]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal',
            'status' => 'in_progress', 'assigned_mechanic_id' => $mechanic->id,
        ]);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'in_progress',
            'scheduled_date' => today()->toDateString(),
        ]);

        $this->actingAs($mechanic)->post(route('work-orders.items.complete', [$workOrder, $item]), [
            'completed_odo' => 12800,
            'completed_date' => today()->toDateString(),
        ])->assertRedirect(route('mechanic.tasks'));

        $planning->refresh();

        $this->assertSame(17800, $planning->next_due_km, 'Siklus baru dihitung dari KM penyelesaian.');
        $this->assertFalse($planning->due_manually_set, 'Penetapan manual berakhir bersama siklusnya.');
    }

    public function test_interval_override_now_applies_without_waiting_for_a_km_input(): void
    {
        [$site, $unit, $planning, $planningItem] = $this->fleet(lastDoneKm: 10000, intervalKm: 5000);
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->assertSame(15000, $planning->next_due_km);

        // Interval dipendekkan khusus kategori kendaraan unit ini.
        $this->actingAs($superadmin)->post(route('planning-item-overrides.store'), [
            'planning_item_id' => $planningItem->id,
            'vehicle_category' => 'truk_ringan',
            'interval_km' => 3000,
            'interval_days' => 45,
        ])->assertRedirect();

        $planning->refresh();

        $this->assertSame(13000, $planning->next_due_km, 'Override yang memendekkan interval ikut berlaku.');
        $this->assertSame(
            today()->subDays(30)->addDays(45)->toDateString(),
            $planning->next_due_date->toDateString(),
            'Sisi tanggal ikut dihitung ulang, bukan hanya KM.',
        );
        $this->assertSame(1, PlanningItemOverride::query()->count());
    }

    /**
     * @return array{0: Site, 1: Unit, 2: UnitPlanning, 3: PlanningItem}
     */
    private function fleet(int $lastDoneKm, int $intervalKm): array
    {
        SystemThreshold::query()->create(['key' => 'warning_km', 'value' => '500']);
        SystemThreshold::query()->create(['key' => 'warning_days', 'value' => '7']);
        SystemThreshold::query()->create(['key' => 'min_inspection_data', 'value' => '3']);

        $site = Site::query()->create(['name' => 'Site Manual', 'region' => 'Kalimantan']);
        $unit = $this->unit($site, 'KT 1111 AA');
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => $intervalKm, 'interval_days' => 90]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => $lastDoneKm,
            'last_done_date' => today()->subDays(30)->toDateString(),
            'next_due_km' => $lastDoneKm + $intervalKm,
            'next_due_date' => today()->addDays(60)->toDateString(),
        ]);

        return [$site, $unit, $planning, $planningItem];
    }

    private function unit(Site $site, string $plate): Unit
    {
        return Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => $plate,
            'type' => 'Truck',
            'brand' => 'Hino',
            'vehicle_category' => 'truk_ringan',
            'year' => 2024,
            'current_odo' => 10000,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]);
    }
}
