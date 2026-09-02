<?php

namespace App\Services;

use App\Models\Unit;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Menyusulkan pekerjaan aktif saat sebuah unit berpindah site.
 *
 * Aplikasi menentukan "unit ini di site mana" lewat dua kolom: papan Perintah
 * Kerja dan Proyeksi membaca units.site_id, sedangkan Daftar Kerja, Ringkasan,
 * Antrian Approval, dan Laporan membaca work_orders.site_id. Selama keduanya
 * tidak disamakan, unit yang dipindah akan tampil di dua site sekaligus
 * tergantung halaman mana yang dibuka.
 *
 * Dipanggil dari UnitObserver, jadi berlaku untuk semua jalur yang mengubah
 * site unit: Master Data Unit, approval Pindah Site, maupun command import.
 */
class UnitSiteRelocationService
{
    /**
     * Item yang sudah tuntas tidak ikut dipindah — riwayatnya memang terjadi di
     * site lama, dan Laporan merekap per work_orders.site_id per bulan.
     *
     * @var array<int, string>
     */
    private const FINAL_ITEM_STATUSES = ['complete', 'cancelled', 'postponed'];

    public function __construct(private WorkOrderProgressService $workOrderProgressService) {}

    public function relocateActiveWorkOrders(Unit $unit): void
    {
        DB::transaction(function () use ($unit): void {
            $workOrderIds = $unit->workOrders()
                ->whereNotIn('status', $this->workOrderProgressService->terminalWorkOrderStatuses())
                ->pluck('id');

            if ($workOrderIds->isEmpty()) {
                return;
            }

            // Mekanik penanggung jawab ikut dilepas: mekanik terikat pada satu
            // site, jadi orang di site lama tidak mungkin lagi mengerjakannya —
            // dan validasi penugasan memang mensyaratkan mekanik satu site
            // dengan WO-nya.
            WorkOrder::query()
                ->whereIn('id', $workOrderIds)
                ->update([
                    'site_id' => $unit->site_id,
                    'assigned_mechanic_id' => null,
                ]);

            WorkOrderItem::query()
                ->whereIn('work_order_id', $workOrderIds)
                ->whereNotIn('status', self::FINAL_ITEM_STATUSES)
                ->update(['scheduled_date' => null]);

            // Tanpa mekanik dan tanggal, item tidak lagi memenuhi syarat
            // in_progress — sama persis dengan aturan yang dipakai saat SPV
            // menyetujui pengajuan. Item kembali menunggu penugasan planner di
            // site barunya.
            WorkOrderItem::query()
                ->whereIn('work_order_id', $workOrderIds)
                ->where('status', 'in_progress')
                ->update(['status' => 'on_hold']);

            WorkOrder::query()
                ->whereIn('id', $workOrderIds)
                ->get()
                ->each(fn (WorkOrder $workOrder) => $this->workOrderProgressService->sync($workOrder));
        });
    }
}
