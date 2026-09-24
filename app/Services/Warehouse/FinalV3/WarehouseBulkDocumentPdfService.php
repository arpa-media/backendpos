<?php

namespace App\Services\Warehouse\FinalV3;

use App\Services\Warehouse\LogisticsV3\WarehouseLogisticsV3Service;
use App\Services\Warehouse\PurchasingV3\WarehousePurchasingV3Service;
use App\Services\Warehouse\SalesV3\WarehouseSalesDemandV3Service;
use Illuminate\Validation\ValidationException;

class WarehouseBulkDocumentPdfService
{
    public function __construct(
        private readonly WarehousePurchasingV3Service $purchasing,
        private readonly WarehouseSalesDemandV3Service $sales,
        private readonly WarehouseLogisticsV3Service $logistics,
    ) {}

    public function render(string $warehouseId, string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => trim((string) $id), $ids))));
        if ($ids === []) throw ValidationException::withMessages(['ids' => ['Pilih minimal satu dokumen.']]);
        if (count($ids) > 50) throw ValidationException::withMessages(['ids' => ['Bulk download maksimal 50 dokumen per proses.']]);

        $documents = [];
        foreach ($ids as $id) {
            $document = match ($type) {
                'purchase-request' => $this->purchasing->showRequest($id, $warehouseId),
                'purchase-order' => $this->purchasing->showOrder($id, $warehouseId),
                'stock-request' => $this->sales->stockRequestDetail($warehouseId, $id),
                'delivery-order' => $this->logistics->deliveryOrderDetail($warehouseId, $id),
                'goods-receipt' => $this->logistics->goodsReceiptDetail($warehouseId, $id),
                default => throw ValidationException::withMessages(['type' => ['Tipe dokumen bulk tidak valid.']]),
            };
            if ($type === 'stock-request' && ($document['approval_status'] ?? null) !== 'approved') {
                throw ValidationException::withMessages(['ids' => ["Stock Request {$document['request_number']} belum approved dan belum merupakan dokumen final."]]);
            }
            $documents[] = $this->documentLines($type, $document);
        }

        return [
            'content' => $this->buildPdf($documents, $type),
            'filename' => 'warehouse_'.str_replace('-', '_', $type).'_bulk_'.now()->format('Ymd_His').'.pdf',
        ];
    }

    private function documentLines(string $type, array $d): array
    {
        $title = match ($type) {
            'purchase-request' => 'PURCHASE REQUEST', 'purchase-order' => 'PURCHASE ORDER', 'stock-request' => 'STOCK REQUEST',
            'delivery-order' => 'DELIVERY ORDER', 'goods-receipt' => 'GOODS RECEIPT', default => strtoupper($type),
        };
        $number = match ($type) {
            'purchase-request' => $d['pr_number'] ?? '-', 'purchase-order' => $d['po_number'] ?? '-', 'stock-request' => $d['request_number'] ?? '-',
            'delivery-order' => $d['delivery_number'] ?? '-', 'goods-receipt' => $d['goods_receipt_number'] ?? '-', default => '-',
        };
        $lines = [
            ['text' => $title.'  '.$number, 'font' => 'B', 'size' => 15],
            ['text' => 'Warehouse ERP - Bulk Download', 'font' => 'R', 'size' => 8],
            ['text' => str_repeat('-', 94), 'font' => 'R', 'size' => 8],
        ];

        foreach ($this->metaLines($type, $d) as $line) $lines[] = ['text' => $line, 'font' => 'R', 'size' => 9];
        $lines[] = ['text' => '', 'font' => 'R', 'size' => 8];
        $lines[] = ['text' => 'ITEM', 'font' => 'B', 'size' => 9];
        $lines[] = ['text' => str_repeat('-', 94), 'font' => 'R', 'size' => 8];
        $items = (array) ($d['items'] ?? []);
        foreach ($items as $i => $item) {
            foreach ($this->itemLines($type, (array) $item, $i + 1) as $line) $lines[] = ['text' => $line, 'font' => 'R', 'size' => 8];
        }
        if ($items === []) $lines[] = ['text' => 'Tidak ada item.', 'font' => 'R', 'size' => 8];
        $lines[] = ['text' => '', 'font' => 'R', 'size' => 8];
        $lines[] = ['text' => 'Generated: '.now()->timezone('Asia/Jakarta')->format('d/m/Y H:i:s').' WIB', 'font' => 'R', 'size' => 7];
        return ['title' => $title.' '.$number, 'lines' => $lines];
    }

    private function metaLines(string $type, array $d): array
    {
        $supplier = $d['supplier']['name'] ?? null;
        $warehouse = $d['warehouse']['name'] ?? null;
        $destination = $d['destination']['name'] ?? null;
        $outlet = $d['outlet']['name'] ?? null;
        return array_values(array_filter([
            'Status: '.($d['status'] ?? $d['approval_status'] ?? '-'),
            $supplier ? 'Supplier: '.$supplier : null,
            $warehouse ? 'Warehouse: '.$warehouse : null,
            $outlet ? 'Outlet: '.$outlet : null,
            $destination ? 'Tujuan: '.$destination : null,
            !empty($d['pr_number']) && $type === 'purchase-order' ? 'Purchase Request: '.$d['pr_number'] : null,
            !empty($d['source_number']) ? 'Source: '.$d['source_number'] : null,
            !empty($d['request_date']) ? 'Request Date: '.$d['request_date'].(!empty($d['needed_date']) ? ' | Needed: '.$d['needed_date'] : '') : null,
            !empty($d['receipt_date']) ? 'Receipt Date: '.$d['receipt_date'] : null,
            !empty($d['estimated_delivery_date']) ? 'Estimate Delivery: '.$d['estimated_delivery_date'].' '.substr((string)($d['estimated_delivery_time'] ?? ''), 0, 5) : null,
            !empty($d['notes']) ? 'Notes: '.$d['notes'] : null,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    private function itemLines(string $type, array $item, int $index): array
    {
        $sku = $item['sku_code'] ?? $item['code'] ?? '-';
        $name = $item['item_name'] ?? $item['sku_name'] ?? $item['name'] ?? '-';
        $uom = $item['request_uom']['code'] ?? $item['purchase_uom_code'] ?? $item['uom_code'] ?? $item['base_uom_code'] ?? 'UNIT';
        $base = $item['base_uom']['code'] ?? $item['base_uom_code'] ?? $uom;
        $qty = fn ($v) => number_format((float)($v ?? 0), 4, '.', '');
        $money = fn ($v) => number_format((float)($v ?? 0), 2, '.', ',');

        $main = sprintf('%02d. %s | %s', $index, $sku, $name);
        $detail = match ($type) {
            'purchase-request' => 'Requested '.$qty($item['requested_qty_uom'] ?? 0).' '.$uom.' | Approved '.$qty($item['approved_qty_uom'] ?? 0).' '.$uom,
            'purchase-order' => 'Order '.$qty($item['ordered_qty_uom'] ?? 0).' '.$uom.' | Actual '.$qty($item['actual_qty_uom'] ?? 0).' '.$uom.' | Rp '.$money($item['actual_purchase_price'] ?? $item['approved_purchase_price'] ?? 0),
            'stock-request' => 'Requested '.$qty($item['requested_qty_uom'] ?? 0).' '.$uom.' | Approved '.$qty($item['approved_qty_uom'] ?? 0).' '.$uom.' | Base '.$qty($item['approved_qty_base'] ?? 0).' '.$base,
            'delivery-order' => 'Sent '.$qty($item['sent_qty_uom'] ?? $item['sent_qty_base'] ?? 0).' '.$uom,
            'goods-receipt' => 'Received '.$qty($item['received_qty_uom'] ?? $item['received_qty_base'] ?? 0).' '.$uom.' | Not Received '.$qty($item['not_received_qty_uom'] ?? $item['not_received_qty_base'] ?? 0).' '.$uom,
            default => '',
        };
        return [$main, '    '.$detail];
    }

    private function buildPdf(array $documents, string $type): string
    {
        $thermal = in_array($type, ['stock-request', 'delivery-order'], true);
        $pages = [];
        foreach ($documents as $document) {
            if ($thermal) {
                $pages[] = ['lines' => $document['lines'], 'thermal' => true];
                continue;
            }
            $chunks = array_chunk($document['lines'], 48);
            foreach ($chunks as $index => $chunk) {
                if ($index > 0) array_unshift($chunk, ['text' => $document['title'].' (lanjutan)', 'font' => 'B', 'size' => 12]);
                $pages[] = ['lines' => $chunk, 'thermal' => false];
            }
        }
        if ($pages === []) $pages = [['lines' => [['text' => 'Warehouse Bulk Document', 'font' => 'B', 'size' => 14]], 'thermal' => $thermal]];

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $kids = [];
        $next = 5;
        foreach ($pages as $pageIndex => $page) {
            $lines = $page['lines'];
            $isThermal = (bool) $page['thermal'];
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = $pageId.' 0 R';
            $stream = '';

            if ($isThermal) {
                $rendered = [];
                foreach ($lines as $line) {
                    $size = min((int) ($line['size'] ?? 8), 10);
                    foreach ($this->wrap((string) ($line['text'] ?? ''), $size <= 7 ? 47 : 40) as $wrapped) {
                        $rendered[] = ['text' => $wrapped, 'font' => $line['font'] ?? 'R', 'size' => $size];
                    }
                }
                $pageHeight = max(260, min(2000, 42 + count($rendered) * 11));
                $y = $pageHeight - 18;
                foreach ($rendered as $line) {
                    $font = ($line['font'] ?? 'R') === 'B' ? 'F2' : 'F1';
                    $size = (int) ($line['size'] ?? 7);
                    $escaped = $this->pdfEscape((string) $line['text']);
                    $stream .= "BT /{$font} {$size} Tf 8 {$y} Td ({$escaped}) Tj ET\n";
                    $y -= max(9, $size + 2);
                }
                $footer = $this->pdfEscape('Page '.($pageIndex + 1).' / '.count($pages));
                $stream .= "BT /F1 6 Tf 8 8 Td ({$footer}) Tj ET\n";
                $mediaBox = '[0 0 226.77 '.number_format($pageHeight, 2, '.', '').']';
            } else {
                $y = 800;
                foreach ($lines as $line) {
                    $font = ($line['font'] ?? 'R') === 'B' ? 'F2' : 'F1';
                    $size = (int) ($line['size'] ?? 9);
                    foreach ($this->wrap((string)($line['text'] ?? ''), $size <= 8 ? 118 : 100) as $wrapped) {
                        $escaped = $this->pdfEscape($wrapped);
                        $stream .= "BT /{$font} {$size} Tf 42 {$y} Td ({$escaped}) Tj ET\n";
                        $y -= max(11, $size + 3);
                    }
                }
                $footer = $this->pdfEscape('Page '.($pageIndex + 1).' / '.count($pages));
                $stream .= "BT /F1 7 Tf 500 24 Td ({$footer}) Tj ET\n";
                $mediaBox = '[0 0 595 842]';
            }

            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox {$mediaBox} /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = "<< /Length ".strlen($stream)." >>\nstream\n{$stream}endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%ERP-FINANCE-V7-I01\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i] ?? 0)."\n";
        $pdf .= "trailer\n<< /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    private function wrap(string $text, int $width): array
    {
        $text = $this->ascii($text);
        if ($text === '') return [''];
        return explode("\n", wordwrap($text, $width, "\n", true));
    }

    private function ascii(string $text): string
    {
        $text = str_replace(['–','—','·','≈','×','→'], ['-','-',' / ','~','x','->'], $text);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $converted;
    }

    private function pdfEscape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
    }
}
