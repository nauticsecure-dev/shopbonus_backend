<?php

namespace App\Services\Vouchers;

use App\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Minimal Excel (.xlsx) reader for discount-code imports.
 * Avoids a heavy spreadsheet dependency; supports the common single-sheet
 * "code" column layout merchants export from their webshop admin.
 */
class SpreadsheetCodeReader
{
    /**
     * @return list<string>
     */
    public function lines(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            throw new ApiException('The spreadsheet could not be read.', 422, 'file_unreadable');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new ApiException('The Excel file is invalid or corrupted.', 422, 'invalid_spreadsheet');
        }

        try {
            $shared = $this->sharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml')
                ?: $zip->getFromName('xl/worksheets/sheet.xml');
            if ($sheetXml === false) {
                throw new ApiException('No worksheet found in the Excel file.', 422, 'invalid_spreadsheet');
            }

            return $this->extractCodes($sheetXml, $shared);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }
        $strings = [];
        foreach ($doc->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } else {
                $parts = [];
                foreach ($si->r as $run) {
                    $parts[] = (string) $run->t;
                }
                $strings[] = implode('', $parts);
            }
        }

        return $strings;
    }

    /**
     * @param  list<string>  $shared
     * @return list<string>
     */
    private function extractCodes(string $sheetXml, array $shared): array
    {
        $doc = @simplexml_load_string($sheetXml);
        if ($doc === false) {
            throw new ApiException('The Excel worksheet could not be parsed.', 422, 'invalid_spreadsheet');
        }

        $rows = [];
        foreach ($doc->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $col = preg_replace('/\d+/', '', $ref) ?? 'A';
                $type = (string) ($cell['t'] ?? '');
                $raw = isset($cell->v) ? (string) $cell->v : '';
                $value = $type === 's' ? ($shared[(int) $raw] ?? '') : $raw;
                $cells[$col] = trim($value);
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return [];
        }

        $column = 'A';
        $first = $rows[0];
        foreach ($first as $col => $value) {
            if (mb_strtolower($value) === 'code') {
                $column = $col;
                array_shift($rows);
                break;
            }
        }

        $lines = [];
        foreach ($rows as $cells) {
            $lines[] = (string) ($cells[$column] ?? '');
        }

        return $lines;
    }
}
