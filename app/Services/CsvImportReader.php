<?php

namespace App\Services;

use SplFileObject;

class CsvImportReader
{
    /**
     * @return array<int, array<string, string>>
     */
    public function rows(string $path): array
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
                $headers = array_map(fn (string $header): string => trim($header, "\xEF\xBB\xBF \t\n\r\0\x0B"), $values);

                continue;
            }

            if ($headers === [] || implode('', $values) === '') {
                continue;
            }

            $rows[] = array_combine($headers, array_pad($values, count($headers), '')) ?: [];
        }

        return $rows;
    }
}
