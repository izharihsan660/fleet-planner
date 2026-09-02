<?php

namespace App\Services;

use App\Models\PlanningItem;
use App\Models\UnitPlanning;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecalculateDueDatesService
{
    public function __construct(private PlanningIntervalResolver $intervalResolver) {}

    /**
     * @param  array{name: string, interval_km: int|string, interval_days: int|string}  $attributes
     */
    public function update(PlanningItem $planningItem, array $attributes): PlanningItem
    {
        $affectedRows = DB::transaction(function () use ($planningItem, $attributes): ?int {
            $planningItem->fill($attributes);
            $shouldRecalculate = $planningItem->isDirty(['interval_km', 'interval_days']);
            $planningItem->save();

            if (! $shouldRecalculate) {
                return null;
            }

            return $this->recalculate($planningItem);
        });

        if ($affectedRows !== null) {
            Log::info('Planning item due dates recalculated.', [
                'planning_item_id' => $planningItem->id,
                'affected_rows' => $affectedRows,
            ]);
        }

        return $planningItem->refresh();
    }

    /**
     * Perubahan interval khusus kategori kendaraan tidak lagi menunggu input KM
     * untuk berlaku. Sebelumnya satu-satunya yang menyusulkannya adalah recompute
     * di InspectionService, dan itu hanya menaikkan next_due_km — memendekkan
     * interval lewat override tidak pernah benar-benar diterapkan, dan sisi
     * tanggalnya tidak pernah tersentuh sama sekali.
     */
    public function recalculateForVehicleCategory(PlanningItem $planningItem, string $vehicleCategory): int
    {
        return DB::transaction(fn (): int => $this->recalculate($planningItem, $vehicleCategory));
    }

    private function recalculate(PlanningItem $planningItem, ?string $vehicleCategory = null): int
    {
        $planningItem->loadMissing('overrides');
        $affectedRows = 0;

        $planningItem->unitPlannings()
            ->applicable()
            ->with('unit:id,vehicle_category')
            // Jadwal yang ditetapkan SPV lewat Tunda atau High Usage tidak ikut
            // dihitung ulang: interval adalah nilai default, bukan pembatal
            // keputusan yang sudah disetujui.
            ->where('due_manually_set', false)
            ->when(
                $vehicleCategory !== null,
                fn ($query) => $query->whereHas('unit', fn ($unitQuery) => $unitQuery->where('vehicle_category', $vehicleCategory)),
            )
            ->whereNotNull('last_done_km')
            ->where('last_done_km', '>', 0)
            ->whereNotNull('last_done_date')
            ->select([
                'id',
                'unit_id',
                'planning_item_id',
                'last_done_km',
                'last_done_date',
            ])
            ->chunkById(200, function (Collection $unitPlannings) use ($planningItem, &$affectedRows): void {
                $unitPlannings->each(function (UnitPlanning $unitPlanning) use ($planningItem, &$affectedRows): void {
                    $interval = $this->intervalResolver->resolve($planningItem, $unitPlanning->unit);

                    $unitPlanning->update([
                        'next_due_km' => (int) $unitPlanning->last_done_km + $interval['interval_km'],
                        'next_due_date' => $unitPlanning->last_done_date
                            ->copy()
                            ->addDays($interval['interval_days'])
                            ->toDateString(),
                    ]);

                    $affectedRows++;
                });
            });

        return $affectedRows;
    }
}
