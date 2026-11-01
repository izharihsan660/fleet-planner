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
        return ['Kelompok Model', 'Type Unit', 'No Polisi', 'Region', 'Site', 'Item', 'Est. Jatuh Tempo', 'Bucket', 'Pemicu', 'Est. KM Jatuh Tempo', 'Baseline KM Kosong'];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return $this->items->map(fn (array $item): array => [
            $item['vehicle_group'],
            $item['unit_type'],
            $item['plate_number'],
            $item['region_name'],
            $item['site_name'],
            $item['planning_item_name'],
            $item['estimated_due_date'] !== null ? Date::dateTimeToExcel(CarbonImmutable::parse($item['estimated_due_date'])) : null,
            $item['bucket_label'],
            match ($item['trigger']) {
                'km' => 'KM',
                'date' => 'Tanggal',
                'both' => 'KM & Tanggal',
            },
            $item['baseline_km_missing'] || $item['estimated_due_km'] === null ? null : (int) $item['estimated_due_km'],
            $item['baseline_km_missing'] ? 'Ya' : 'Tidak',
        ])->all();
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['G' => 'dd/mm/yyyy', 'J' => '#,##0'];
    }

    public function styles(Worksheet $sheet): void
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:K'.$sheet->getHighestRow());
        $sheet->getStyle('A1:K1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
        ]);
    }
}
