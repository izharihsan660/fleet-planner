<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\HighUsageFlag;
use App\Models\InspectionLog;
use App\Models\Notification;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\FleetNotificationService;
use App\Services\PlanningBaselineSheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class PlanningBaselineSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_blank_km_uses_zero_and_date_only_task_without_km_due(): void
    {
        [$unit, $planning] = $this->planning('KT 1111 AA', 'Filter A');
        $this->import($this->sheet([[$unit->current_plate, 'Filter A', null, today()->subDays(30)->format('d/m/Y'), today()->addDays(2)->format('d/m/Y'), '']]));

        $planning->refresh();
        $this->assertSame(0, $planning->last_done_km);
        $this->assertNull($planning->next_due_km);
        $this->assertTrue($planning->isDueByDate());
        $this->assertFalse($planning->isDueByKm());
        $this->assertSame('on_hold', WorkOrderItem::query()->where('unit_planning_id', $planning->id)->value('status'));
    }

    public function test_next_only_preserves_empty_and_filled_baseline(): void
    {
        [$unit, $empty] = $this->planning('KT 2222 AA', 'Filter A');
        [, $filled] = $this->planning('KT 2222 AA', 'Filter B', $unit);
        $filled->update(['last_done_km' => 500, 'last_done_date' => today()->subDays(10), 'next_due_km' => 1500]);
        $next = today()->subDay()->format('d/m/Y');
        $this->import($this->sheet([
            [$unit->current_plate, 'Filter A', null, null, $next, ''],
            [$unit->current_plate, 'Filter B', null, null, $next, ''],
        ]));

        $this->assertSame(0, $empty->refresh()->last_done_km);
        $this->assertNull($empty->last_done_date);
        $this->assertNull($empty->next_due_km);
        $this->assertTrue($empty->due_manually_set);
        $this->assertSame('overdue', WorkOrderItem::query()->where('unit_planning_id', $empty->id)->value('status'));
        $this->assertSame(500, $filled->refresh()->last_done_km);
        $this->assertSame(today()->subDays(10)->toDateString(), $filled->last_done_date->toDateString());
        $this->assertTrue($filled->due_manually_set);
    }

    public function test_excel_dates_and_invalid_km_are_validated_without_guessing(): void
    {
        [$unit, $text] = $this->planning('KT 3333 AA', 'Text Date');
        [, $ambiguous] = $this->planning('KT 3333 AA', 'Ambiguous', $unit);
        [, $excel] = $this->planning('KT 3333 AA', 'Excel Date', $unit);
        [, $overflow] = $this->planning('KT 3333 AA', 'Overflow', $unit);
        [, $numeric] = $this->planning('KT 3333 AA', 'Numeric', $unit);
        [, $zero] = $this->planning('KT 3333 AA', 'Zero KM', $unit);
        $path = $this->sheet([
            [$unit->current_plate, 'Text Date', null, null, '05/11/2026', ''],
            [$unit->current_plate, 'Ambiguous', null, null, null, ''],
            [$unit->current_plate, 'Excel Date', null, null, null, ''],
            [$unit->current_plate, 'Overflow', null, '30/02/2026', null, ''],
            [$unit->current_plate, 'Numeric', null, 46000, null, ''],
            [$unit->current_plate, 'Zero KM', 0, null, null, ''],
        ], function (Spreadsheet $spreadsheet): void {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue('E3', Date::PHPToExcel(CarbonImmutable::parse('2026-09-05')));
            $sheet->getStyle('E3')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $sheet->setCellValue('E4', Date::PHPToExcel(CarbonImmutable::parse('2026-09-15')));
            $sheet->getStyle('E4')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        });
        $readRows = app(PlanningBaselineSheetReader::class)->read($path, 'Siap Import');
        $this->assertTrue($readRows[2]['next_date_excel']);
        $this->import($path);

        $this->assertSame('2026-11-05', $text->refresh()->next_due_date->toDateString());
        $this->assertNotNull($excel->refresh()->next_due_date, Artisan::output());
        $this->assertSame('2026-09-15', $excel->next_due_date->toDateString());
        foreach ([$ambiguous, $overflow, $numeric, $zero] as $rejected) {
            $this->assertNull($rejected->refresh()->next_due_date);
        }
        $this->assertSame(0, $zero->last_done_km);
    }

    public function test_newer_file_wins_older_and_completed_data_conflict_unless_forced(): void
    {
        [$unit, $planning] = $this->planning('KT 4444 AA', 'Filter A');
        $planning->update(['last_done_km' => 100, 'last_done_date' => today()->subDays(20), 'next_due_km' => 1100]);
        $this->import($this->sheet([[$unit->current_plate, 'Filter A', 200, today()->subDays(10)->format('d/m/Y'), null, '']]));
        $this->assertSame(200, $planning->refresh()->last_done_km);

        $this->import($this->sheet([[$unit->current_plate, 'Filter A', 150, today()->subDays(40)->format('d/m/Y'), null, '']]));
        $this->assertSame(200, $planning->refresh()->last_done_km);

        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $unit->site_id, 'status' => 'complete', 'trigger_type' => 'normal']);
        $complete = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id, 'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id, 'status' => 'complete', 'completed_date' => today()->subDay()]);
        $newer = $this->sheet([[$unit->current_plate, 'Filter A', 250, today()->subDays(5)->format('d/m/Y'), null, '']]);
        $this->import($newer);
        $this->assertSame(200, $planning->refresh()->last_done_km);

        $this->import($newer, ['--force-overwrite' => true]);
        $this->assertSame(250, $planning->refresh()->last_done_km);
        $this->assertSame('complete', $complete->refresh()->status);
    }

    public function test_odometer_raise_writes_one_log_and_rejects_conflicting_history(): void
    {
        [$unit, $first] = $this->planning('KT 5555 AA', 'Filter A');
        [, $second] = $this->planning('KT 5555 AA', 'Filter B', $unit);
        $date = today()->subDays(30);
        $this->import($this->sheet([
            [$unit->current_plate, 'Filter A', 1100, $date->format('d/m/Y'), null, ''],
            [$unit->current_plate, 'Filter B', 1200, $date->subDay()->format('d/m/Y'), null, ''],
        ]));

        $this->assertSame(1200, $unit->refresh()->current_odo);
        $this->assertSame(1, InspectionLog::query()->where('unit_id', $unit->id)->count());
        $this->assertNull(InspectionLog::query()->firstOrFail()->mechanic_id);
        $this->assertSame(1100, $first->refresh()->last_done_km);
        $this->assertSame(1200, $second->refresh()->last_done_km);
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame(0, HighUsageFlag::query()->count());

        [, $withoutDate] = $this->planning('KT 5555 AA', 'Filter C', $unit);
        [, $historyConflict] = $this->planning('KT 5555 AA', 'Filter D', $unit);
        $this->import($this->sheet([
            [$unit->current_plate, 'Filter C', 1400, null, null, ''],
            [$unit->current_plate, 'Filter D', 1400, $date->subDays(10)->format('d/m/Y'), null, ''],
        ]));
        $this->assertSame(0, $withoutDate->refresh()->last_done_km);
        $this->assertSame(0, $historyConflict->refresh()->last_done_km);
        $this->assertSame(1200, $unit->refresh()->current_odo);
    }

    public function test_dry_run_rolls_back_all_tables_and_execute_is_idempotent(): void
    {
        [$unit, $planning] = $this->planning('KT 6666 AA', 'Filter A');
        $path = $this->sheet([[$unit->current_plate, 'Filter A', 1500, today()->subDays(10)->format('d/m/Y'), today()->subDay()->format('d/m/Y'), '']]);
        $before = [WorkOrder::query()->count(), WorkOrderItem::query()->count(), InspectionLog::query()->count(), Notification::query()->count()];

        $this->import($path, ['--dry-run' => true]);
        $this->assertSame($before, [WorkOrder::query()->count(), WorkOrderItem::query()->count(), InspectionLog::query()->count(), Notification::query()->count()]);
        $this->assertSame(0, $planning->refresh()->last_done_km);
        $this->assertSame(1000, $unit->refresh()->current_odo);

        $this->import($path);
        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $planning->id)->count());
        $this->import($path);
        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $planning->id)->count());
        $this->assertSame(1, InspectionLog::query()->count());
    }

    public function test_duplicate_plate_history_and_excluded_planning(): void
    {
        [$unit, $duplicate] = $this->planning('KT 7777 AA', 'Duplicate');
        [, $historical] = $this->planning('KT 7777 AA', 'History', $unit);
        [, $excluded] = $this->planning('KT 7777 AA', 'Excluded', $unit);
        $excluded->update(['is_excluded' => true]);
        $unit->plateHistories()->create(['plate_number' => 'KT 0000 ZZ', 'active_from' => today()->subYear()]);
        $this->import($this->sheet([
            ['kt  7777 aa', 'Duplicate', null, today()->subDays(10)->format('d/m/Y'), null, ''],
            ['KT 0000 ZZ', 'Duplicate', null, today()->subDays(10)->format('d/m/Y'), null, ''],
            ['KT 0000 ZZ', 'History', null, today()->subDays(10)->format('d/m/Y'), null, ''],
            ['KT 7777 AA', 'Excluded', null, today()->subDays(10)->format('d/m/Y'), null, ''],
        ]));

        $this->assertNull($duplicate->refresh()->last_done_date);
        $this->assertNotNull($historical->refresh()->last_done_date);
        $this->assertNull($excluded->refresh()->last_done_date);
    }

    public function test_locked_and_unlocked_active_items_are_reconciled_without_changing_terminal_rows(): void
    {
        [$unit, $close] = $this->planning('KT 8888 AA', 'Close');
        $unit->update(['current_odo' => 500]);
        [, $keep] = $this->planning('KT 8888 AA', 'Keep', $unit);
        [, $lockedClose] = $this->planning('KT 8888 AA', 'Locked Close', $unit);
        [, $lockedKeep] = $this->planning('KT 8888 AA', 'Locked Keep', $unit);
        [, $created] = $this->planning('KT 8888 AA', 'Created', $unit);
        $submitter = User::factory()->create();
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $unit->site_id, 'status' => 'open', 'trigger_type' => 'normal']);
        $active = [];
        foreach ([$close, $keep, $lockedClose, $lockedKeep] as $index => $planning) {
            $planning->update(['last_done_km' => 100, 'last_done_date' => today()->subDays(20), 'next_due_km' => 1100]);
            $active[] = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id, 'planning_item_id' => $planning->planning_item_id,
                'status' => ['on_hold', 'on_hold', 'in_progress', 'replace'][$index],
                'action' => $index >= 2 ? 'replace' : null, 'submitted_by' => $index >= 2 ? $submitter->id : null]);
        }
        $terminal = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id,
            'unit_planning_id' => $close->id, 'planning_item_id' => $close->planning_item_id,
            'status' => 'complete', 'completed_date' => today()->subDays(30)]);
        $date = today()->subDays(10)->format('d/m/Y');
        $future = today()->addDays(30)->format('d/m/Y');
        $past = today()->subDay()->format('d/m/Y');
        $this->import($this->sheet([
            [$unit->current_plate, 'Close', 200, $date, $future, ''],
            [$unit->current_plate, 'Keep', 200, $date, $past, ''],
            [$unit->current_plate, 'Locked Close', 200, $date, $future, ''],
            [$unit->current_plate, 'Locked Keep', 200, $date, $past, ''],
            [$unit->current_plate, 'Created', null, $date, $past, ''],
        ]));

        $this->assertSame('cancelled', $active[0]->refresh()->status);
        $this->assertSame('overdue', $active[1]->refresh()->status);
        $this->assertSame('cancelled', $active[2]->refresh()->status);
        $this->assertStringContainsString('sebelumnya in_progress', $active[2]->reason);
        $this->assertSame('replace', $active[3]->refresh()->status);
        $this->assertSame($submitter->id, $active[3]->submitted_by);
        $this->assertSame('complete', $terminal->refresh()->status);
        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $created->id)->count());
    }

    public function test_locked_rejected_and_pending_items_close_with_history_and_sync_parent(): void
    {
        [$unit, $rejected] = $this->planning('KT 1010 AA', 'Rejected');
        $unit->update(['current_odo' => 500]);
        [, $pending] = $this->planning('KT 1010 AA', 'Pending', $unit);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $unit->site_id, 'status' => 'open', 'trigger_type' => 'normal']);
        $actor = User::factory()->create();
        $active = [];

        foreach ([$rejected, $pending] as $index => $planning) {
            $planning->update(['last_done_km' => 100, 'last_done_date' => today()->subDays(20), 'next_due_km' => 1100]);
            $active[] = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id, 'planning_item_id' => $planning->planning_item_id,
                'status' => $index === 0 ? 'rejected' : 'replace', 'action' => 'replace',
                'submitted_by' => $actor->id, 'approved_by' => $actor->id]);
        }

        $historicalWorkOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $unit->site_id,
            'status' => 'complete', 'trigger_type' => 'normal']);
        $postponed = WorkOrderItem::query()->create(['work_order_id' => $historicalWorkOrder->id,
            'unit_planning_id' => $rejected->id, 'planning_item_id' => $rejected->planning_item_id,
            'status' => 'postponed']);
        $cancelled = WorkOrderItem::query()->create(['work_order_id' => $historicalWorkOrder->id,
            'unit_planning_id' => $pending->id, 'planning_item_id' => $pending->planning_item_id,
            'status' => 'cancelled']);

        $this->import($this->sheet([
            [$unit->current_plate, 'Rejected', 200, today()->subDays(10)->format('d/m/Y'), today()->addDays(30)->format('d/m/Y'), ''],
            [$unit->current_plate, 'Pending', 200, today()->subDays(10)->format('d/m/Y'), today()->addDays(30)->format('d/m/Y'), ''],
        ]));

        foreach ($active as $item) {
            $this->assertSame('cancelled', $item->refresh()->status);
            $this->assertStringContainsString('sebelumnya', $item->reason);
            $this->assertStringContainsString('submitted_by '.$actor->id, $item->reason);
            $this->assertSame($actor->id, $item->approved_by);
        }
        $this->assertSame('postponed', $postponed->refresh()->status);
        $this->assertSame('cancelled', $cancelled->refresh()->status);
        $this->assertSame('cancelled', $workOrder->refresh()->status);
    }

    public function test_execute_sends_one_summary_per_scoped_recipient_without_per_item_notifications(): void
    {
        [$unit] = $this->planning('KT 9999 AA', 'Filter A');
        $this->planning('KT 9999 AA', 'Filter B', $unit);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $unit->site->region_id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $otherRegion = Region::query()->create(['name' => 'Other']);
        $outside = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $otherRegion->id]);
        $this->import($this->sheet([
            [$unit->current_plate, 'Filter A', null, today()->subDays(10)->format('d/m/Y'), today()->subDay()->format('d/m/Y'), ''],
            [$unit->current_plate, 'Filter B', null, today()->subDays(10)->format('d/m/Y'), today()->addDays(30)->format('d/m/Y'), ''],
        ]));

        $this->assertSame(1, Notification::query()->where('user_id', $planner->id)->count());
        $this->assertSame(1, Notification::query()->where('user_id', $spv->id)->count());
        $this->assertSame(0, Notification::query()->where('user_id', $outside->id)->count());
        $this->assertSame(0, Notification::query()->whereIn('type', ['task_auto_generated', 'maintenance_overdue', 'high_usage_window_2'])->count());
        $this->assertSame('baseline_import_summary', Notification::query()->firstOrFail()->type);
    }

    public function test_execute_rolls_back_data_and_report_when_summary_fails(): void
    {
        [$unit, $planning] = $this->planning('KT 1212 AA', 'Filter A');
        User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $unit->site->region_id]);
        $path = $this->sheet([[$unit->current_plate, 'Filter A', null, today()->subDays(10)->format('d/m/Y'),
            today()->subDay()->format('d/m/Y'), '']]);
        $reportPattern = storage_path('app/import-reports/baseline-'.pathinfo($path, PATHINFO_FILENAME).'-*.csv');
        $existingReports = glob($reportPattern);
        $this->mock(FleetNotificationService::class)
            ->shouldReceive('batchSummary')->once()->andThrow(new RuntimeException('summary unavailable'));

        $this->assertSame(1, Artisan::call('planning:import-baseline-sheet', ['file' => $path, '--execute' => true]));
        $this->assertStringContainsString('Import dibatalkan', Artisan::output());
        $this->assertSame(0, $planning->refresh()->last_done_km);
        $this->assertSame(0, WorkOrderItem::query()->count());
        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame($existingReports, glob($reportPattern));
    }

    public function test_import_requires_explicit_mode_and_rejects_missing_sheet(): void
    {
        [$unit] = $this->planning('KT 1313 AA', 'Filter A');
        $path = $this->sheet([[$unit->current_plate, 'Filter A', 100, null, null, '']]);

        $this->assertSame(1, Artisan::call('planning:import-baseline-sheet', ['file' => $path]));
        $this->assertSame(1, Artisan::call('planning:import-baseline-sheet', ['file' => $path, '--execute' => true, '--sheet' => 'Unknown']));
        $this->assertSame(0, WorkOrderItem::query()->count());
    }

    private function planning(string $plate, string $name, ?Unit $unit = null): array
    {
        if ($unit === null) {
            $region = Region::query()->create(['name' => 'Region A']);
            $site = Site::query()->create(['name' => 'Site A', 'region' => 'Region A', 'region_id' => $region->id]);
            $unit = Unit::query()->create(['site_id' => $site->id, 'customer' => 'A', 'current_plate' => $plate,
                'type' => 'Pickup', 'brand' => 'Toyota', 'year' => 2024, 'current_odo' => 1000, 'status' => 'active', 'has_odometer_reading' => true]);
        }

        $item = PlanningItem::query()->create(['name' => $name, 'interval_km' => 1000, 'interval_days' => 90]);
        $planning = UnitPlanning::query()->create(['unit_id' => $unit->id, 'planning_item_id' => $item->id,
            'last_done_km' => 0, 'last_done_date' => null, 'next_due_km' => null, 'next_due_date' => null]);

        return [$unit, $planning];
    }

    private function sheet(array $rows, ?callable $configure = null): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Siap Import');
        $sheet->fromArray([['Plat Nomor', 'Item Maintenance', 'KM SAAT DIGANTI',
            'TANGGAL TERAKHIR DIGANTI (DD/MM/YYYY)', 'NEXT PERGANTIAN (DD/MM/YYYY)', 'Catatan'], ...$rows]);
        if ($configure !== null) {
            $configure($spreadsheet);
        }
        $path = tempnam(sys_get_temp_dir(), 'baseline-sheet-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->files[] = $path;

        return $path;
    }

    private function import(string $path, array $options = []): void
    {
        $exit = Artisan::call('planning:import-baseline-sheet', ['file' => $path, '--execute' => ! isset($options['--dry-run']), ...$options]);
        $this->assertSame(0, $exit, Artisan::output());
        if (preg_match('/CSV: (.+\.csv)/', Artisan::output(), $matches)) {
            $this->files[] = $matches[1];
        }
    }
}
