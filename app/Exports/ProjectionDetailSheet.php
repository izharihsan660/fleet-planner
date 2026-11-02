<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProjectionDetailSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    public function __construct(private readonly Collection $items) {}

    public function title(): string
    {
        return 'Detail';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Kelompok Model', 'No Polisi', 'Region', 'Site', 'Item', 'Est. Jatuh Tempo', 'Bucket', 'Hari Terlambat', 'Pemicu', 'Alasan Manual', 'Est. KM Jatuh Tempo', 'Baseline KM Kosong', 'Type Unit'];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return $this->items->map(fn (array $item): array => [
            $item['vehicle_group'],
            $item['plate_number'],
            $item['region_name'],
            $item['site_name'],
            $item['planning_item_name'],
            $item['estimated_due_date'] !== null ? Date::dateTimeToExcel(CarbonImmutable::parse($item['estimated_due_date'])) : null,
            $item['bucket_label'],
            $item['days_late'],
            match ($item['trigger']) {
                'km' => 'KM',
                'date' => 'Tanggal',
                'both' => 'KM & Tanggal',
                'manual' => 'Manual',
            },
            $item['trigger'] === 'manual' ? $item['manual_reason'] : null,
            $item['baseline_km_missing'] || $item['estimated_due_km'] === null ? null : (int) $item['estimated_due_km'],
            $item['baseline_km_missing'] ? 'Ya' : 'Tidak',
            $item['unit_type'],
        ])->all();
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['F' => 'dd/mm/yyyy', 'H' => '0', 'K' => '#,##0'];
    }

    public function styles(Worksheet $sheet): void
    {
        $sheet->freezePane('C2');
        $sheet->setAutoFilter('A1:M'.$sheet->getHighestRow());
        $sheet->getStyle('A1:M1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
        ]);

        foreach ($this->items->values() as $index => $item) {
            if ($item['bucket'] !== 'overdue') {
                continue;
            }

            $row = $index + 2;
            $sheet->getStyle('A'.$row.':M'.$row)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FDE2E2']],
            ]);

            if ($item['days_late'] > 180) {
                $sheet->getStyle('H'.$row)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FF0000']],
                ]);
            }
        }
    }
}
