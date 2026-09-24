<?php

namespace App\Services\Finance;

use RuntimeException;
use ZipArchive;

/**
 * Minimal dependency-free XLSX writer for Finance statements.
 * Uses OpenXML with inline strings and native numeric cells.
 */
final class FinanceOpenXmlXlsxWriter
{
    public const STYLE_DEFAULT = 0;
    public const STYLE_TITLE = 1;
    public const STYLE_META_LABEL = 2;
    public const STYLE_META_VALUE = 3;
    public const STYLE_HEADER = 4;
    public const STYLE_SECTION = 5;
    public const STYLE_TEXT = 6;
    public const STYLE_NUMBER = 7;
    public const STYLE_SUBTOTAL_TEXT = 8;
    public const STYLE_SUBTOTAL_NUMBER = 9;
    public const STYLE_TOTAL_TEXT = 10;
    public const STYLE_TOTAL_NUMBER = 11;
    public const STYLE_NOTE = 12;

    /**
     * @param array<int,array{cells:array<int,mixed>,height?:float}> $rows
     * @param array<int,string> $merges
     * @param array<int,float> $widths 1-based column => Excel width
     */
    public function write(
        string $path,
        string $sheetName,
        array $rows,
        array $merges = [],
        array $widths = [],
        int $freezeRow = 0,
        string $creator = 'POS Finance'
    ): void {
        $sheetName = $this->sanitizeSheetName($sheetName);
        $files = [
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => $this->rootRelsXml(),
            'docProps/app.xml' => $this->appXml(),
            'docProps/core.xml' => $this->coreXml($creator),
            'xl/workbook.xml' => $this->workbookXml($sheetName),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelsXml(),
            'xl/styles.xml' => $this->stylesXml(),
            'xl/worksheets/sheet1.xml' => $this->sheetXml($rows, $merges, $widths, $freezeRow),
        ];

        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Folder temporary XLSX tidak dapat dibuat.');
        }

        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('File XLSX tidak dapat dibuat.');
            }
            foreach ($files as $name => $content) {
                if (! $zip->addFromString($name, $content)) {
                    $zip->close();
                    @unlink($path);
                    throw new RuntimeException("Komponen XLSX {$name} gagal ditulis.");
                }
            }
            $zip->close();
            return;
        }

        // Fallback ZIP STORE writer so no Composer package/ext-zip is mandatory.
        file_put_contents($path, $this->storeZip($files));
        if (! is_file($path) || filesize($path) < 1000) {
            @unlink($path);
            throw new RuntimeException('File XLSX fallback tidak berhasil dibuat.');
        }
    }

    public static function cell(mixed $value, int $style = self::STYLE_DEFAULT, ?string $type = null): array
    {
        return ['value' => $value, 'style' => $style, 'type' => $type];
    }

    private function sheetXml(array $rows, array $merges, array $widths, int $freezeRow): string
    {
        $maxCols = 1;
        foreach ($rows as $row) $maxCols = max($maxCols, count($row['cells'] ?? []));
        $maxRows = max(1, count($rows));
        $dimension = 'A1:' . $this->colLetter($maxCols) . $maxRows;

        $cols = '';
        if ($widths) {
            ksort($widths);
            $parts = [];
            foreach ($widths as $index => $width) {
                $i = max(1, (int) $index);
                $parts[] = '<col min="'.$i.'" max="'.$i.'" width="'.number_format((float)$width, 2, '.', '').'" customWidth="1"/>';
            }
            $cols = '<cols>'.implode('', $parts).'</cols>';
        }

        $sheetViews = '<sheetViews><sheetView workbookViewId="0" showGridLines="0">';
        if ($freezeRow > 0) {
            $sheetViews .= '<pane ySplit="'.$freezeRow.'" topLeftCell="A'.($freezeRow + 1).'" activePane="bottomLeft" state="frozen"/>';
            $sheetViews .= '<selection pane="bottomLeft" activeCell="A'.($freezeRow + 1).'" sqref="A'.($freezeRow + 1).'"/>';
        }
        $sheetViews .= '</sheetView></sheetViews>';

        $rowXml = [];
        foreach ($rows as $rIndex => $row) {
            $rowNo = $rIndex + 1;
            $height = isset($row['height']) ? ' ht="'.number_format((float)$row['height'], 2, '.', '').'" customHeight="1"' : '';
            $cellsXml = [];
            foreach (($row['cells'] ?? []) as $cIndex => $cell) {
                if (! is_array($cell) || ! array_key_exists('value', $cell)) {
                    $cell = self::cell($cell);
                }
                $value = $cell['value'];
                if ($value === null) continue;
                $style = (int)($cell['style'] ?? 0);
                $type = $cell['type'] ?? null;
                $ref = $this->colLetter($cIndex + 1).$rowNo;
                $s = $style > 0 ? ' s="'.$style.'"' : '';

                if ($type === 'n' || ($type === null && (is_int($value) || is_float($value)))) {
                    $numeric = is_finite((float)$value) ? number_format((float)$value, 2, '.', '') : '0';
                    $cellsXml[] = '<c r="'.$ref.'"'.$s.'><v>'.$numeric.'</v></c>';
                    continue;
                }
                if ($type === 'b' || is_bool($value)) {
                    $cellsXml[] = '<c r="'.$ref.'"'.$s.' t="b"><v>'.($value ? '1' : '0').'</v></c>';
                    continue;
                }
                $text = $this->xml((string)$value);
                $cellsXml[] = '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
            }
            $rowXml[] = '<row r="'.$rowNo.'"'.$height.'>'.implode('', $cellsXml).'</row>';
        }

        $mergeXml = '';
        if ($merges) {
            $valid = array_values(array_filter(array_map('trim', $merges)));
            if ($valid) $mergeXml = '<mergeCells count="'.count($valid).'">'.implode('', array_map(fn($r)=>'<mergeCell ref="'.$this->xml($r).'"/>', $valid)).'</mergeCells>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="'.$dimension.'"/>'.$sheetViews
            .'<sheetFormatPr defaultRowHeight="15"/>'.$cols
            .'<sheetData>'.implode('', $rowXml).'</sheetData>'.$mergeXml
            .'<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            .'<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
            .'</worksheet>';
    }

    private function stylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00;[Red]-#,##0.00;0.00"/></numFmts>
  <fonts count="5">
    <font><sz val="10"/><name val="Aptos"/><family val="2"/></font>
    <font><b/><sz val="18"/><color rgb="FF0F172A"/><name val="Aptos Display"/><family val="2"/></font>
    <font><b/><sz val="10"/><color rgb="FF334155"/><name val="Aptos"/><family val="2"/></font>
    <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Aptos"/><family val="2"/></font>
    <font><i/><sz val="9"/><color rgb="FF64748B"/><name val="Aptos"/><family val="2"/></font>
  </fonts>
  <fills count="5">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="13">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="164" fontId="2" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1"/>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="top" wrapText="1"/></xf>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1"><alignment horizontal="right"/></xf>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
    <xf numFmtId="164" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1"><alignment horizontal="right"/></xf>
    <xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
    <xf numFmtId="164" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1"><alignment horizontal="right"/></xf>
    <xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment wrapText="1"/></xf>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;
    }

    private function contentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
    }

    private function rootRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
    }

    private function workbookXml(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<workbookPr/><bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
            .'<sheets><sheet name="'.$this->xml($sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
            .'<calcPr calcId="191029" fullCalcOnLoad="1" forceFullCalc="1"/>'
            .'</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function appXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>POS Finance</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>
  <HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>
  <TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Report</vt:lpstr></vt:vector></TitlesOfParts>
</Properties>
XML;
    }

    private function coreXml(string $creator): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:creator>'.$this->xml($creator).'</dc:creator><cp:lastModifiedBy>'.$this->xml($creator).'</cp:lastModifiedBy>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    /** @param array<string,string> $files */
    private function storeZip(array $files): string
    {
        $body = '';
        $central = '';
        $offset = 0;
        [$dosTime, $dosDate] = $this->dosDateTime();

        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', $name);
            $crc = (int) sprintf('%u', crc32($content));
            $size = strlen($content);
            $nameLen = strlen($name);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0)
                .$name.$content;
            $body .= $local;

            $central .= pack('VvvvvvvVVVvvvvvVV',
                0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size,
                $nameLen, 0, 0, 0, 0, 0, $offset
            ).$name;
            $offset += strlen($local);
        }

        $count = count($files);
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0);
        return $body.$central.$eocd;
    }

    /** @return array{int,int} */
    private function dosDateTime(): array
    {
        $y = (int) date('Y'); $m = (int) date('n'); $d = (int) date('j');
        $h = (int) date('G'); $i = (int) date('i'); $s = (int) date('s');
        $date = (($y - 1980) << 9) | ($m << 5) | $d;
        $time = ($h << 11) | ($i << 5) | intdiv($s, 2);
        return [$time, $date];
    }

    private function sanitizeSheetName(string $name): string
    {
        $name = trim(str_replace(['\\','/','?','*','[',']',':'], ' ', $name));
        $name = preg_replace('/\s+/', ' ', $name) ?: 'Report';
        return function_exists('mb_substr') ? mb_substr($name, 0, 31) : substr($name, 0, 31);
    }

    private function colLetter(int $index): string
    {
        $s = '';
        while ($index > 0) {
            $index--;
            $s = chr(65 + ($index % 26)).$s;
            $index = intdiv($index, 26);
        }
        return $s ?: 'A';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
