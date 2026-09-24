<?php

namespace App\Services\Support;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class SimpleXlsxService
{
    public function download(string $filename, string $sheetName, array $rows): Response
    {
        return $this->downloadWorkbook($filename, [
            ['name' => $sheetName, 'rows' => $rows],
        ]);
    }

    /**
     * @param array<int,array{name:string,rows:array<int,array<int,mixed>>}> $sheets
     */
    public function downloadWorkbook(string $filename, array $sheets): Response
    {
        if ($sheets === []) {
            throw new InvalidArgumentException('Workbook wajib memiliki minimal satu worksheet.');
        }

        $binary = $this->buildWorkbook($sheets);

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Backward-compatible: returns rows from the first worksheet.
     *
     * @return array<int,array<int,string>>
     */
    public function read(UploadedFile|string $file): array
    {
        $worksheets = $this->readWorksheets($file);
        if ($worksheets === []) {
            throw new InvalidArgumentException('Worksheet tidak ditemukan di file XLSX.');
        }

        return $worksheets[0]['rows'];
    }

    /**
     * @return array<int,array{name:string,rows:array<int,array<int,string>>}>
     */
    public function readWorksheets(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new InvalidArgumentException('File XLSX tidak ditemukan.');
        }

        $entries = $this->readPackage($path);
        $sharedStrings = $this->sharedStrings($entries['xl/sharedStrings.xml'] ?? null);
        $sheetDefinitions = $this->sheetDefinitions($entries);

        if ($sheetDefinitions === []) {
            foreach (array_keys($entries) as $name) {
                if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $match)) {
                    $sheetDefinitions[] = [
                        'name' => 'Sheet'.$match[1],
                        'path' => $name,
                        'order' => (int) $match[1],
                    ];
                }
            }
            usort($sheetDefinitions, fn (array $left, array $right): int => $left['order'] <=> $right['order']);
        }

        $worksheets = [];
        foreach ($sheetDefinitions as $definition) {
            $content = $entries[$definition['path']] ?? null;
            if (! is_string($content) || $content === '') {
                continue;
            }
            $worksheets[] = [
                'name' => $definition['name'],
                'rows' => $this->worksheetRows($content, $sharedStrings),
            ];
        }

        return $worksheets;
    }

    /** @return array<int,array{name:string,path:string,order:int}> */
    private function sheetDefinitions(array $entries): array
    {
        $workbook = $entries['xl/workbook.xml'] ?? null;
        $relationships = $entries['xl/_rels/workbook.xml.rels'] ?? null;
        if (! is_string($workbook) || ! is_string($relationships)) return [];

        $targets = [];
        preg_match_all('/<(?:[A-Za-z0-9_]+:)?Relationship\b([^>]*)\/?\s*>/i', $this->stripUtf8Bom($relationships), $matches);
        foreach ($matches[1] ?? [] as $attributeText) {
            $attributes = $this->xmlAttributes($attributeText);
            $id = trim((string) ($attributes['Id'] ?? $attributes['id'] ?? ''));
            $target = trim((string) ($attributes['Target'] ?? $attributes['target'] ?? ''));
            if ($id === '' || $target === '') continue;
            $targets[$id] = $this->normalizePackagePath('xl/workbook.xml', $target);
        }

        $definitions = [];
        preg_match_all('/<(?:[A-Za-z0-9_]+:)?sheet\b([^>]*)\/?\s*>/i', $this->stripUtf8Bom($workbook), $matches);
        $order = 0;
        foreach ($matches[1] ?? [] as $attributeText) {
            $attributes = $this->xmlAttributes($attributeText);
            $relationshipId = trim((string) ($attributes['r:id'] ?? $attributes['id'] ?? ''));
            $path = $targets[$relationshipId] ?? '';
            if ($path === '' || ! isset($entries[$path])) continue;
            $name = trim((string) ($attributes['name'] ?? ''));
            $definitions[] = [
                'name' => $name !== '' ? $name : 'Sheet'.($order + 1),
                'path' => $path,
                'order' => $order++,
            ];
        }

        return $definitions;
    }

    /** @return array<int,array<int,string>> */
    private function worksheetRows(string $content, array $sharedStrings): array
    {
        $content = $this->stripUtf8Bom($content);
        $sheetDataXml = $this->extractElementBody($content, 'sheetData');
        if ($sheetDataXml === null) {
            throw new InvalidArgumentException('Worksheet XLSX tidak memiliki sheetData.');
        }

        preg_match_all('/<(?:[A-Za-z0-9_]+:)?row\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?row>/is', $sheetDataXml, $rowMatches);
        $rows = [];
        foreach ($rowMatches[1] ?? [] as $rowXml) {
            $cells = [];
            // Match paired and self-closing cells in a single token. The old
            // paired-only regex could start at <c .../> and consume the next
            // closing </c>, shifting every following column to the left.
            preg_match_all(
                '/<(?:[A-Za-z0-9_]+:)?c\b([^>]*?)(?:\/\s*>|>(.*?)<\/(?:[A-Za-z0-9_]+:)?c\s*>)/is',
                $rowXml,
                $cellMatches,
                PREG_SET_ORDER
            );
            foreach ($cellMatches as $cellMatch) {
                $attributes = $this->xmlAttributes($cellMatch[1]);
                $ref = (string) ($attributes['r'] ?? 'A1');
                $type = (string) ($attributes['t'] ?? '');
                $body = (string) ($cellMatch[2] ?? '');
                $index = $this->columnIndex($ref);
                $value = '';

                if ($type === 'inlineStr') {
                    preg_match_all('/<(?:[A-Za-z0-9_]+:)?t\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?t>/is', $body, $texts);
                    $value = implode('', array_map(fn (string $text): string => $this->xmlDecode($text), $texts[1] ?? []));
                } elseif ($type === 's') {
                    preg_match('/<(?:[A-Za-z0-9_]+:)?v\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?v>/is', $body, $valueMatch);
                    $value = $sharedStrings[(int) trim($valueMatch[1] ?? '0')] ?? '';
                } elseif ($type === 'b') {
                    preg_match('/<(?:[A-Za-z0-9_]+:)?v\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?v>/is', $body, $valueMatch);
                    $value = trim($valueMatch[1] ?? '0') === '1' ? '1' : '0';
                } else {
                    preg_match('/<(?:[A-Za-z0-9_]+:)?v\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?v>/is', $body, $valueMatch);
                    $value = $this->xmlDecode((string) ($valueMatch[1] ?? ''));
                }

                $cells[$index] = trim((string) $value);
            }

            if ($cells !== []) {
                ksort($cells);
                $rows[] = array_map(fn (int $i): string => $cells[$i] ?? '', range(0, max(array_keys($cells))));
            }
        }

        return $rows;
    }


    /**
     * Extract an XML element body without applying a dot-star regex to the
     * entire worksheet. Large recipe workbooks can exceed PCRE backtracking
     * limits even though their XML is valid.
     */
    private function extractElementBody(string $xml, string $localName): ?string
    {
        $name = preg_quote($localName, '/');
        if (! preg_match('/<(?:[A-Za-z0-9_]+:)?'.$name.'\b[^>]*>/i', $xml, $open, PREG_OFFSET_CAPTURE)) {
            // A valid but empty element may be emitted as <x:sheetData/>.
            if (preg_match('/<(?:[A-Za-z0-9_]+:)?'.$name.'\b[^>]*\/\s*>/i', $xml)) {
                return '';
            }
            return null;
        }

        $openTag = $open[0][0];
        $openOffset = (int) $open[0][1];
        if (preg_match('/\/\s*>$/', $openTag)) {
            return '';
        }

        $bodyOffset = $openOffset + strlen($openTag);
        if (! preg_match('/<\/(?:[A-Za-z0-9_]+:)?'.$name.'\s*>/i', $xml, $close, PREG_OFFSET_CAPTURE, $bodyOffset)) {
            return null;
        }

        $closeOffset = (int) $close[0][1];
        return substr($xml, $bodyOffset, $closeOffset - $bodyOffset);
    }

    /** @return array<int,string> */
    private function sharedStrings(?string $content): array
    {
        if (! is_string($content) || $content === '') return [];
        preg_match_all('/<(?:[A-Za-z0-9_]+:)?si\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?si>/is', $this->stripUtf8Bom($content), $items);
        $strings = [];
        foreach ($items[1] ?? [] as $itemXml) {
            preg_match_all('/<(?:[A-Za-z0-9_]+:)?t\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?t>/is', $itemXml, $texts);
            $strings[] = implode('', array_map(fn (string $text): string => $this->xmlDecode($text), $texts[1] ?? []));
        }
        return $strings;
    }

    /** @return array<string,string> */
    private function xmlAttributes(string $attributeText): array
    {
        preg_match_all('/([A-Za-z_][A-Za-z0-9_.:-]*)\s*=\s*("([^"]*)"|\'([^\']*)\')/s', $attributeText, $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $match) {
            $attributes[$match[1]] = $this->xmlDecode($match[3] !== '' ? $match[3] : $match[4]);
        }
        return $attributes;
    }

    private function xmlDecode(string $value): string
    {
        return html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function normalizePackagePath(string $sourcePath, string $target): string
    {
        $target = str_replace('\\', '/', trim($target));
        if ($target === '') return '';
        if (str_starts_with($target, '/')) return ltrim($target, '/');
        $parts = explode('/', dirname($sourcePath).'/'.$target);
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') { array_pop($normalized); continue; }
            $normalized[] = $part;
        }
        return implode('/', $normalized);
    }

    private function stripUtf8Bom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF")
            ? substr($content, 3)
            : $content;
    }

    private function readPackage(string $path): array
    {
        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new InvalidArgumentException('File XLSX tidak dapat dibuka atau corrupt.');
            }
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $content = $name === false ? false : $zip->getFromIndex($i);
                if ($name !== false && $content !== false) $entries[$name] = $content;
            }
            $zip->close();
            return $entries;
        }

        $binary = @file_get_contents($path);
        if ($binary === false) throw new InvalidArgumentException('File XLSX tidak dapat dibaca.');
        $eocdOffset = strrpos($binary, "PK\x05\x06");
        if ($eocdOffset === false) throw new InvalidArgumentException('Struktur ZIP pada XLSX tidak valid.');
        $eocd = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLength', substr($binary, $eocdOffset, 22));
        $entries = [];
        $cursor = (int) $eocd['cdOffset'];
        for ($i = 0; $i < (int) $eocd['totalEntries']; $i++) {
            $header = unpack('Vsig/vversionMade/vversionNeeded/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttributes/VexternalAttributes/VlocalOffset', substr($binary, $cursor, 46));
            if (($header['sig'] ?? null) !== 0x02014b50) throw new InvalidArgumentException('Central directory XLSX tidak valid.');
            $name = substr($binary, $cursor + 46, $header['nameLength']);
            $cursor += 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
            $local = unpack('Vsig/vversion/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength', substr($binary, $header['localOffset'], 30));
            if (($local['sig'] ?? null) !== 0x04034b50) continue;
            $start = $header['localOffset'] + 30 + $local['nameLength'] + $local['extraLength'];
            $compressed = substr($binary, $start, $header['compressedSize']);
            if ((int) $header['method'] === 0) $entries[$name] = $compressed;
            elseif ((int) $header['method'] === 8) {
                $inflated = @gzinflate($compressed);
                if ($inflated === false) throw new InvalidArgumentException('Data XLSX terkompresi tidak dapat dibaca.');
                $entries[$name] = $inflated;
            }
        }
        return $entries;
    }

    /** @param array<int,array{name:string,rows:array<int,array<int,mixed>>}> $sheets */
    private function buildWorkbook(array $sheets): string
    {
        $timestamp = now()->toISOString();
        $sheetRows = [];
        $sheetTags = [];
        $relationshipTags = [];
        $contentTypeTags = [];

        foreach (array_values($sheets) as $index => $sheet) {
            $sheetNumber = $index + 1;
            $safeName = $this->safeSheetName((string) ($sheet['name'] ?? ('Sheet'.$sheetNumber)), $sheetNumber);
            $sheetRows['xl/worksheets/sheet'.$sheetNumber.'.xml'] = $this->worksheetXml((array) ($sheet['rows'] ?? []));
            $sheetTags[] = '<sheet name="'.htmlspecialchars($safeName, ENT_XML1 | ENT_COMPAT, 'UTF-8').'" sheetId="'.$sheetNumber.'" r:id="rId'.$sheetNumber.'"/>';
            $relationshipTags[] = '<Relationship Id="rId'.$sheetNumber.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheetNumber.'.xml"/>';
            $contentTypeTags[] = '<Override PartName="/xl/worksheets/sheet'.$sheetNumber.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.implode('', $contentTypeTags).'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>ERP Stock Inventory</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:modified></cp:coreProperties>',
            'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>ERP Stock Inventory</Application></Properties>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('', $relationshipTags).'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.implode('', $sheetTags).'</sheets></workbook>',
            ...$sheetRows,
        ];

        return $this->buildZip($files);
    }

    private function safeSheetName(string $name, int $number): string
    {
        $name = trim(preg_replace('~[\\/?*\[\]:]~u', ' ', $name) ?: '');
        return mb_substr($name !== '' ? $name : 'Sheet'.$number, 0, 31);
    }

    private function worksheetXml(array $rows): string
    {
        $xml = '';
        foreach (array_values($rows) as $rowIndex => $row) {
            $cells = '';
            foreach (array_values($row) as $columnIndex => $value) {
                $ref = $this->columnName($columnIndex + 1).($rowIndex + 1);
                $safe = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $cells .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$safe.'</t></is></c>';
            }
            $xml .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>';
    }

    private function buildZip(array $files): string
    {
        $data = '';
        $central = '';
        $offset = 0;
        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', $name);
            $crc = crc32($content);
            $size = strlen($content);
            $nameLength = strlen($name);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0).$name;
            $data .= $local.$content;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset).$name;
            $offset += strlen($local) + $size;
        }
        return $data.$central.pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }

    private function columnIndex(string $cellRef): int
    {
        preg_match('/^[A-Z]+/i', $cellRef, $matches);
        $index = 0;
        foreach (str_split(strtoupper($matches[0] ?? 'A')) as $letter) $index = $index * 26 + ord($letter) - 64;
        return $index - 1;
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }
        return $name;
    }
}
