<?php

namespace App\Services;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class PlanningBaselineSheetReader
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function read(string $path, string $sheetName): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($path);

        try {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if ($sheet === null) {
                throw new InvalidArgumentException('Sheet tidak ditemukan: '.$sheetName);
            }

            $required = [
                'plat nomor' => 'plate',
                'item maintenance' => 'item',
                'km saat diganti' => 'km',
                'tanggal terakhir diganti (dd/mm/yyyy)' => 'last_date',
                'next pergantian (dd/mm/yyyy)' => 'next_date',
                'catatan' => 'note',
            ];
            $columns = [];

            for ($column = 1; $column <= Coordinate::columnIndexFromString($sheet->getHighestColumn()); $column++) {
                $label = mb_strtolower(trim((string) $sheet->getCell([$column, 1])->getValue()));
                if (isset($required[$label])) {
                    if (isset($columns[$required[$label]])) {
                        throw new InvalidArgumentException('Header duplikat: '.$label);
                    }

                    $columns[$required[$label]] = $column;
                }
            }

            if (count($columns) !== count($required)) {
                throw new InvalidArgumentException('Header wajib tidak ada: '.implode(', ', array_keys(array_diff($required, $columns))));
            }

            $rows = [];
            for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
                $plate = $this->value($sheet->getCell([$columns['plate'], $row]));
                $item = $this->value($sheet->getCell([$columns['item'], $row]));
                if (trim((string) $plate) === '' && trim((string) $item) === '') {
                    break;
                }

                $lastDateCell = $sheet->getCell([$columns['last_date'], $row]);
                $lastDateValue = $this->value($lastDateCell);
                $lastDateExcel = Date::isDateTime($lastDateCell, $lastDateValue);
                $nextDateCell = $sheet->getCell([$columns['next_date'], $row]);
                $nextDateValue = $this->value($nextDateCell);
                $nextDateExcel = Date::isDateTime($nextDateCell, $nextDateValue);
                $rows[] = [
                    'row' => $row,
                    'plate' => $plate,
                    'item' => $item,
                    'km' => $this->value($sheet->getCell([$columns['km'], $row])),
                    'last_date' => $lastDateValue,
                    'last_date_excel' => $lastDateExcel,
                    'next_date' => $nextDateValue,
                    'next_date_excel' => $nextDateExcel,
                    'note' => $this->value($sheet->getCell([$columns['note'], $row])),
                ];
            }

            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function value(Cell $cell): mixed
    {
        return $cell->getDataType() === DataType::TYPE_FORMULA
            ? $cell->getCalculatedValue()
            : $cell->getValue();
    }
}
