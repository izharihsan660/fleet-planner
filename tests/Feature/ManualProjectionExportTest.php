<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ManualProjection;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class ManualProjectionExportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, Spreadsheet> */
    private array $spreadsheets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
    }

    protected function tearDown(): void
    {
        foreach ($this->spreadsheets as $spreadsheet) {
            $spreadsheet->disconnectWorksheets();
        }
        $this->travelBack();
        parent::tearDown();
    }

    public function test_manual_export_includes_trigger_reason_month_bucket_and_summary_info(): void
    {
        [$manual] = $this->manual('KT 1001 AA', '2026-11-10');
        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));
        $detail = $spreadsheet->getSheetByName('Detail');
        $summary = $spreadsheet->getSheetByName('Rekap');
        $this->assertSame('Pemicu', $detail->getCell('I1')->getValue());
        $this->assertSame('Alasan Manual', $detail->getCell('J1')->getValue());
        $this->assertSame('Est. KM Jatuh Tempo', $detail->getCell('K1')->getValue());
        $this->assertSame('Manual', $detail->getCell('I2')->getValue());
        $this->assertSame('Rusak', $detail->getCell('J2')->getValue());
        $this->assertSame('Nov 2026', $detail->getCell('G2')->getValue());
        $this->assertSame((float) Date::dateTimeToExcel($manual->target_date), (float) $detail->getCell('F2')->getValue());
        $this->assertSame('1 kebutuhan berasal dari input manual', $summary->getCell('A4')->getValue());
        $this->assertSame(['HILUX 2.4 DC', 'Ban', null, null, 1, null, 1], $summary->rangeToArray('A7:G7', null, false, false)[0]);
        $this->assertSame('C7', $summary->getFreezePane());
    }

    public function test_overlap_does_not_duplicate_and_keeps_manual_reason_blank_when_system_is_earlier(): void
    {
        $this->manual('KT 1001 AA', '2026-11-07', '2026-11-12');
        $this->manual('KT 1002 AA', '2026-11-17', '2026-11-12');
        $this->manual('KT 1003 AA', '2026-11-12', '2026-11-12');
        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::Superadmin]));
        $rows = array_slice($spreadsheet->getSheetByName('Detail')->toArray(null, false, false), 1);
        $this->assertCount(3, $rows);
        $rows = array_column($rows, null, 1);
        $this->assertSame('Manual', $rows['KT 1001 AA'][8]);
        $this->assertSame('Rusak', $rows['KT 1001 AA'][9]);
        $this->assertSame('Tanggal', $rows['KT 1002 AA'][8]);
        $this->assertNull($rows['KT 1002 AA'][9]);
        $this->assertSame('Tanggal', $rows['KT 1003 AA'][8]);
        $this->assertNull($rows['KT 1003 AA'][9]);
        $this->assertSame((float) Date::dateTimeToExcel(CarbonImmutable::parse('2026-11-07')), (float) $rows['KT 1001 AA'][5]);
        $this->assertSame((float) Date::dateTimeToExcel(CarbonImmutable::parse('2026-11-12')), (float) $rows['KT 1002 AA'][5]);
        $summary = $spreadsheet->getSheetByName('Rekap');
        $this->assertSame(3, $summary->getCell($summary->getHighestColumn().$summary->getHighestRow())->getValue());
        $this->assertSame('1 kebutuhan berasal dari input manual', $summary->getCell('A4')->getValue());
    }

    public function test_overdue_and_period_end_manual_rows_are_counted_even_for_excluded_planning(): void
    {
        [$overdue, $planning] = $this->manual('KT 1001 AA', '2026-11-01');
        $planning->update(['is_excluded' => true, 'excluded_reason' => 'Tidak Berlaku']);
        $this->manual('KT 1002 AA', '2026-12-02');
        $this->manual('KT 1003 AA', '2026-12-03');
        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));
        $detail = $spreadsheet->getSheetByName('Detail');
        $this->assertSame(3, $detail->getHighestRow());
        $this->assertSame('Lewat Due', $detail->getCell('G2')->getValue());
        $this->assertSame(1, $detail->getCell('H2')->getValue());
        $this->assertSame('Des 2026', $detail->getCell('G3')->getValue());
        $summary = $spreadsheet->getSheetByName('Rekap');
        $this->assertSame(['HILUX 2.4 DC', 'Ban', 1, null, null, 1, 2], $summary->rangeToArray('A7:G7', null, false, false)[0]);
        $this->assertSame('2 kebutuhan berasal dari input manual', $summary->getCell('A4')->getValue());
        $this->assertSame('active', $overdue->fresh()->status);
    }

    public function test_manual_export_obeys_region_site_and_planner_scope(): void
    {
        [$own] = $this->manual('KT 1001 AA', '2026-11-10');
        [$outside] = $this->manual('DD 1002 BB', '2026-11-10', regionName: 'Other Region');
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $own->unit->site->region_id]);
        $spreadsheet = $this->download($planner, ['region_id' => $outside->unit->site->region_id]);
        $this->assertSame(['KT 1001 AA'], array_column(array_slice($spreadsheet->getSheetByName('Detail')->toArray(), 1), 1));
        $this->assertSame('1 kebutuhan berasal dari input manual', $spreadsheet->getSheetByName('Rekap')->getCell('A4')->getValue());
        $empty = $this->download($planner, ['site_id' => $outside->unit->site_id]);
        $this->assertSame(1, $empty->getSheetByName('Detail')->getHighestRow());

        $supervisor = User::factory()->create(['role' => UserRole::SpvHo]);
        $selected = $this->download($supervisor, ['site_id' => $outside->unit->site_id]);
        $this->assertSame('DD 1002 BB', $selected->getSheetByName('Detail')->getCell('B2')->getValue());
    }

    public function test_cancelled_and_fulfilled_entries_are_not_exported(): void
    {
        [$cancelled] = $this->manual('KT 1001 AA', '2026-11-10');
        [$fulfilled] = $this->manual('KT 1002 AA', '2026-11-10');
        $cancelled->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $fulfilled->update(['status' => 'fulfilled', 'fulfilled_at' => now()]);
        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));
        $this->assertSame(1, $spreadsheet->getSheetByName('Detail')->getHighestRow());
        $this->assertSame('0 kebutuhan berasal dari input manual', $spreadsheet->getSheetByName('Rekap')->getCell('A4')->getValue());
    }

    /** @param array<string, mixed> $filters */
    private function download(User $user, array $filters = []): Spreadsheet
    {
        $response = $this->actingAs($user)->get(route('projections.export', $filters));
        $response->assertOk()->assertDownload();
        $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->spreadsheets[] = $spreadsheet;

        return $spreadsheet;
    }

    /** @return array{ManualProjection, UnitPlanning} */
    private function manual(string $plate, string $targetDate, string $systemDate = '2027-10-01', string $regionName = 'Own Region'): array
    {
        $region = Region::query()->firstOrCreate(['name' => $regionName]);
        $site = Site::query()->firstOrCreate(['name' => $regionName.' Site', 'region_id' => $region->id, 'region' => $regionName]);
        $unit = Unit::withoutEvents(fn (): Unit => Unit::query()->create([
            'site_id' => $site->id, 'customer' => 'Test', 'current_plate' => $plate,
            'brand' => 'Toyota', 'type' => 'TOYOTA HILUX DC', 'year' => 2024,
            'current_odo' => 1300, 'status' => 'active', 'has_odometer_reading' => false,
        ]));
        $item = PlanningItem::query()->firstOrCreate(['name' => 'Ban'], ['interval_km' => 5000, 'interval_days' => 365]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id, 'planning_item_id' => $item->id, 'last_done_km' => 1000,
            'last_done_date' => '2026-10-01', 'next_due_km' => 10000, 'next_due_date' => $systemDate,
        ]);
        $manual = ManualProjection::query()->create([
            'unit_id' => $unit->id, 'planning_item_id' => $item->id, 'unit_planning_id' => $planning->id,
            'target_date' => $targetDate, 'reason' => 'rusak', 'status' => 'active',
            'created_by' => User::factory()->create(['role' => UserRole::SpvHo])->id,
        ]);

        return [$manual, $planning];
    }
}
