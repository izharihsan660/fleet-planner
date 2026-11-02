<?php

namespace App\Exports;

use App\Support\VehicleGroupResolver;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProjectionSummarySheet implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    /** @var array<int, array<int, mixed>> */
    private array $rows = [];

    /** @var array<int, int> */
    private array $subtotalRows = [];

    private int $headerRow;

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array<string, string>  $months
     * @param  array<int, string>  $info
     */
    public function __construct(Collection $items, array $months, array $info)
    {
        $ungroupedCount = $items->where('vehicle_group', VehicleGroupResolver::UNGROUPED)->unique('unit_id')->count();
        $this->rows = [
            ...array_map(fn (string $line): array => [$line], $info),
            [$ungroupedCount > 0 ? $ungroupedCount.' unit belum dikelompokkan' : null],
            ['Kelompok Model', 'Item', 'Lewat Due', 'Tanpa Tanggal', ...array_values($months), 'Total'],
        ];
        $this->headerRow = count($this->rows);
        $buckets = ['overdue', 'undated', ...array_keys($months)];
        $grandTotal = array_fill(0, count($buckets) + 1, 0);

        foreach ($items->groupBy('vehicle_group') as $vehicleGroup => $vehicleItems) {
            $subtotal = array_fill(0, count($buckets) + 1, 0);

            foreach ($vehicleItems->groupBy('planning_item_id')->sortKeys(SORT_NUMERIC) as $planningItems) {
                $counts = array_map(
                    fn (string $bucket): int => $planningItems->where('bucket', $bucket)->unique('unit_id')->count(),
                    $buckets,
                );
                $counts[] = $planningItems->unique('unit_id')->count();
                $this->rows[] = [$vehicleGroup, $planningItems->first()['planning_item_name'], ...$this->blankZeros($counts)];

                foreach ($counts as $column => $count) {
                    $subtotal[$column] += $count;
                    $grandTotal[$column] += $count;
                }
            }

            $this->rows[] = [$vehicleGroup, 'Subtotal', ...$this->blankZeros($subtotal)];
            $this->subtotalRows[] = count($this->rows);
        }

        $this->rows[] = ['Grand Total', null, ...$this->blankZeros($grandTotal)];
    }

    public function title(): string
    {
        return 'Rekap';
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    public function styles(Worksheet $sheet): void
    {
        $lastColumn = $sheet->getHighestColumn();

        foreach (range(1, $this->headerRow - 1) as $row) {
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        }

        $firstDataRow = $this->headerRow + 1;
        $sheet->freezePane('C'.$firstDataRow);
        $sheet->getStyle('A'.$this->headerRow.':'.$lastColumn.$this->headerRow)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
        ]);
        $sheet->getStyle('C'.$firstDataRow.':'.$lastColumn.count($this->rows))->getNumberFormat()->setFormatCode('#,##0');

        foreach ($this->subtotalRows as $row) {
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF4']],
            ]);
        }

        $sheet->getStyle('A'.count($this->rows).':'.$lastColumn.count($this->rows))->getFont()->setBold(true);
    }

    /**
     * @param  array<int, int>  $counts
     * @return array<int, int|null>
     */
    private function blankZeros(array $counts): array
    {
        return array_map(fn (int $count): ?int => $count === 0 ? null : $count, $counts);
    }
}
