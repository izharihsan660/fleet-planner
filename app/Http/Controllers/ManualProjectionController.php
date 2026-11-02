<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreManualProjectionRequest;
use App\Models\ManualProjection;
use App\Models\Unit;
use App\Models\UnitPlanning;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManualProjectionController extends Controller
{
    public function store(StoreManualProjectionRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $unit = Unit::query()->lockForUpdate()->findOrFail($request->integer('unit_id'));
            Gate::authorize('create', [ManualProjection::class, $unit]);

            if (ManualProjection::query()->active()->where('unit_id', $unit->id)
                ->where('planning_item_id', $request->integer('planning_item_id'))->exists()) {
                throw ValidationException::withMessages([
                    'planning_item_id' => 'Unit dan item ini sudah memiliki kebutuhan manual aktif.',
                ]);
            }

            ManualProjection::query()->create([
                ...$request->validated(),
                'unit_planning_id' => UnitPlanning::query()->where('unit_id', $unit->id)
                    ->where('planning_item_id', $request->integer('planning_item_id'))->value('id'),
                'created_by' => $request->user()->id,
                'status' => 'active',
            ]);
        });

        return back()->with('status', 'Kebutuhan manual berhasil ditambahkan.');
    }

    public function cancel(Request $request, ManualProjection $manualProjection): RedirectResponse
    {
        Gate::authorize('cancel', $manualProjection);

        ManualProjection::query()->whereKey($manualProjection->id)->active()->update([
            'status' => 'cancelled',
            'cancelled_by' => $request->user()->id,
            'cancelled_at' => now(),
        ]);

        return back()->with('status', 'Kebutuhan manual dibatalkan.');
    }
}
