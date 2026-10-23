<?php

declare(strict_types=1);

namespace App\Services\Import;

use DateTimeImmutable;
use RuntimeException;
use ZipArchive;

/**
 * Writes a single-sheet .xlsx with real dates and numbers, a bold frozen header row, column widths and an
 * auto-filter, using only PHP's zip extension. Rows are streamed to a temporary file, so memory stays flat.
 */
final class XlsxWriter
{
    public const TEXT = 'text';
    public const DATE = 'date';
    public const NUMBER = 'number';
    public const MONEY = 'money';

    private const STYLE = ['text' => 0, 'header' => 1, 'date' => 2, 'money' => 3, 'number' => 3];

    /**
     * @param array<int,string> $headers
     * @param array<int,string> $types column types (self::TEXT, DATE, NUMBER, MONEY), one per header
     * @param iterable<array<int,string|null>> $rows cell values in header order; dates as Y-m-d, numbers as plain numeric strings
     */
    public static function write(string $path, string $sheetName, array $headers, array $types, iterable $rows): int
    {
        $body = tmpfile();
        if ($body === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        fwrite($body, '<row r="1">');
        foreach ($headers as $i => $header) {
            fwrite($body, self::textCell(self::ref($i, 1), $header, self::STYLE['header']));
        }
        fwrite($body, '</row>');

        $count = 0;
        foreach ($rows as $row) {
            $rowNo = ++$count + 1;
            fwrite($body, '<row r="' . $rowNo . '">');
            foreach ($headers as $i => $_) {
                $value = $row[$i] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $ref = self::ref($i, $rowNo);
                $type = $types[$i] ?? self::TEXT;
                if ($type === self::DATE && ($serial = self::serial((string) $value)) !== null) {
                    fwrite($body, '<c r="' . $ref . '" s="' . self::STYLE['date'] . '"><v>' . $serial . '</v></c>');
                } elseif (($type === self::NUMBER || $type === self::MONEY) && is_numeric($value)) {
                    fwrite($body, '<c r="' . $ref . '" s="' . self::STYLE[$type] . '"><v>' . (float) $value . '</v></c>');
                } else {
                    fwrite($body, self::textCell($ref, (string) $value, self::STYLE['text']));
                }
            }
            fwrite($body, '</row>');
        }

        $sheetFile = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($sheetFile === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        $last = self::ref(count($headers) - 1, $count + 1);
        $out = fopen($sheetFile, 'wb');
        fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/><cols>');
        foreach ($headers as $i => $header) {
            $width = in_array($types[$i] ?? 'text', [self::DATE, self::NUMBER, self::MONEY], true) ? 16 : min(40, max(14, strlen($header) + 4));
            fwrite($out, '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>');
        }
        fwrite($out, '</cols><sheetData>');
        rewind($body);
        stream_copy_to_stream($body, $out);
        fwrite($out, '</sheetData><autoFilter ref="A1:' . $last . '"/></worksheet>');
        fclose($out);
        fclose($body);

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($sheetFile);
            throw new RuntimeException('Cannot create the Excel file.');
        }
        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook(self::safeSheetName($sheetName)));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFile($sheetFile, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($sheetFile);

        return $count;
    }

    private static function textCell(string $ref, string $text, int $style): string
    {
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        return '<c r="' . $ref . '" t="inlineStr"' . ($style > 0 ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">'
            . htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
    }

    /** Excel serial number of a Y-m-d date. */
    private static function serial(string $date): ?int
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
        if ($parsed === false) {
            return null;
        }
        return (int) (new DateTimeImmutable('1899-12-30'))->diff($parsed)->format('%r%a');
    }

    /** Column index 0-based and row 1-based to "A1" style. */
    private static function ref(int $col, int $row): string
    {
        $letters = '';
        for ($n = $col + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + (($n - 1) % 26)) . $letters;
        }
        return $letters . $row;
    }

    private static function safeSheetName(string $name): string
    {
        return substr((string) preg_replace('/[\[\]:*?\/\\\\]/', '', $name) ?: 'Sheet1', 0, 31);
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars($sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** Style 0 text, 1 bold header on a light fill, 2 yyyy-mm-dd, 3 #,##0.00. */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
