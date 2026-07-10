<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use SplFileObject;
use Stringable;

class MaintenanceImportReader
{
    /**
     * @return array<int, array<string, string>>
     */
    public function rows(string $path, string $type): array
    {
        return $this->isSpreadsheet($path)
            ? $this->spreadsheetRows($path, $type)
            : $this->csvRows($path);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function csvRows(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $headers = [];
        $rows = [];

        foreach ($file as $index => $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }

            $values = array_map(fn ($value): string => trim((string) $value), $row);

            if ($index === 0) {
                $headers = $this->normalizeHeaders($values);

                continue;
            }

            if ($headers === [] || implode('', $values) === '') {
                continue;
            }

            $rows[] = array_combine($headers, array_pad($values, count($headers), '')) ?: [];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function spreadsheetRows(string $path, string $type): array
    {
        $spreadsheet = IOFactory::load($path);
        $worksheet = $this->worksheetForType($spreadsheet->getAllSheets(), $type);
        $highestRow = $worksheet->getHighestDataRow();
        $highestColumn = $worksheet->getHighestDataColumn();
        $rawRows = $worksheet->rangeToArray("A1:{$highestColumn}{$highestRow}", '', true, true, false);
        $headerIndex = $this->findHeaderIndex($rawRows, $type);

        if ($headerIndex === null) {
            return [];
        }

        $headers = $this->normalizeHeaders($rawRows[$headerIndex]);
        $rows = [];

        foreach (array_slice($rawRows, $headerIndex + 1) as $row) {
            $values = array_map(fn ($value): string => trim((string) $value), $row);

            if (implode('', $values) === '') {
                continue;
            }

            $rows[] = array_combine($headers, array_pad($values, count($headers), '')) ?: [];
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /**
     * @param  array<int, Worksheet>  $worksheets
     */
    private function worksheetForType(array $worksheets, string $type): Worksheet
    {
        $expectedNames = $type === 'units'
            ? ['data unit', 'unit', 'units']
            : ['setup awal item', 'unit plannings', 'unit planning', 'planning'];

        foreach ($worksheets as $worksheet) {
            $title = $this->normalizeSheetName($worksheet->getTitle());

            foreach ($expectedNames as $expectedName) {
                if ($title === $expectedName) {
                    return $worksheet;
                }
            }
        }

        foreach ($worksheets as $worksheet) {
            if (! in_array($this->normalizeSheetName($worksheet->getTitle()), ['panduan', 'guide', 'help'], true)) {
                return $worksheet;
            }
        }

        return $worksheets[0];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function findHeaderIndex(array $rows, string $type): ?int
    {
        $requiredHeaders = $type === 'units'
            ? ['site', 'plat_nomor', 'kategori_kendaraan']
            : ['plat_nomor', 'nama_item'];

        foreach ($rows as $index => $row) {
            $headers = $this->normalizeHeaders($row);

            if (collect($requiredHeaders)->every(fn (string $header): bool => in_array($header, $headers, true))) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $headers
     * @return array<int, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        return array_map(function (mixed $header): string {
            $normalized = str((string) $header)
                ->replace("\xEF\xBB\xBF", '')
                ->trim()
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->toString();

            return match ($normalized) {
                'plat', 'plate', 'plate_number', 'no_polisi', 'nomor_polisi' => 'plat_nomor',
                'item', 'item_maintenance', 'planning_item' => 'nama_item',
                'kategori', 'vehicle_category' => 'kategori_kendaraan',
                'tipe', 'merk', 'type_brand' => 'tipe_merk',
                'odometer', 'current_odo', 'odo_saat_ini' => 'odometer_saat_ini',
                default => $normalized,
            };
        }, $headers);
    }

    private function normalizeSheetName(Stringable|string $sheetName): string
    {
        return str((string) $sheetName)
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    private function isSpreadsheet(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true);
    }
}
