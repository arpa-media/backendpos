<?php

namespace App\Services\Warehouse\Documents\I05;

final class WarehouseProductionRequestPdfRendererI05
{
    private const PAGE_W = 595.28;
    private const PAGE_H = 841.89;
    private const MARGIN = 38.0;

    public function render(array $documents): string
    {
        $documents = array_values(array_filter($documents, 'is_array'));
        $pages = [];
        foreach ($documents as $document) {
            foreach ($this->pageChunks($document) as $chunk) $pages[] = $chunk;
        }
        if ($pages === []) $pages[] = ['document' => ['title' => 'Warehouse Document', 'company' => []], 'rows' => [], 'continued' => false, 'final' => true];

        $logo = $this->logoObject();
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $imageObjectId = 5;
        $objects[$imageObjectId] = $logo['object'];

        $kids = [];
        $next = 6;
        foreach ($pages as $pageIndex => $page) {
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = $pageId.' 0 R';
            $stream = $this->pageStream($page['document'], $page['rows'], (bool) $page['continued'], (bool) ($page['final'] ?? true), $pageIndex + 1, count($pages), $logo);
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::PAGE_W.' '.self::PAGE_H.'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Im1 '.$imageObjectId.' 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);
        return $this->assemble($objects);
    }

    private function pageChunks(array $document): array
    {
        $rows = array_values((array) ($document['rows'] ?? []));
        $perPage = count((array) ($document['columns'] ?? [])) >= 6 ? 15 : 18;
        $chunks = array_chunk($rows, $perPage);
        if ($chunks === []) $chunks = [[]];
        return array_map(fn ($rows, $i) => ['document' => $document, 'rows' => $rows, 'continued' => $i > 0, 'final' => $i === count($chunks) - 1], $chunks, array_keys($chunks));
    }

    private function pageStream(array $d, array $rows, bool $continued, bool $final, int $pageNo, int $pageCount, array $logo): string
    {
        $s = '';
        $pageW = self::PAGE_W;
        $left = self::MARGIN;
        $right = $pageW - self::MARGIN;
        $width = $right - $left;
        $title = (string) ($d['title'] ?? 'Warehouse Document').($continued ? ' (Lanjutan)' : '');
        $company = (array) ($d['company'] ?? []);

        // Logo and centered document title.
        $imgW = 135.0;
        $imgH = $logo['height'] > 0 ? $imgW * ($logo['height'] / $logo['width']) : 30.0;
        $imgY = 785.0;
        $s .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q\n", $imgW, $imgH, $left, $imgY);
        $s .= $this->text($pageW / 2, 807, $title, 17, true, 'center');
        $s .= $this->text($pageW / 2, 790, (string) ($company['name'] ?? 'PT. Aneka Produk Berkah'), 8, true, 'center');
        $s .= $this->line($left, 776, $right, 776, 0.8);

        // Header company/date/number blocks.
        $s .= $this->text($left, 758, 'Perusahaan:', 7, false);
        $s .= $this->text($left, 745, (string) ($company['name'] ?? '-'), 10, true);
        $s .= $this->text($left, 732, (string) ($company['address'] ?? '-'), 7, false);
        $s .= $this->text($left, 720, 'Tel: '.($company['phone'] ?? '-').'  |  Email: '.($company['email'] ?? '-'), 7, false);
        $s .= $this->text($left + 285, 758, 'Tanggal:', 7, false);
        $s .= $this->text($left + 285, 744, (string) ($d['date'] ?? '-'), 9, true);
        $s .= $this->text($left + 390, 758, (string) ($d['code'] ?? 'DOC').':', 7, false);
        $s .= $this->text($left + 390, 744, (string) ($d['number'] ?? '-'), 9, true);
        if (! empty($d['reference'])) $s .= $this->text($left + 390, 730, 'Ref: '.(string) $d['reference'], 7, false);
        $s .= $this->text($left + 285, 718, 'Status: '.(string) ($d['status'] ?? '-'), 7, false);

        $partyY = 685.0;
        $leftParty = (array) ($d['left_party'] ?? []);
        $rightParty = (array) ($d['right_party'] ?? []);
        $s .= $this->partyBlock($left, $partyY, 235, $leftParty);
        if ($rightParty !== []) $s .= $this->partyBlock($left + 280, $partyY, 235, $rightParty);

        $metaY = 608.0;
        $meta = array_values((array) ($d['meta'] ?? []));
        foreach (array_slice($meta, 0, 4) as $i => $m) {
            $x = $left + ($i % 2) * 280;
            $y = $metaY - intdiv($i, 2) * 20;
            $s .= $this->text($x, $y, (string) ($m['label'] ?? '').':', 6.5, true);
            $s .= $this->text($x + 75, $y, (string) ($m['value'] ?? '-'), 7, false);
        }

        // Table.
        $tableTop = 555.0;
        $columns = array_values((array) ($d['columns'] ?? []));
        $colWidths = $this->columnWidths($columns, $width);
        $headerH = 20.0;
        $rowH = 20.0;
        $s .= $this->rect($left, $tableTop - $headerH, $width, $headerH, [0.93, 0.93, 0.88], [0.70, 0.70, 0.55]);
        $x = $left;
        foreach ($columns as $i => $column) {
            $cw = $colWidths[$i];
            $align = ($column['align'] ?? 'left') === 'right' ? 'right' : 'left';
            $s .= $this->text($align === 'right' ? $x + $cw - 4 : $x + 4, $tableTop - 14, (string) ($column['label'] ?? ''), 7, true, $align);
            $s .= $this->line($x, $tableTop, $x, $tableTop - $headerH - max(1, count($rows)) * $rowH, 0.35, [0.82, 0.82, 0.75]);
            $x += $cw;
        }
        $s .= $this->line($right, $tableTop, $right, $tableTop - $headerH - max(1, count($rows)) * $rowH, 0.35, [0.82, 0.82, 0.75]);
        $y = $tableTop - $headerH;
        foreach ($rows as $row) {
            $s .= $this->rect($left, $y - $rowH, $width, $rowH, [0.965, 0.965, 0.94], [0.86, 0.86, 0.78]);
            $x = $left;
            foreach ($columns as $i => $column) {
                $cw = $colWidths[$i];
                $value = (string) ($row[$i] ?? '');
                $align = ($column['align'] ?? 'left') === 'right' ? 'right' : 'left';
                $maxChars = max(6, (int) floor($cw / 4.4));
                $value = $this->truncate($value, $maxChars);
                $s .= $this->text($align === 'right' ? $x + $cw - 4 : $x + 4, $y - 13, $value, 7, false, $align);
                $x += $cw;
            }
            $y -= $rowH;
        }
        if ($rows === []) {
            $s .= $this->rect($left, $y - $rowH, $width, $rowH, [0.965, 0.965, 0.94], [0.86, 0.86, 0.78]);
            $s .= $this->text($left + 5, $y - 13, 'Tidak ada item.', 7, false);
            $y -= $rowH;
        }

        // Totals, notes and signatures belong only on the final page of each document.
        if ($final) {
            $totals = array_values((array) ($d['totals'] ?? []));
            foreach ($totals as $t) {
                $s .= $this->text($right - 165, $y - 14, (string) ($t['label'] ?? ''), 7, true);
                $s .= $this->text($right - 5, $y - 14, (string) ($t['value'] ?? ''), 7, true, 'right');
                $y -= 18;
            }
            if (! empty($d['terbilang'])) {
                $s .= $this->text($left, $y - 8, 'Terbilang: '.(string) $d['terbilang'], 7, false);
                $y -= 18;
            }
            if (! empty($d['notes'])) {
                $s .= $this->text($left, $y - 8, '*Note: '.$this->truncate((string) $d['notes'], 115), 7, false);
                $y -= 18;
            }

            $sigY = max(98.0, min($y - 22, 170.0));
            $signatures = array_values((array) ($d['signatures'] ?? []));
            if ($signatures !== []) {
                $slot = $width / count($signatures);
                foreach ($signatures as $i => $sig) {
                    $cx = $left + $slot * $i + $slot / 2;
                    $s .= $this->text($cx, $sigY, (string) ($sig['label'] ?? 'Disetujui oleh'), 7, false, 'center');
                    $s .= $this->line($cx - min(55, $slot * 0.32), $sigY - 45, $cx + min(55, $slot * 0.32), $sigY - 45, 0.45, [0.45,0.45,0.45]);
                    $s .= $this->text($cx, $sigY - 56, $this->truncate((string) ($sig['name'] ?? '-'), 28), 7, true, 'center');
                    $s .= $this->text($cx, $sigY - 67, (string) ($sig['role'] ?? ''), 6, false, 'center');
                }
            }
        }

        $footerY = 35.0;
        $s .= $this->line($left, $footerY + 17, $right, $footerY + 17, 0.4, [0.75, 0.75, 0.75]);
        $s .= $this->text($left, $footerY + 6, (string) ($company['name'] ?? 'PT. Aneka Produk Berkah').' · '.(string) ($company['address'] ?? ''), 5.5, false);
        $s .= $this->text($left, $footerY - 3, 'Tel '.($company['phone'] ?? '-').' · Email '.($company['email'] ?? '-'), 5.5, false);
        $s .= $this->text($pageW / 2, $footerY + 1, (string) ($company['brand_footer'] ?? 'TOKO KOPI JAYA'), 8, true, 'center');
        $s .= $this->text($right, $footerY - 3, 'Page '.$pageNo.' / '.$pageCount, 5.5, false, 'right');
        return $s;
    }

    private function partyBlock(float $x, float $y, float $w, array $p): string
    {
        if ($p === []) return '';
        $s = $this->text($x, $y, (string) ($p['label'] ?? ''), 7, true);
        $s .= $this->text($x, $y - 14, (string) ($p['name'] ?? '-'), 8, true);
        $s .= $this->text($x, $y - 27, (string) ($p['company'] ?? '-'), 7, false);
        $s .= $this->text($x, $y - 39, $this->truncate((string) ($p['address'] ?? ''), 58), 6.5, false);
        $contact = trim(((string) ($p['phone'] ?? '')).' '.((string) ($p['email'] ?? '')));
        if ($contact !== '') $s .= $this->text($x, $y - 51, $this->truncate($contact, 58), 6.5, false);
        return $s;
    }

    private function columnWidths(array $columns, float $total): array
    {
        $weights = array_map(fn ($c) => max(1.0, (float) ($c['width'] ?? 1)), $columns);
        $sum = array_sum($weights) ?: 1.0;
        return array_map(fn ($w) => $total * $w / $sum, $weights);
    }

    private function logoObject(): array
    {
        $path = resource_path('warehouse/i05/logoWarehouse.jpg');
        $data = is_file($path) ? file_get_contents($path) : '';
        $size = $path && is_file($path) ? @getimagesize($path) : false;
        $width = is_array($size) ? (int) $size[0] : 1;
        $height = is_array($size) ? (int) $size[1] : 1;
        $data = is_string($data) ? $data : '';
        return [
            'width' => $width, 'height' => $height,
            'object' => '<< /Type /XObject /Subtype /Image /Width '.$width.' /Height '.$height.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($data)." >>\nstream\n{$data}\nendstream",
        ];
    }

    private function text(float $x, float $y, string $text, float $size, bool $bold = false, string $align = 'left'): string
    {
        $text = $this->ascii($text);
        $approx = strlen($text) * $size * 0.49;
        if ($align === 'center') $x -= $approx / 2;
        if ($align === 'right') $x -= $approx;
        return sprintf("0 0 0 rg BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, $this->escape($text));
    }

    private function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $stroke = [0,0,0]): string
    {
        return sprintf("%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n", $stroke[0], $stroke[1], $stroke[2], $width, $x1, $y1, $x2, $y2);
    }

    private function rect(float $x, float $y, float $w, float $h, array $fill, array $stroke): string
    {
        return sprintf("%.3f %.3f %.3f rg %.3f %.3f %.3f RG %.2f %.2f %.2f %.2f re B\n", $fill[0],$fill[1],$fill[2],$stroke[0],$stroke[1],$stroke[2],$x,$y,$w,$h);
    }

    private function truncate(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return strlen($text) <= $max ? $text : rtrim(substr($text, 0, max(1, $max - 3))).'...';
    }

    private function ascii(string $value): string
    {
        $value = str_replace(['–','—','•','·','“','”','’','…'], ['-','-','-','-','"','"',"'",'...'], $value);
        if (function_exists('iconv')) {
            $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($out !== false) $value = $out;
        }
        return preg_replace('/[^\x20-\x7E]/', '?', $value) ?? $value;
    }

    private function escape(string $text): string { return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $text); }

    private function assemble(array $objects): string
    {
        $pdf = "%PDF-1.4\n%WAREHOUSE-PRODUCTION-REQUEST-I05\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i] ?? 0)."\n";
        $pdf .= "trailer\n<< /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }
}
