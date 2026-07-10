<?php

namespace App\Console\Commands;

use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ResetInconsistentOdometerReadings extends Command
{
    protected $signature = 'units:reset-inconsistent-odometer-readings
                            {--dry-run : Preview units without applying changes (default)}
                            {--execute : Reset matched units to current_odo=0 and has_odometer_reading=false}';

    protected $description = 'Dry-run or reset units marked as having odometer readings but without inspection logs.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $units = $this->matchingUnits();

        $this->line('MODE: '.($execute ? 'EXECUTE' : 'DRY-RUN'));
        $this->line('Kondisi: has_odometer_reading=true dan inspection_logs_count=0');
        $this->line('Unit match: '.$units->count());

        if ($units->isEmpty()) {
            $this->info('Tidak ada unit yang perlu di-reset.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Plat Nomor', 'Site', 'Current Odo', 'Has Odo Reading', 'Inspection Logs'],
            $units->map(fn (Unit $unit): array => [
                $unit->id,
                $unit->current_plate,
                $unit->site?->name ?? '-',
                $unit->current_odo,
                $unit->has_odometer_reading ? 'true' : 'false',
                $unit->inspection_logs_count,
            ])->all(),
        );

        if (! $execute) {
            $this->info('DRY-RUN saja. Tidak ada data yang diubah. Jalankan dengan --execute setelah dikonfirmasi.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($units): void {
            Unit::query()
                ->whereKey($units->pluck('id'))
                ->update([
                    'current_odo' => 0,
                    'has_odometer_reading' => false,
                    'updated_at' => now(),
                ]);
        });

        $this->info('EXECUTE selesai. Unit match sudah di-reset ke current_odo=0 dan has_odometer_reading=false.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Unit>
     */
    private function matchingUnits()
    {
        return Unit::query()
            ->with('site:id,name')
            ->withCount('inspectionLogs')
            ->where('has_odometer_reading', true)
            ->doesntHave('inspectionLogs')
            ->orderBy('current_plate')
            ->get();
    }
}
