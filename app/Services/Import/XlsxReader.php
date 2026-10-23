<?php

declare(strict_types=1);

namespace App\Services\Import;

use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/**
 * Reads the first worksheet of an .xlsx workbook using only PHP's zip and XML extensions.
 *
 * Defensive by design: an .xlsx is a zip of XML files, so before reading anything it limits the number of
 * entries and their unpacked size (zip bombs), refuses XML that declares a DOCTYPE or entities (XXE / entity
 * expansion), disables network access in the XML parser, and caps rows and columns. Formulas are never
 * evaluated; the cached value Excel stored is read as plain data.
 */
final class XlsxReader
{
    private const MAX_ENTRIES = 200;
    private const MAX_ENTRY_BYTES = 30 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 60 * 1024 * 1024;
    private const MAX_ROW_ELEMENTS = 200000;
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public static function available(): bool
    {
        return class_exists(ZipArchive::class) && class_exists(XMLReader::class);
    }

    /**
     * @return array{headers:array<int,string>,rows:array<int,array{0:int,1:array<int,string>}>}
     * @throws ImportFileException
     */
    public static function read(string $path, int $maxRows, int $maxColumns): array
    {
        if (!self::available()) {
            throw new ImportFileException('Excel files are not supported on this server (the PHP zip extension is missing). Save the sheet as CSV instead.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportFileException('This is not a valid Excel (.xlsx) file.');
        }

        try {
            self::checkArchive($zip);
            $sheetPath = self::firstSheetPath($zip);
            $shared = self::sharedStrings($zip);
            $rows = self::sheetRows($zip, $sheetPath, $shared, $maxRows + 1, $maxColumns);
        } finally {
            $zip->close();
        }

        if ($rows === []) {
            throw new ImportFileException('The first worksheet is empty.');
        }

        $header = array_shift($rows);
        $headers = array_map(static fn (string $h): string => trim($h), $header[1]);

        if (count($rows) > $maxRows) {
            throw new ImportFileException('The file has more than ' . number_format($maxRows) . ' data rows. Split it into smaller files.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function checkArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new ImportFileException('This Excel file has too many parts to be a normal workbook.');
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new ImportFileException('This is not a valid Excel (.xlsx) file.');
            }
            if ($stat['size'] > self::MAX_ENTRY_BYTES) {
                throw new ImportFileException('This Excel file unpacks to something far larger than a normal workbook.');
            }
            $total += $stat['size'];
        }
        if ($total > self::MAX_TOTAL_BYTES) {
            throw new ImportFileException('This Excel file unpacks to something far larger than a normal workbook.');
        }

        if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
            throw new ImportFileException('This is not an Excel workbook (.xlsx).');
        }
    }

    /** Part contents with the dangerous constructs refused. */
    private static function part(ZipArchive $zip, string $name): ?string
    {
        $content = $zip->getFromName($name);
        if ($content === false) {
            return null;
        }
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', substr($content, 0, 4096)) === 1 || stripos($content, '<!ENTITY') !== false) {
            throw new ImportFileException('This Excel file contains unsupported content.');
        }
        return $content;
    }

    private static function reader(string $xml): XMLReader
    {
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new ImportFileException('This Excel file is damaged.');
        }
        return $reader;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = self::part($zip, 'xl/workbook.xml');
        $relationshipId = null;
        if ($workbook !== null) {
            $reader = self::reader($workbook);
            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sheet') {
                    $relationshipId = $reader->getAttributeNs('id', self::NS_REL);
                    break;
                }
            }
            $reader->close();
        }

        $rels = self::part($zip, 'xl/_rels/workbook.xml.rels');
        if ($relationshipId !== null && $rels !== null) {
            $reader = self::reader($rels);
            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Relationship' && $reader->getAttribute('Id') === $relationshipId) {
                    $target = (string) $reader->getAttribute('Target');
                    $reader->close();
                    $target = ltrim($target, '/');
                    $path = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    return $zip->locateName($path) !== false ? $path : 'xl/worksheets/sheet1.xml';
                }
            }
            $reader->close();
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @return array<int,string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::part($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }

        $strings = [];
        $reader = self::reader($xml);
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $item = @simplexml_load_string($reader->readOuterXml(), SimpleXMLElement::class, LIBXML_NONET);
                $text = '';
                if ($item !== false) {
                    // Plain <t> and rich-text runs <r><t>, but not phonetic hints <rPh>.
                    foreach ($item->xpath('.//*[local-name()="t"][not(ancestor::*[local-name()="rPh"])]') ?: [] as $t) {
                        $text .= (string) $t;
                    }
                }
                $strings[] = $text;
            }
        }
        $reader->close();
        return $strings;
    }

    /**
     * @param array<int,string> $shared
     * @return array<int,array{0:int,1:array<int,string>}>
     */
    private static function sheetRows(ZipArchive $zip, string $path, array $shared, int $maxRows, int $maxColumns): array
    {
        $xml = self::part($zip, $path);
        if ($xml === null) {
            throw new ImportFileException('The first worksheet could not be found in this Excel file.');
        }

        $reader = self::reader($xml);
        $rows = [];
        $rowElements = 0;
        $rowNo = 0;
        $cells = [];
        $col = 0;
        $type = null;
        $value = '';
        $inline = '';
        $inCell = false;
        $inInline = false;

        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                switch ($reader->localName) {
                    case 'row':
                        if (++$rowElements > self::MAX_ROW_ELEMENTS) {
                            throw new ImportFileException('This worksheet is far larger than a normal job list.');
                        }
                        $rowNo = (int) $reader->getAttribute('r') ?: $rowNo + 1;
                        $cells = [];
                        $col = -1;
                        break;
                    case 'c':
                        $ref = (string) $reader->getAttribute('r');
                        $col = $ref !== '' ? self::columnIndex($ref) : $col + 1;
                        $type = $reader->getAttribute('t');
                        $value = '';
                        $inline = '';
                        $inCell = !$reader->isEmptyElement;
                        if (!$inCell && $col < $maxColumns) {
                            $cells[$col] = '';
                        }
                        break;
                    case 'v':
                        if ($inCell) {
                            $value = $reader->readString();
                        }
                        break;
                    case 'is':
                        $inInline = true;
                        break;
                    case 't':
                        if ($inCell && $inInline) {
                            $inline .= $reader->readString();
                        }
                        break;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                switch ($reader->localName) {
                    case 'is':
                        $inInline = false;
                        break;
                    case 'c':
                        $inCell = false;
                        $cell = match ($type) {
                            's' => $shared[(int) $value] ?? '',
                            'inlineStr' => $inline,
                            'b' => $value === '1' ? 'TRUE' : 'FALSE',
                            'e' => '',
                            default => $value,
                        };
                        if ($col >= $maxColumns) {
                            if (trim($cell) !== '') {
                                throw new ImportFileException("The file has more than {$maxColumns} columns.");
                            }
                        } else {
                            $cells[$col] = $cell;
                        }
                        break;
                    case 'row':
                        $dense = self::dense($cells);
                        if ($dense !== []) {
                            $rows[] = [$rowNo, $dense];
                            if (count($rows) > $maxRows) {
                                throw new ImportFileException('The file has more than ' . number_format($maxRows - 1) . ' data rows. Split it into smaller files.');
                            }
                        }
                        break;
                }
            }
        }
        $reader->close();

        return $rows;
    }

    /**
     * Sparse column => value into a list, trailing empty cells dropped; a wholly empty row becomes [].
     *
     * @param array<int,string> $cells
     * @return array<int,string>
     */
    private static function dense(array $cells): array
    {
        if ($cells === []) {
            return [];
        }
        $last = -1;
        foreach ($cells as $index => $cell) {
            if (trim($cell) !== '') {
                $last = max($last, $index);
            }
        }
        if ($last < 0) {
            return [];
        }
        $dense = [];
        for ($i = 0; $i <= $last; $i++) {
            $dense[] = trim((string) ($cells[$i] ?? ''));
        }
        return $dense;
    }

    /** "C5" -> 2, "AA1" -> 26 */
    private static function columnIndex(string $ref): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $ref));
        $index = 0;
        for ($i = 0, $n = strlen($letters); $i < $n; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }
}
