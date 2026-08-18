<?php

namespace App\Console\Commands;

use App\Models\Unit;
use App\Models\UnitPlanning;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RepairMissingOdometerPlanningBaselines extends Command
{
    protected $signature = 'maintenance:repair-missing-odometer-baselines
                            {--dry-run : Preview affected units without applying changes (default)}
                            {--execute : Set next_due_km to NULL for affected unit plannings}';

    protected $description = 'Dry-run or repair KM planning baselines created before a unit has an odometer reading.';

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Gunakan salah satu opsi saja: --dry-run atau --execute.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $units = $this->matchingUnits();
        $affectedPlanningRows = $units->sum('affected_planning_count');

        $this->line('MODE: '.($execute ? 'EXECUTE' : 'DRY-RUN'));
        $this->line('Kondisi: has_odometer_reading=false, next_due_km terisi, dan TIDAK ada riwayat last_done_km');
        $this->line('Baris dengan riwayat last_done_km sengaja dilewati — itu baseline KM yang sah.');
        $this->info('Unit terdampak: '.$units->count());
        $this->info('Unit planning terdampak: '.$affectedPlanningRows);
        $this->line('Unit current_odo=0: '.$units->where('current_odo', 0)->count());
        $this->line('Unit current_odo>0: '.$units->where('current_odo', '>', 0)->count());

        if ($units->isEmpty()) {
            $this->info('Tidak ada baseline KM yang perlu diperbaiki.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Plat Nomor', 'Site', 'Current Odo', 'Inspection Logs', 'Planning Terisi'],
            $units->map(fn (Unit $unit): array => [
                $unit->id,
                $unit->current_plate,
                $unit->site?->name ?? '-',
                $unit->current_odo,
                $unit->inspection_logs_count,
                $unit->affected_planning_count,
            ])->all(),
        );

        if (! $execute) {
            $this->info('DRY-RUN saja. Tidak ada data yang diubah. Jalankan dengan --execute setelah dikonfirmasi.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            UnitPlanning::query()
                ->applicable()
                ->whereNotNull('next_due_km')
                ->missingBaseline()
                ->whereHas('unit', fn ($query) => $query->where('has_odometer_reading', false))
                ->update([
                    'next_due_km' => null,
                    'updated_at' => now(),
                ]);
        });

        $this->info('EXECUTE selesai. next_due_km unit terdampak sudah dikosongkan; next_due_date tidak diubah.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Unit>
     */
    private function matchingUnits(): Collection
    {
        return Unit::query()
            ->with('site:id,name')
            ->withCount('inspectionLogs')
            ->withCount([
                'unitPlannings as affected_planning_count' => fn ($query) => $query->applicable()->whereNotNull('next_due_km')->missingBaseline(),
            ])
            ->where('has_odometer_reading', false)
            ->whereHas('unitPlannings', fn ($query) => $query->applicable()->whereNotNull('next_due_km')->missingBaseline())
            ->orderBy('current_plate')
            ->get();
    }
}
