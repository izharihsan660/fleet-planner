<?php

namespace App\Http\Controllers;

use App\Http\Requests\DecideUnitSiteTransferRequest;
use App\Http\Requests\StoreUnitSiteTransferRequest;
use App\Models\Unit;
use App\Models\UnitSiteTransfer;
use App\Services\FleetNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class UnitSiteTransferController extends Controller
{
    public function store(StoreUnitSiteTransferRequest $request, Unit $unit, FleetNotificationService $notifications): RedirectResponse
    {
        if ($unit->siteTransfers()->where('status', 'pending')->exists()) {
            return back()->withErrors(['to_site_id' => 'Unit ini masih punya pengajuan pindah site yang menunggu approval.']);
        }

        $transfer = UnitSiteTransfer::query()->create([
            'unit_id' => $unit->id,
            'from_site_id' => $unit->site_id,
            'to_site_id' => $request->integer('to_site_id'),
            'reason' => $request->validated('reason'),
            'requested_by' => $request->user()->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $notifications->unitSiteTransferRequested($transfer->refresh());

        return back()->with('status', 'Pengajuan pindah site dikirim ke Spv HO.');
    }

    public function approve(DecideUnitSiteTransferRequest $request, UnitSiteTransfer $transfer, FleetNotificationService $notifications): RedirectResponse
    {
        if ($transfer->status !== 'pending') {
            return back()->withErrors(['decision_reason' => 'Pengajuan ini sudah diproses.']);
        }

        DB::transaction(function () use ($request, $transfer, $notifications): void {
            $transfer->loadMissing(['unit', 'fromSite', 'toSite']);
            $oldSiteId = $transfer->from_site_id;
            $newSiteId = $transfer->to_site_id;

            $transfer->unit->update(['site_id' => $newSiteId]);

            $transfer->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'decision_reason' => $request->validated('decision_reason'),
            ]);

            $notifications->unitSiteTransferApproved($transfer->refresh(), $oldSiteId, $newSiteId);
        });

        return back()->with('status', 'Pindah site disetujui.');
    }

    public function reject(DecideUnitSiteTransferRequest $request, UnitSiteTransfer $transfer): RedirectResponse
    {
        if ($transfer->status !== 'pending') {
            return back()->withErrors(['decision_reason' => 'Pengajuan ini sudah diproses.']);
        }

        $transfer->update([
            'status' => 'rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'decision_reason' => $request->validated('decision_reason'),
        ]);

        return back()->with('status', 'Pindah site ditolak.');
    }
}
