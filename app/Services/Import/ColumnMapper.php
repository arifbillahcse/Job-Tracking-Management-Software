<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Works out which spreadsheet column is which job field.
 */
final class ColumnMapper
{
    /**
     * @param array<int,string> $headers the header row, by column index
     * @return array{mapping:array<int,string>,ignored:array<int,string>,missing:array<int,string>,duplicates:array<string,array<int,string>>}
     *   mapping: column index => field key; ignored: headers that matched no field;
     *   missing: required fields with no column; duplicates: field key => headers that all claim it
     */
    public static function map(array $headers): array
    {
        $mapping = [];
        $ignored = [];
        $claims = [];

        foreach ($headers as $index => $header) {
            $header = trim((string) $header);
            if ($header === '') {
                continue;
            }
            $field = JobFields::match($header);
            if ($field === null) {
                $ignored[] = $header;
                continue;
            }
            $claims[$field][] = $header;
            if (!in_array($field, $mapping, true)) {
                $mapping[$index] = $field; // the first column claiming a field wins; later ones are reported as duplicates
            }
        }

        $duplicates = array_filter($claims, static fn (array $headersFor): bool => count($headersFor) > 1);
        $missing = array_values(array_diff(JobFields::required(), array_values($mapping)));

        return ['mapping' => $mapping, 'ignored' => $ignored, 'missing' => $missing, 'duplicates' => $duplicates];
    }
}
