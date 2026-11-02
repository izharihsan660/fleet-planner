<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exports\ProjectionExport;
use App\Models\InspectionLog;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Services\ProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class ProjectionExportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, Spreadsheet> */
    private array $spreadsheets = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00:00'));
    }

    protected function tearDown(): void
    {
        foreach ($this->spreadsheets as $spreadsheet) {
            $spreadsheet->disconnectWorksheets();
        }

        $this->travelBack();
        parent::tearDown();
    }

    public function test_spv_ho_can_download_an_xlsx_for_all_regions(): void
    {
        $firstSite = $this->createSite('Kalimantan', 'Site A');
        $secondSite = $this->createSite('Sulawesi', 'Site B');
        $item = $this->createItem('Filter Oli');
        $this->createPlanning($this->createUnit($firstSite, 'KT 1001 AA'), $item, '2026-11-10');
        $this->createPlanning($this->createUnit($secondSite, 'DD 1002 BB'), $item, '2026-11-20');
        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]), [], 'Proyeksi_1bln_Semua_20261031.xlsx');

        $this->assertSame(['Rekap', 'Detail'], $spreadsheet->getSheetNames());
        $this->assertEqualsCanonicalizing(['Kalimantan', 'Sulawesi'], array_column($this->detailRows($spreadsheet), 2));
        $this->assertStringContainsString('Filter: Semua Region / Semua Site', $spreadsheet->getSheetByName('Rekap')->getCell('A1')->getValue());
        $this->assertSame(2, $this->grandTotal($spreadsheet));
    }

    public function test_superadmin_export_obeys_the_period_region_and_site_filters(): void
    {
        $selectedSite = $this->createSite('Kalimantan', 'Site A');
        $otherSite = $this->createSite('Kalimantan', 'Site B');
        $outsideSite = $this->createSite('Sulawesi', 'Site C');
        $item = $this->createItem('Filter Oli');

        foreach ([[$selectedSite, 'KT 1001 AA'], [$otherSite, 'KT 1002 AA'], [$outsideSite, 'DD 1003 AA']] as [$site, $plate]) {
            $this->createPlanning($this->createUnit($site, $plate), $item, '2026-12-10');
        }

        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $filters = ['months' => 2, 'region_id' => $selectedSite->region_id, 'site_id' => $selectedSite->id];
        $spreadsheet = $this->download($user, $filters, 'Proyeksi_2bln_Site A_20261031.xlsx');

        $this->assertSame(['KT 1001 AA'], array_column($this->detailRows($spreadsheet), 1));
        $this->assertStringContainsString('Periode: 31 Okt 2026', $spreadsheet->getSheetByName('Rekap')->getCell('A1')->getValue());
        $this->assertStringContainsString('Filter: Kalimantan / Site A', $spreadsheet->getSheetByName('Rekap')->getCell('A1')->getValue());
        $this->assertSame([], $this->detailRows($this->download($user, [...$filters, 'months' => 1])));
    }

    public function test_region_and_site_exports_match_the_selected_scope_and_filename(): void
    {
        $firstSite = $this->createSite('Kalimantan', 'Site A');
        $secondSite = $this->createSite('Kalimantan', 'Site B');
        $outsideSite = $this->createSite('Sulawesi', 'Site C');
        $item = $this->createItem('Filter Oli');

        foreach ([[$firstSite, 'KT 1001 AA'], [$secondSite, 'KT 1002 AA'], [$outsideSite, 'DD 1003 AA']] as [$site, $plate]) {
            $this->createPlanning($this->createUnit($site, $plate), $item, '2026-11-10');
        }

        $scenarios = [
            [['region_id' => $firstSite->region_id], ['KT 1001 AA', 'KT 1002 AA'], 'Kalimantan'],
            [['site_id' => $secondSite->id], ['KT 1002 AA'], 'Site B'],
            [['region_id' => $firstSite->region_id, 'site_id' => $outsideSite->id], ['DD 1003 AA'], 'Site C'],
            [['region_id' => 'invalid', 'site_id' => $outsideSite->id], ['DD 1003 AA'], 'Site C'],
        ];

        foreach ([UserRole::Superadmin, UserRole::SpvHo] as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach ($scenarios as [$filters, $expectedPlates, $filenameLocation]) {
                $spreadsheet = $this->download($user, $filters, 'Proyeksi_1bln_'.$filenameLocation.'_20261031.xlsx');
                $this->assertSame($expectedPlates, array_column($this->detailRows($spreadsheet), 1));
                $this->assertSame(count($expectedPlates), $this->grandTotal($spreadsheet));
            }
        }
    }

    public function test_export_exclusion_summary_obeys_region_filter(): void
    {
        $ownSite = $this->createSite('Kalimantan', 'Own Site');
        $outsideSite = $this->createSite('Sulawesi', 'Outside Site');
        $item = $this->createItem('Tanpa Sumber');

        foreach ([[$ownSite, 'KT 1001 AA'], [$outsideSite, 'DD 1002 AA']] as [$site, $plate]) {
            $this->createPlanning($this->createUnit($site, $plate), $item, '2026-11-10', [
                'last_done_km' => 0, 'last_done_date' => null, 'due_manually_set' => false, 'is_estimated' => false,
            ]);
        }

        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        foreach ([[$ownSite->region_id, 1], [null, 2]] as [$regionId, $expectedCount]) {
            $spreadsheet = $this->download($user, ['region_id' => $regionId]);
            $this->assertSame($expectedCount.' item tidak diproyeksikan (tanggal due tanpa data terakhir ganti)', $spreadsheet->getSheetByName('Rekap')->getCell('A2')->getValue());
        }
    }

    public function test_planner_area_cannot_override_their_region_even_with_an_invalid_region_parameter(): void
    {
        $ownSite = $this->createSite('Kalimantan', 'Own Site');
        $outsideSite = $this->createSite('Sulawesi', 'Outside Site');
        $item = $this->createItem('Filter Oli');
        $this->createPlanning($this->createUnit($ownSite, 'KT 1001 AA'), $item, '2026-11-10');
        $this->createPlanning($this->createUnit($outsideSite, 'DD 1002 BB'), $item, '2026-11-10');
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $ownSite->region_id]);

        foreach ([$outsideSite->region_id, 'not-a-region'] as $regionParameter) {
            $spreadsheet = $this->download($planner, ['region_id' => $regionParameter], 'Proyeksi_1bln_Kalimantan_20261031.xlsx');
            $this->assertSame(['KT 1001 AA'], array_column($this->detailRows($spreadsheet), 1));
            $this->assertSame(1, $this->grandTotal($spreadsheet));
        }

        $spreadsheet = $this->download($planner, ['region_id' => $outsideSite->region_id, 'site_id' => $outsideSite->id]);
        $this->assertSame([], $this->detailRows($spreadsheet));

        $spreadsheet = $this->download($planner, ['region_id' => $outsideSite->region_id, 'site_id' => $ownSite->id], 'Proyeksi_1bln_Own Site_20261031.xlsx');
        $this->assertSame(['KT 1001 AA'], array_column($this->detailRows($spreadsheet), 1));
    }

    public function test_mechanic_and_planner_without_a_region_are_forbidden(): void
    {
        foreach ([UserRole::Mekanik, UserRole::PlannerArea] as $role) {
            $user = User::factory()->create(['role' => $role, 'region_id' => null]);
            $this->actingAs($user)->get(route('projections.export'))->assertForbidden();
            $this->actingAs($user)->getJson(route('projections.export', ['months' => 'invalid']))->assertForbidden();
        }
    }

    public function test_export_requires_authentication_and_valid_filters(): void
    {
        $this->get(route('projections.export'))->assertRedirect(route('login'));
        $user = User::factory()->create(['role' => UserRole::SpvHo]);

        foreach ([['months' => 4], ['months' => 0], ['region_id' => 999], ['site_id' => 999]] as $filters) {
            $this->actingAs($user)->getJson(route('projections.export', $filters))->assertUnprocessable();
        }
    }

    public function test_calendar_buckets_include_overdue_today_month_boundaries_and_the_period_end(): void
    {
        $site = $this->createSite();
        $item = $this->createItem('Filter Oli');

        foreach (['2026-10-30', '2026-10-31', '2026-11-01', '2026-12-31', '2027-01-29', '2027-01-30'] as $index => $dueDate) {
            $this->createPlanning($this->createUnit($site, 'KT 100'.$index.' AA'), $item, $dueDate);
        }

        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]), ['months' => 3]);
        $summary = $spreadsheet->getSheetByName('Rekap');
        $this->assertSame(['Kelompok Model', 'Item', 'Lewat Due', 'Tanpa Tanggal', 'Okt 2026 (31 Okt)', 'Nov 2026', 'Des 2026', 'Jan 2027 (s/d 29 Jan)', 'Total'], $summary->rangeToArray('A6:I6')[0]);
        $this->assertSame(['HILUX 2.4 DC', 'Filter Oli', 1, null, 1, 1, 1, 1, 5], $summary->rangeToArray('A7:I7', null, false, false)[0]);
        $this->assertSame(['Lewat Due', 'Okt 2026', 'Nov 2026', 'Des 2026', 'Jan 2027'], array_column($this->detailRows($spreadsheet), 6));
        $this->assertSame(count($this->detailRows($spreadsheet)), $this->grandTotal($spreadsheet));
    }

    public function test_calendar_month_labels_distinguish_partial_and_full_months(): void
    {
        $user = User::factory()->create(['role' => UserRole::SpvHo]);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00'));
        $partial = $this->download($user)->getSheetByName('Rekap');
        $this->assertSame(['Okt 2026 (15 Okt - 31 Okt)', 'Nov 2026 (s/d 14 Nov)'], $partial->rangeToArray('E6:F6')[0]);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00'));
        $full = $this->download($user)->getSheetByName('Rekap');
        $this->assertSame(['Nov 2026', 'Des 2026 (s/d 1 Des)'], $full->rangeToArray('E6:F6')[0]);
    }

    public function test_dates_without_a_source_are_absent_from_both_sheets_but_valid_date_sources_are_kept(): void
    {
        $unit = $this->createUnit($this->createSite(), 'KT 1001 AA');

        // TODO: kembalikan kasus NULL setelah migration last_done_km nullable di-deploy
        foreach ([0, 0] as $index => $baselineKm) {
            $this->createPlanning($unit, $this->createItem('Tanpa Sumber '.$index), '2026-11-10', [
                'last_done_km' => $baselineKm, 'last_done_date' => null, 'due_manually_set' => false, 'is_estimated' => false,
            ]);
        }

        foreach (['due_manually_set', 'is_estimated', 'last_done_date'] as $source) {
            $this->createPlanning($unit, $this->createItem($source), '2026-11-10', [
                // TODO: kembalikan kasus NULL setelah migration last_done_km nullable di-deploy
                'last_done_km' => 0,
                'last_done_date' => $source === 'last_done_date' ? '2026-10-01' : null,
                'due_manually_set' => $source === 'due_manually_set',
                'is_estimated' => $source === 'is_estimated',
            ]);
        }

        $this->createPlanning($unit, $this->createItem('Dikecualikan'), '2026-11-10', ['is_excluded' => true]);
        $sourceResult = app(ProjectionService::class)->calculate(1);
        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));
        $summary = $spreadsheet->getSheetByName('Rekap');
        $details = $this->detailRows($spreadsheet);

        $this->assertSame(2, $sourceResult['excluded_date_without_source_count']);
        $this->assertSame('2 item tidak diproyeksikan (tanggal due tanpa data terakhir ganti)', $summary->getCell('A2')->getValue());
        $this->assertSame('Angka per item = jumlah unit. Subtotal = jumlah kebutuhan (unit × item).', $summary->getCell('A3')->getValue());
        $this->assertEqualsCanonicalizing(['due_manually_set', 'is_estimated', 'last_done_date'], array_column($details, 4));
        $this->assertStringNotContainsString('Tanpa Sumber', json_encode($summary->toArray()));
        $this->assertStringNotContainsString('Dikecualikan', json_encode($summary->toArray()));
        $this->assertSame([null, null, null], array_column($details, 9));
        $this->assertSame([null, null, null], array_column($details, 10));
        $this->assertSame(['Ya', 'Ya', 'Ya'], array_column($details, 11));
        $this->assertSame(count($sourceResult['by_unit'][0]['items']), $this->grandTotal($spreadsheet));
        $this->assertSame(count($details), $this->grandTotal($spreadsheet));
    }

    public function test_recap_groups_type_aliases_orders_fallbacks_last_and_counts_distinct_ungrouped_units(): void
    {
        $site = $this->createSite();
        $firstItem = $this->createItem('Z Item Pertama');
        $secondItem = $this->createItem('A Item Kedua');
        $originalType = "  toyota \tHILUX  DC  ";
        $firstUnit = $this->createUnit($site, 'KT 1002 AA', ['brand' => 'Brand Diabaikan', 'type' => $originalType]);
        $secondUnit = $this->createUnit($site, 'KT 1001 AA', ['type' => 'Toyota All New Hilux 2,4G DC 4X4 M']);
        $unknownUnit = $this->createUnit($site, 'KT 1003 AA', ['brand' => 'Toyota', 'type' => '  ']);
        $agyaUnit = $this->createUnit($site, 'KT 1004 AA', ['type' => 'Toyota All New Agya 1,2 G AT']);
        $ungroupedUnit = $this->createUnit($site, 'KT 1005 AA', ['type' => 'MODEL BARU']);
        $undatedUnit = $this->createUnit($site, 'KT 1006 AA', ['type' => 'MODEL LAIN', 'has_odometer_reading' => true]);
        $this->createUnit($site, 'KT 1007 AA', ['type' => 'TIDAK DIPROYEKSIKAN']);
        $this->createPlanning($firstUnit, $firstItem, '2026-11-20');
        $this->createPlanning($firstUnit, $secondItem, '2026-11-10');
        $this->createPlanning($secondUnit, $firstItem, '2026-11-15');
        $this->createPlanning($unknownUnit, $firstItem, '2026-11-10');
        $this->createPlanning($agyaUnit, $firstItem, '2026-11-10');
        $this->createPlanning($ungroupedUnit, $firstItem, '2026-11-10');
        $this->createPlanning($ungroupedUnit, $secondItem, '2026-10-30');
        $this->createPlanning($undatedUnit, $firstItem, null, ['next_due_km' => 1200]);
        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));
        $summary = $spreadsheet->getSheetByName('Rekap');
        $details = $this->detailRows($spreadsheet);

        $this->assertSame('2 unit belum dikelompokkan', $summary->getCell('A5')->getValue());
        $this->assertSame(['AGYA 1.2', 'HILUX 2.4 DC', 'BELUM DIKELOMPOKKAN', 'TIDAK DIKETAHUI'], array_values(array_unique(array_column($details, 0))));
        $this->assertSame('AGYA 1.2', $summary->getCell('A7')->getValue());
        $this->assertSame('HILUX 2.4 DC', $summary->getCell('A9')->getValue());
        $this->assertSame('Z Item Pertama', $summary->getCell('B9')->getValue());
        $this->assertSame('A Item Kedua', $summary->getCell('B10')->getValue());
        $this->assertSame(2, $summary->getCell('G9')->getValue());
        $this->assertSame('Subtotal', $summary->getCell('B11')->getValue());
        $this->assertSame(3, $summary->getCell('G11')->getValue());
        $this->assertNull($summary->getCell('C9')->getValue());
        $this->assertNull($summary->getCell('D9')->getValue());
        $this->assertNull($summary->getCell('E9')->getValue());
        $this->assertSame('BELUM DIKELOMPOKKAN', $summary->getCell('A12')->getValue());
        $this->assertSame(['BELUM DIKELOMPOKKAN', 'Z Item Pertama', null, 1, null, 1, 2], $summary->rangeToArray('A12:G12', null, false, false)[0]);
        $this->assertSame('TIDAK DIKETAHUI', $summary->getCell('A15')->getValue());
        $this->assertSame(['KT 1004 AA', 'KT 1001 AA', 'KT 1002 AA', 'KT 1002 AA', 'KT 1005 AA', 'KT 1005 AA', 'KT 1006 AA', 'KT 1003 AA'], array_column($details, 1));
        $this->assertSame(['A Item Kedua', 'Z Item Pertama'], array_column(array_slice($details, 2, 2), 4));
        $this->assertSame($originalType, $details[2][12]);
        $this->assertSame('  ', $details[7][12]);
        $this->assertSame('Tanpa Tanggal', $details[6][6]);
        $this->assertSame(8, $this->grandTotal($spreadsheet));
        $this->assertSame(count($details), $this->grandTotal($spreadsheet));
    }

    public function test_export_calls_the_service_once_and_does_not_count_duplicate_unit_items_or_part_quantities(): void
    {
        $unit = $this->createUnit($this->createSite(), 'KT 1001 AA');
        $this->createPlanning($unit, $this->createItem('Ban'), '2026-11-10');
        $result = app(ProjectionService::class)->calculate(1);
        $result['by_unit'][0]['items'][0]['estimated_quantity'] = 4;
        $result['by_unit'][] = $result['by_unit'][0];
        $unitIds = array_column($result['by_unit'], 'unit_id');
        $units = app(ProjectionService::class)->unitsForExport($unitIds);
        $this->mock(ProjectionService::class, function (MockInterface $mock) use ($result, $unitIds, $units): void {
            $mock->shouldReceive('calculate')->once()->with(1, null, null, \Mockery::type(User::class))->andReturn($result);
            $mock->shouldReceive('unitsForExport')->once()->with($unitIds)->andReturn($units);
        });
        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));

        $this->assertCount(1, $this->detailRows($spreadsheet));
        $this->assertSame(1, $this->grandTotal($spreadsheet));
    }

    public function test_export_reads_group_aliases_from_config_and_preserves_the_original_type(): void
    {
        config(['vehicle_groups' => ['CUSTOM GROUP' => ["  Toyota  \t Hilux DC  "]]]);
        $originalType = '  toyota hilux   dc ';
        $unit = $this->createUnit($this->createSite(), 'KT 1001 AA', ['brand' => 'Brand Diabaikan', 'type' => $originalType]);
        $this->createPlanning($unit, $this->createItem('Filter Oli'), '2026-11-10');
        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));

        $this->assertSame('CUSTOM GROUP', $spreadsheet->getSheetByName('Rekap')->getCell('A7')->getValue());
        $this->assertSame('CUSTOM GROUP', $this->detailRows($spreadsheet)[0][0]);
        $this->assertSame($originalType, $this->detailRows($spreadsheet)[0][12]);
        $this->assertNull($spreadsheet->getSheetByName('Rekap')->getCell('A5')->getValue());
    }

    public function test_detail_preserves_service_trigger_dates_numeric_km_and_excel_formatting(): void
    {
        $site = $this->createSite();
        $unit = $this->createUnit($site, 'KT 1001 AA', ['has_odometer_reading' => true]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        foreach ([['2026-10-30', 1200], ['2026-10-31', 1300]] as [$date, $odometer]) {
            InspectionLog::query()->create(['unit_id' => $unit->id, 'mechanic_id' => $mechanic->id, 'inspection_date' => $date, 'odometer' => $odometer]);
        }

        $this->createPlanning($unit, $this->createItem('KM Only'), null, ['next_due_km' => 1500]);
        $this->createPlanning($unit, $this->createItem('Both'), '2026-11-05', ['next_due_km' => 1500]);
        // TODO: kembalikan kasus NULL setelah migration last_done_km nullable di-deploy
        $this->createPlanning($unit, $this->createItem('Date Only'), '2026-11-06', ['last_done_km' => 0, 'next_due_km' => 999999]);
        $this->createPlanning($unit, $this->createItem('KM Zero'), null, ['next_due_km' => 0]);
        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));
        $detail = $spreadsheet->getSheetByName('Detail');
        $rows = array_column($this->detailRows($spreadsheet), null, 4);

        $this->assertSame(['Kelompok Model', 'No Polisi', 'Region', 'Site', 'Item', 'Est. Jatuh Tempo', 'Bucket', 'Hari Terlambat', 'Pemicu', 'Alasan Manual', 'Est. KM Jatuh Tempo', 'Baseline KM Kosong', 'Type Unit'], $detail->rangeToArray('A1:M1')[0]);
        $this->assertSame('KM', $rows['KM Only'][8]);
        $this->assertSame('KM & Tanggal', $rows['Both'][8]);
        $this->assertSame('Tanggal', $rows['Date Only'][8]);
        $this->assertSame((float) Date::dateTimeToExcel(CarbonImmutable::parse('2026-11-02')), (float) $rows['Both'][5]);
        $this->assertNull($rows['KM Only'][9]);
        $this->assertSame(1500, $rows['KM Only'][10]);
        $this->assertNull($rows['Date Only'][10]);
        $this->assertSame(0, $rows['KM Zero'][10]);
        $this->assertSame(DataType::TYPE_NUMERIC, $detail->getCell('F2')->getDataType());
        $this->assertSame('31/10/2026', $detail->getCell('F2')->getFormattedValue());
        $this->assertSame('dd/mm/yyyy', $detail->getStyle('F2')->getNumberFormat()->getFormatCode());
        $this->assertSame('0', $detail->getStyle('H2')->getNumberFormat()->getFormatCode());
        $this->assertSame('#,##0', $detail->getStyle('K2')->getNumberFormat()->getFormatCode());
        $this->assertSame('C2', $detail->getFreezePane());
        $this->assertSame('A1:M5', $detail->getAutoFilter()->getRange());
        $this->assertTrue($detail->getStyle('A1')->getFont()->getBold());
        $this->assertTrue($detail->getStyle('M1')->getFont()->getBold());
        $this->assertSame('1E3A5F', $detail->getStyle('M1')->getFill()->getStartColor()->getRGB());
        $this->assertGreaterThan(10, $detail->getColumnDimension('A')->getWidth());
        $this->assertSame('C7', $spreadsheet->getSheetByName('Rekap')->getFreezePane());
        $this->assertTrue($spreadsheet->getSheetByName('Rekap')->getStyle('A6')->getFont()->getBold());
        $this->assertGreaterThan(4, $spreadsheet->getSheetByName('Rekap')->getColumnDimension('B')->getWidth());
    }

    public function test_detail_counts_integer_days_late_and_highlights_only_overdue_rows(): void
    {
        $site = $this->createSite();
        $item = $this->createItem('Filter Oli');
        $daysLateScenarios = [1, 180, 181, 400, 0, -1, null];

        foreach ($daysLateScenarios as $index => $daysLate) {
            $unit = $this->createUnit($site, 'KT 500'.$index.' AA', ['has_odometer_reading' => $daysLate === null]);
            $dueDate = $daysLate !== null ? CarbonImmutable::today()->subDays($daysLate)->toDateString() : null;
            $this->createPlanning($unit, $item, $dueDate, ['next_due_km' => $daysLate === null ? 1200 : 10000]);
        }

        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));
        $detail = $spreadsheet->getSheetByName('Detail');
        $this->assertCount(count($daysLateScenarios), $this->detailRows($spreadsheet));

        foreach ($daysLateScenarios as $index => $daysLate) {
            $row = $index + 2;
            $isOverdue = $daysLate !== null && $daysLate > 0;
            $cell = $detail->getCell('H'.$row);
            $this->assertSame($isOverdue ? $daysLate : null, $cell->getValue());
            $this->assertSame($isOverdue, $detail->getCell('G'.$row)->getValue() === 'Lewat Due');
            $this->assertSame('0', $detail->getStyle('H'.$row)->getNumberFormat()->getFormatCode());

            if ($isOverdue) {
                $this->assertIsInt($cell->getValue());
                $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
            }

            foreach (range('A', 'M') as $column) {
                $fill = $detail->getStyle($column.$row)->getFill();
                $this->assertSame($isOverdue, $fill->getStartColor()->getRGB() === 'FDE2E2');

                if ($isOverdue) {
                    $this->assertSame(Fill::FILL_SOLID, $fill->getFillType());
                }
            }

            $font = $detail->getStyle('H'.$row)->getFont();
            $this->assertSame($isOverdue && $daysLate > 180, $font->getBold());
            $this->assertSame($isOverdue && $daysLate > 180, $font->getColor()->getRGB() === 'FF0000');
        }
    }

    public function test_detail_days_late_counts_calendar_days_across_year_and_leap_day_boundaries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-03-01 23:59:59'));
        $site = $this->createSite();
        $item = $this->createItem('Filter Oli');

        foreach (['2028-02-29', '2028-02-28', '2027-12-31'] as $index => $dueDate) {
            $this->createPlanning($this->createUnit($site, 'KT 600'.$index.' AA'), $item, $dueDate);
        }

        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));
        $this->assertSame([1, 2, 61], array_column($this->detailRows($spreadsheet), 7));
    }

    public function test_km_projection_without_an_estimated_date_uses_the_undated_bucket_without_fabricating_a_date(): void
    {
        $unit = $this->createUnit($this->createSite(), 'KT 1001 AA', ['has_odometer_reading' => true]);
        $this->createPlanning($unit, $this->createItem('KM Only'), null, ['next_due_km' => 1200]);
        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));
        $row = $this->detailRows($spreadsheet)[0];
        $summary = $spreadsheet->getSheetByName('Rekap');

        $this->assertNull($row[5]);
        $this->assertSame('Tanpa Tanggal', $row[6]);
        $this->assertNull($row[7]);
        $this->assertSame('KM', $row[8]);
        $this->assertSame(['HILUX 2.4 DC', 'KM Only', null, 1, null, null, 1], $summary->rangeToArray('A7:G7', null, false, false)[0]);
        $this->assertSame(1, $this->grandTotal($spreadsheet));
        $this->assertNull($summary->getCell('A5')->getValue());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_synchronous_download_includes_all_200_units_and_18_items_without_pagination(): void
    {
        $site = $this->createSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $items = collect(range(1, 18))->map(fn (int $number): PlanningItem => $this->createItem('Item '.$number));

        for ($index = 0; $index < 200; $index++) {
            $unit = $this->createUnit($site, 'KT '.$index.' AA', ['has_odometer_reading' => true]);

            foreach ([['2026-10-30', 1200], ['2026-10-31', 1300]] as [$date, $odometer]) {
                InspectionLog::query()->create(['unit_id' => $unit->id, 'mechanic_id' => $mechanic->id, 'inspection_date' => $date, 'odometer' => $odometer]);
            }

            foreach ($items as $item) {
                $this->createPlanning($unit, $item, '2026-11-10');
            }
        }

        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]), ['months' => 3]);

        $this->assertCount(3600, $this->detailRows($spreadsheet));
        $this->assertSame(3600, $this->grandTotal($spreadsheet));
        $this->assertSame(200, $spreadsheet->getSheetByName('Rekap')->getCell('F7')->getValue());
    }

    public function test_metadata_is_eager_loaded_in_three_queries_and_strings_cannot_become_excel_formulas(): void
    {
        $site = $this->createSite();
        $item = $this->createItem('=1+1');

        for ($index = 0; $index < 30; $index++) {
            $unit = $this->createUnit($site, 'KT '.$index.' AA', ['brand' => '', 'type' => '=2+2']);
            $this->createPlanning($unit, $item, '2026-11-10');
        }

        $result = app(ProjectionService::class)->calculate(1);
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $units = app(ProjectionService::class)->unitsForExport(array_column($result['by_unit'], 'unit_id'));
            (new ProjectionExport($result, $units, CarbonImmutable::today(), 'Semua Region / Semua Site'))->sheets();
            $this->assertCount(3, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $spreadsheet = $this->download(User::factory()->create(['role' => UserRole::SpvHo]));
        $detail = $spreadsheet->getSheetByName('Detail');
        $this->assertCount(30, $this->detailRows($spreadsheet));
        $this->assertSame(30, $this->grandTotal($spreadsheet));
        $this->assertSame(DataType::TYPE_STRING, $detail->getCell('A2')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $detail->getCell('B2')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $detail->getCell('M2')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $detail->getCell('E2')->getDataType());
        $this->assertSame('BELUM DIKELOMPOKKAN', $detail->getCell('A2')->getValue());
        $this->assertSame('KT 0 AA', $detail->getCell('B2')->getValue());
        $this->assertSame('=2+2', $detail->getCell('M2')->getValue());
        $this->assertSame('=1+1', $detail->getCell('E2')->getValue());
    }

    public function test_frontend_download_uses_an_anchor_with_active_filters_not_an_inertia_visit(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Projections/Index.tsx'));
        $this->assertStringContainsString("route('projections.export', {", $source);
        $this->assertStringContainsString('months: filters.months', $source);
        $this->assertStringContainsString('region_id: filters.region_id', $source);
        $this->assertStringContainsString('site_id: filters.site_id', $source);
        $this->assertStringContainsString('filters.site_id !== null ? { site_id: filters.site_id } :', $source);
        $this->assertStringContainsString('<Button asChild variant="outline"', $source);
        $this->assertStringContainsString('<a href={exportUrl}>', $source);
        $this->assertStringContainsString('<Download ', $source);
        $this->assertDoesNotMatchRegularExpression('/router\.[a-z]+\(exportUrl|<Link[^>]+href=\{exportUrl\}/', $source);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function download(User $user, array $filters = [], ?string $filename = null): Spreadsheet
    {
        $response = $this->actingAs($user)->get(route('projections.export', $filters));
        $response->assertOk()->assertDownload($filename);
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertFalse($response->headers->has('X-Inertia'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->spreadsheets[] = $spreadsheet;
        $this->assertSummaryTotalsMatchDetails($spreadsheet);

        return $spreadsheet;
    }

    private function assertSummaryTotalsMatchDetails(Spreadsheet $spreadsheet): void
    {
        $rows = array_slice($spreadsheet->getSheetByName('Rekap')->toArray(null, false, false), 6);

        foreach ($rows as $row) {
            $counts = array_slice($row, 2);
            $total = array_pop($counts);
            $this->assertSame((int) $total, array_sum($counts), 'Bucket total mismatch: '.$row[0].' / '.$row[1]);
        }

        $this->assertSame(count($this->detailRows($spreadsheet)), $this->grandTotal($spreadsheet));
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function detailRows(Spreadsheet $spreadsheet): array
    {
        return array_slice($spreadsheet->getSheetByName('Detail')->toArray(null, false, false), 1);
    }

    private function grandTotal(Spreadsheet $spreadsheet): int
    {
        $sheet = $spreadsheet->getSheetByName('Rekap');

        return (int) $sheet->getCell($sheet->getHighestColumn().$sheet->getHighestRow())->getValue();
    }

    private function createSite(string $regionName = 'Kalimantan', string $siteName = 'Site A'): Site
    {
        $region = Region::query()->firstOrCreate(['name' => $regionName]);

        return Site::query()->create(['name' => $siteName, 'region_id' => $region->id, 'region' => $region->name]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createUnit(Site $site, string $plate, array $attributes = []): Unit
    {
        return Unit::withoutEvents(fn (): Unit => Unit::query()->create([
            'site_id' => $site->id, 'customer' => 'Customer Test', 'current_plate' => $plate,
            'brand' => 'Toyota', 'type' => 'TOYOTA HILUX DC', 'year' => 2024, 'current_odo' => 1300,
            'has_odometer_reading' => false, 'status' => 'active', ...$attributes,
        ]));
    }

    private function createItem(string $name): PlanningItem
    {
        return PlanningItem::query()->create(['name' => $name, 'interval_km' => 5000, 'interval_days' => 30]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPlanning(Unit $unit, PlanningItem $item, ?string $dueDate, array $attributes = []): UnitPlanning
    {
        return UnitPlanning::query()->create([
            'unit_id' => $unit->id, 'planning_item_id' => $item->id,
            'last_done_km' => 1000, 'last_done_date' => '2026-10-01',
            'next_due_km' => 10000, 'next_due_date' => $dueDate, ...$attributes,
        ]);
    }
}
