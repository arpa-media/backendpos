<?php

namespace App\Support\MenuImport;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class MenuWorkbookReader
{
    /**
     * Read the first worksheet from an XLSX workbook into an array of associative rows.
     * This reader intentionally supports only the subset needed by MENU exports.
     *
     * @return array<int, array<string, string|int|float|null>>
     */
    public function readFirstSheet(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Workbook not found: {$path}");
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open workbook: {$path}");
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPath = $this->resolveFirstWorksheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);

            if ($sheetXml === false) {
                throw new RuntimeException("Worksheet XML not found: {$sheetPath}");
            }

            $xml = simplexml_load_string($sheetXml);
            if (! $xml instanceof SimpleXMLElement) {
                throw new RuntimeException("Invalid worksheet XML in: {$path}");
            }

            $rows = [];
            $headers = [];

            foreach ($xml->sheetData->row ?? [] as $rowNode) {
                $cells = [];

                foreach ($rowNode->c ?? [] as $cellNode) {
                    $reference = (string) ($cellNode['r'] ?? '');
                    $columnIndex = $this->columnIndexFromReference($reference);
                    $cells[$columnIndex] = $this->extractCellValue($cellNode, $sharedStrings);
                }

                if ($cells === []) {
                    continue;
                }

                ksort($cells);
                $ordered = array_values($cells);

                if ($headers === []) {
                    $headers = array_map(fn ($value) => trim((string) $value), $ordered);
                    continue;
                }

                $row = [];
                foreach ($headers as $index => $header) {
                    if ($header === '') {
                        continue;
                    }

                    $row[$header] = $ordered[$index] ?? null;
                }

                if ($row !== []) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $shared = simplexml_load_string($xml);
        if (! $shared instanceof SimpleXMLElement) {
            return [];
        }

        $values = [];

        foreach ($shared->si ?? [] as $item) {
            if (isset($item->t)) {
                $values[] = (string) $item->t;
                continue;
            }

            $parts = [];
            foreach ($item->r ?? [] as $run) {
                $parts[] = (string) ($run->t ?? '');
            }
            $values[] = implode('', $parts);
        }

        return $values;
    }

    private function resolveFirstWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('Workbook manifest is incomplete.');
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);

        if (! $workbook instanceof SimpleXMLElement || ! $rels instanceof SimpleXMLElement) {
            throw new RuntimeException('Workbook manifest XML is invalid.');
        }

        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rels->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $firstSheet = $workbook->sheets->sheet[0] ?? null;
        if (! $firstSheet) {
            throw new RuntimeException('Workbook does not contain any sheets.');
        }

        $relationshipId = (string) $firstSheet->attributes('r', true)->id;
        foreach ($rels->Relationship ?? [] as $relationship) {
            if ((string) ($relationship['Id'] ?? '') !== $relationshipId) {
                continue;
            }

            $target = (string) ($relationship['Target'] ?? '');
            $target = ltrim($target, '/');

            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        throw new RuntimeException('Unable to resolve first worksheet relationship.');
    }

    private function columnIndexFromReference(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference));
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(1, $index) - 1;
    }

    /**
     * @param array<int, string> $sharedStrings
     */
    private function extractCellValue(SimpleXMLElement $cellNode, array $sharedStrings): string|int|float|null
    {
        $type = (string) ($cellNode['t'] ?? '');

        if ($type === 'inlineStr') {
            return trim((string) ($cellNode->is->t ?? ''));
        }

        $raw = isset($cellNode->v) ? (string) $cellNode->v : null;
        if ($raw === null || $raw === '') {
            return null;
        }

        if ($type === 's') {
            return trim($sharedStrings[(int) $raw] ?? '');
        }

        if ($type === 'b') {
            return $raw === '1' ? 1 : 0;
        }

        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return trim($raw);
    }
}
