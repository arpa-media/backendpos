<?php

namespace App\Services\Spreadsheet;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Lightweight typed XLSX writer for cross-portal exports.
 * Explicit types avoid the two common ERP mistakes: time-as-text and IDs losing leading zeroes.
 */
final class UniversalSpreadsheetXlsxService
{
    public const TEXT = 'text';
    public const NUMBER = 'number';
    public const CURRENCY = 'currency';
    public const DATE = 'date';
    public const DATETIME = 'datetime';
    public const TIME = 'time';
    public const BOOLEAN = 'boolean';
    public const PERCENT = 'percent';

    public static function cell(mixed $value, string $type = self::TEXT): array
    {
        return ['value' => $value, 'type' => $type];
    }

    /** @param array<int,string|array{label:string,type?:string,width?:float}> $columns */
    public function write(string $path, string $sheetName, array $columns, iterable $rows, string $creator = 'ERP POS'): void
    {
        $normalized = $this->normalizeColumns($columns);
        $xmlRows = [];
        $header = [];
        foreach ($normalized as $column) $header[] = self::cell($column['label'], self::TEXT);
        $xmlRows[] = $header;

        foreach ($rows as $row) {
            $cells = [];
            $values = is_array($row) ? array_values($row) : array_values((array) $row);
            foreach ($normalized as $index => $column) {
                $value = $values[$index] ?? null;
                if (is_array($value) && array_key_exists('value', $value)) {
                    $cells[] = ['value' => $value['value'], 'type' => $value['type'] ?? $column['type']];
                } else {
                    $cells[] = ['value' => $value, 'type' => $column['type']];
                }
            }
            $xmlRows[] = $cells;
        }

        $files = [
            '[Content_Types].xml' => $this->contentTypes(), '_rels/.rels' => $this->rootRels(),
            'docProps/app.xml' => $this->appXml(), 'docProps/core.xml' => $this->coreXml($creator),
            'xl/workbook.xml' => $this->workbookXml($sheetName), 'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml' => $this->stylesXml(), 'xl/worksheets/sheet1.xml' => $this->sheetXml($xmlRows, $normalized),
        ];
        $this->writeZip($path, $files);
    }

    public function response(string $filename, string $sheetName, array $columns, iterable $rows): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'erp_xlsx_');
        if ($tmp === false) throw new RuntimeException('Temporary XLSX file tidak dapat dibuat.');
        $xlsx = $tmp.'.xlsx';
        @unlink($tmp);
        $this->write($xlsx, $sheetName, $columns, $rows);
        return response()->download($xlsx, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    private function normalizeColumns(array $columns): array
    {
        if ($columns === []) throw new RuntimeException('Export harus memiliki minimal satu kolom.');
        return array_map(function ($column, int $index): array {
            if (is_string($column)) return ['label' => $column, 'type' => self::TEXT, 'width' => 18.0];
            $type = strtolower((string) ($column['type'] ?? self::TEXT));
            if (! in_array($type, [self::TEXT,self::NUMBER,self::CURRENCY,self::DATE,self::DATETIME,self::TIME,self::BOOLEAN,self::PERCENT], true)) $type = self::TEXT;
            return [
                'label' => trim((string) ($column['label'] ?? ('Column '.($index + 1)))) ?: ('Column '.($index + 1)),
                'type' => $type, 'width' => max(8.0, min(60.0, (float) ($column['width'] ?? 18))),
            ];
        }, $columns, array_keys($columns));
    }

    private function sheetXml(array $rows, array $columns): string
    {
        $cols = [];
        foreach ($columns as $i => $column) $cols[] = '<col min="'.($i+1).'" max="'.($i+1).'" width="'.number_format($column['width'], 2, '.', '').'" customWidth="1"/>';
        $rowXml = [];
        foreach ($rows as $r => $cells) {
            $rowNo = $r + 1; $cellXml = [];
            foreach ($cells as $c => $cell) {
                $ref = $this->col($c + 1).$rowNo;
                if ($r === 0) { $cellXml[] = $this->textCell($ref, $cell['value'], 1); continue; }
                $cellXml[] = $this->typedCell($ref, $cell['value'] ?? null, (string) ($cell['type'] ?? self::TEXT));
            }
            $rowXml[] = '<row r="'.$rowNo.'">'.implode('', array_filter($cellXml)).'</row>';
        }
        $dimension = 'A1:'.$this->col(count($columns)).max(1, count($rows));
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="'.$dimension.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.implode('', $cols).'</cols><sheetData>'.implode('', $rowXml).'</sheetData><autoFilter ref="'.$dimension.'"/>'
            .'</worksheet>';
    }

    private function typedCell(string $ref, mixed $value, string $type): string
    {
        if ($value === null || $value === '') return '';
        return match ($type) {
            self::NUMBER => $this->numericCell($ref, $value, 2),
            self::CURRENCY => $this->numericCell($ref, $value, 3),
            self::DATE => $this->dateCell($ref, $value, false),
            self::DATETIME => $this->dateCell($ref, $value, true),
            self::TIME => $this->timeCell($ref, $value),
            self::BOOLEAN => '<c r="'.$ref.'" s="7" t="b"><v>'.($this->truthy($value) ? '1' : '0').'</v></c>',
            self::PERCENT => $this->numericCell($ref, $value, 8),
            default => $this->textCell($ref, $value, 0),
        };
    }

    private function numericCell(string $ref, mixed $value, int $style): string
    {
        if (! is_numeric($value)) return $this->textCell($ref, $value, 0);
        return '<c r="'.$ref.'" s="'.$style.'"><v>'.number_format((float) $value, 10, '.', '').'</v></c>';
    }

    private function dateCell(string $ref, mixed $value, bool $withTime): string
    {
        $date = $this->toDate($value);
        if (! $date) return $this->textCell($ref, $value, 0);
        $seconds = ($date->getTimestamp() - (new \DateTimeImmutable('1899-12-30 00:00:00', $date->getTimezone()))->getTimestamp());
        $serial = $seconds / 86400;
        return '<c r="'.$ref.'" s="'.($withTime ? 5 : 4).'"><v>'.number_format($serial, 10, '.', '').'</v></c>';
    }

    private function timeCell(string $ref, mixed $value): string
    {
        if (is_numeric($value) && (float) $value >= 0 && (float) $value < 1) $serial = (float) $value;
        else {
            $text = trim((string) $value);
            if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?$/', $text, $m)) return $this->textCell($ref, $value, 0);
            $h=(int)$m[1]; $i=(int)$m[2]; $s=isset($m[3])?(int)$m[3]:0;
            if ($h>23 || $i>59 || $s>59) return $this->textCell($ref, $value, 0);
            $fraction = isset($m[4]) && $m[4] !== '' ? (float) ('0.'.$m[4]) : 0.0;
            $serial = (($h*3600)+($i*60)+$s+$fraction)/86400;
        }
        return '<c r="'.$ref.'" s="6"><v>'.number_format($serial, 10, '.', '').'</v></c>';
    }

    private function textCell(string $ref, mixed $value, int $style): string
    {
        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml((string)$value).'</t></is></c>';
    }

    private function toDate(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) return $value;
        $text = trim((string) $value); if ($text === '') return null;
        try { return new \DateTimeImmutable($text); } catch (\Throwable) { return null; }
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string)$value)), ['1','true','yes','ya','y'], true);
    }

    private function stylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="5"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="&quot;Rp&quot; #,##0.00"/><numFmt numFmtId="166" formatCode="yyyy-mm-dd"/><numFmt numFmtId="167" formatCode="yyyy-mm-dd hh:mm:ss"/><numFmt numFmtId="168" formatCode="hh:mm:ss"/></numFmts>
<fonts count="2"><font><sz val="10"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Aptos"/></font></fonts>
<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill></fills>
<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFE2E8F0"/></left><right style="thin"><color rgb="FFE2E8F0"/></right><top style="thin"><color rgb="FFE2E8F0"/></top><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="9"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="167" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="168" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/><xf numFmtId="10" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/></cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>
XML;
    }

    private function writeZip(string $path, array $files): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) throw new RuntimeException('Folder output XLSX tidak dapat dibuat.');
        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('XLSX tidak dapat dibuat.');
            foreach ($files as $name => $content) $zip->addFromString($name, $content);
            $zip->close();
            return;
        }
        file_put_contents($path, $this->storeZip($files));
        if (! is_file($path) || filesize($path) < 1000) { @unlink($path); throw new RuntimeException('XLSX fallback tidak berhasil dibuat.'); }
    }

    private function storeZip(array $files): string
    {
        $body = ''; $central = ''; $offset = 0; [$dosTime, $dosDate] = $this->dosDateTime();
        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', $name); $crc = (int) sprintf('%u', crc32($content)); $size = strlen($content); $nameLen = strlen($name);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0).$name.$content;
            $body .= $local;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset).$name;
            $offset += strlen($local);
        }
        $count = count($files);
        return $body.$central.pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0);
    }

    private function dosDateTime(): array
    {
        $y=(int)date('Y'); $m=(int)date('n'); $d=(int)date('j'); $h=(int)date('G'); $i=(int)date('i'); $s=(int)date('s');
        return [(($h << 11) | ($i << 5) | intdiv($s, 2)), ((max(1980, $y) - 1980) << 9) | ($m << 5) | $d];
    }

    private function contentTypes(): string { return '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>'; }
    private function rootRels(): string { return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>'; }
    private function workbookRels(): string { return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'; }
    private function workbookXml(string $name): string { $name=trim(str_replace(['\\','/','?','*','[',']',':'], ' ', $name)); $name=substr($name !== '' ? $name : 'Data',0,31); return '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$this->xml($name).'" sheetId="1" r:id="rId1"/></sheets></workbook>'; }
    private function appXml(): string { return '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>ERP POS</Application></Properties>'; }
    private function coreXml(string $creator): string { $now=gmdate('Y-m-d\\TH:i:s\\Z'); return '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>'.$this->xml($creator).'</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created></cp:coreProperties>'; }
    private function col(int $n): string { $s=''; while($n>0){$n--; $s=chr(65+($n%26)).$s; $n=intdiv($n,26);} return $s; }
    private function xml(string $v): string { return htmlspecialchars($v, ENT_XML1|ENT_COMPAT, 'UTF-8'); }
}
