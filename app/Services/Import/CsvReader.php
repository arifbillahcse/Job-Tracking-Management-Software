<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Reads a CSV / TSV file as it comes out of Excel, Google Sheets or a text editor: UTF-8 (with or without BOM),
 * UTF-16 ("Unicode Text") or Windows-1252, comma / semicolon / tab separated, quoted fields with embedded
 * commas and line breaks. Row numbers count records with the header as row 1.
 */
final class CsvReader
{
    /**
     * @return array{headers:array<int,string>,rows:array<int,array{0:int,1:array<int,string>}>}
     * @throws ImportFileException
     */
    public static function read(string $path, int $maxRows, int $maxColumns): array
    {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            throw new ImportFileException('The file is empty or could not be read.');
        }

        $data = self::toUtf8($data);
        if (str_contains($data, "\0")) {
            throw new ImportFileException('This does not look like a CSV text file (it contains binary data).');
        }

        $delimiter = self::detectDelimiter($data);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $data);
        rewind($stream);

        $headers = null;
        $rows = [];
        $recordNo = 0;

        while (($record = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $recordNo++;
            if ($record === [null] || self::isBlank($record)) {
                continue;
            }
            $record = array_map(static fn (?string $cell): string => (string) $cell, $record);

            if ($headers === null) {
                $headers = array_map('trim', $record);
                if (count($headers) > $maxColumns) {
                    throw new ImportFileException("The file has more than {$maxColumns} columns.");
                }
                continue;
            }

            if (count($rows) >= $maxRows) {
                throw new ImportFileException('The file has more than ' . number_format($maxRows) . ' data rows. Split it into smaller files.');
            }
            $rows[] = [$recordNo, $record];
        }
        fclose($stream);

        if ($headers === null) {
            throw new ImportFileException('The file has no header row.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function toUtf8(string $data): string
    {
        if (str_starts_with($data, "\xEF\xBB\xBF")) {
            return substr($data, 3);
        }
        if (str_starts_with($data, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($data, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16BE');
        }
        if (!mb_check_encoding($data, 'UTF-8')) {
            return (string) mb_convert_encoding($data, 'UTF-8', 'Windows-1252'); // what older Excel saves
        }
        return $data;
    }

    /** Comma, semicolon or tab: whichever appears most often (outside quotes) in the first line. */
    private static function detectDelimiter(string $data): string
    {
        $counts = [',' => 0, ';' => 0, "\t" => 0];
        $quoted = false;
        $length = min(strlen($data), 4096);
        for ($i = 0; $i < $length; $i++) {
            $char = $data[$i];
            if ($char === '"') {
                $quoted = !$quoted;
            } elseif (!$quoted && ($char === "\n" || $char === "\r")) {
                break;
            } elseif (!$quoted && isset($counts[$char])) {
                $counts[$char]++;
            }
        }
        arsort($counts);
        return array_key_first($counts) !== null && current($counts) > 0 ? (string) array_key_first($counts) : ',';
    }

    /**
     * @param array<int,string|null> $record
     */
    private static function isBlank(array $record): bool
    {
        foreach ($record as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
