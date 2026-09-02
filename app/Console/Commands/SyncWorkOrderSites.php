<?php

namespace App\Console\Commands;

use App\Models\Unit;
use App\Services\UnitSiteRelocationService;
use App\Services\WorkOrderProgressService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Perbaikan sekali jalan untuk unit yang terlanjur dipindah site tanpa membawa
 * pekerjaan aktifnya — sebelum UnitObserver menangani perpindahan site. Unit
 * seperti ini tampil di site baru pada papan Perintah Kerja dan Proyeksi, tapi
 * masih di site lama pada Daftar Kerja, Ringkasan, Antrian Approval, dan
 * Laporan. Perbaikannya memakai UnitSiteRelocationService yang sama dengan
 * jalur normal, jadi hasilnya tidak mungkin berbeda aturan.
 */
class SyncWorkOrderSites extends Command
{
    protected $signature = 'fleet:sync-work-order-sites {--dry-run} {--execute}';

    protected $description = 'Move active work orders to the site their unit currently belongs to.';

    public function handle(
        UnitSiteRelocationService $relocationService,
        WorkOrderProgressService $workOrderProgressService,
    ): int {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Gunakan salah satu opsi saja: --dry-run atau --execute.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $units = $this->mismatchedUnits($workOrderProgressService);

        $this->line('MODE: '.($execute ? 'EXECUTE' : 'DRY-RUN'));

        if ($units->isEmpty()) {
            $this->info('Tidak ada work order aktif yang site-nya berbeda dari unitnya.');

            return self::SUCCESS;
        }

        $rows = [];
        $workOrderCount = 0;

        foreach ($units as $unit) {
            $mismatched = $unit->workOrders->where('site_id', '!=', $unit->site_id);

            foreach ($mismatched as $workOrder) {
                $workOrderCount++;
                $rows[] = [
                    'WO #'.$workOrder->id,
                    $unit->current_plate,
                    $workOrder->site?->name ?? '-',
                    $unit->site?->name ?? '-',
                    $workOrder->assignedMechanic?->name ?? '-',
                ];
            }
        }

        $this->table(['Work Order', 'Plat Nomor', 'Site WO (lama)', 'Site Unit (baru)', 'Mekanik'], $rows);

        if (! $execute) {
            $this->info($workOrderCount.' work order akan dipindah ke site unitnya. Tidak ada data yang diubah.');

            return self::SUCCESS;
        }

        try {
            $units->each(fn (Unit $unit) => $relocationService->relocateActiveWorkOrders($unit));
        } catch (Throwable $exception) {
            $this->error('Perbaikan gagal: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($workOrderCount.' work order berhasil dipindah ke site unitnya.');
        $this->info('Mekanik penanggung jawab dilepas — penugasan diulang oleh planner site baru.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Unit>
     */
    private function mismatchedUnits(WorkOrderProgressService $workOrderProgressService): Collection
    {
        $terminalStatuses = $workOrderProgressService->terminalWorkOrderStatuses();

        return Unit::query()
            // Eager load tidak bisa membandingkan ke kolom units.site_id karena
            // dijalankan sebagai query terpisah, jadi selisihnya disaring di PHP
            // setelah unit yang terdampak dipersempit oleh whereHas di bawah.
            ->with([
                'site:id,name',
                'workOrders' => fn ($query) => $query
                    ->whereNotIn('status', $terminalStatuses)
                    ->with(['site:id,name', 'assignedMechanic:id,name']),
            ])
            ->whereHas('workOrders', fn ($query) => $query
                ->whereNotIn('status', $terminalStatuses)
                ->whereColumn('work_orders.site_id', '!=', 'units.site_id'))
            ->orderBy('current_plate')
            ->get();
    }
}
