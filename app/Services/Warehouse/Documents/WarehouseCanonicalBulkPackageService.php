<?php

namespace App\Services\Warehouse\Documents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class WarehouseCanonicalBulkPackageService
{
    private const MAX_PACKAGE_BYTES = 209715200; // 200 MiB hard guard.

    public function __construct(
        private readonly WarehouseCanonicalDocumentService $documents,
        private readonly WarehouseCanonicalPdfRenderer $pdf,
    ) {}

    public function render(string $warehouseId, string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => trim((string) $id), $ids))));
        if ($ids === []) throw ValidationException::withMessages(['ids' => ['Pilih minimal satu dokumen.']]);
        if (count($ids) > 50) throw ValidationException::withMessages(['ids' => ['Bulk download maksimal 50 dokumen per proses.']]);

        return match ($type) {
            'purchase-request' => $this->purchaseRequests($warehouseId, $ids),
            'purchase-order' => $this->purchaseOrders($warehouseId, $ids),
            default => throw ValidationException::withMessages(['type' => ['Canonical bulk package hanya untuk Purchase Request / Purchase Order.']]),
        };
    }

    private function purchaseRequests(string $warehouseId, array $ids): array
    {
        $docs = array_map(fn ($id) => $this->documents->purchaseRequest($warehouseId, $id), $ids);
        return [
            'content' => $this->pdf->render($docs),
            'filename' => 'warehouse_purchase_request_bulk_'.now('Asia/Jakarta')->format('Ymd_His').'.pdf',
            'content_type' => 'application/pdf',
        ];
    }

    private function purchaseOrders(string $warehouseId, array $ids): array
    {
        $entries = [];
        $manifest = [];
        $estimatedSize = 0;
        foreach ($ids as $id) {
            $doc = $this->documents->purchaseOrder($warehouseId, $id);
            $poNumber = $this->safeName((string) ($doc['number'] ?? $id));
            $pdf = $this->pdf->render([$doc]);
            $pdfPath = 'documents/'.$poNumber.'.pdf';
            $entries[] = ['name' => $pdfPath, 'content' => $pdf];
            $estimatedSize += strlen($pdf);
            $manifest[] = ['purchase_order' => $doc['number'] ?? $id, 'type' => 'canonical_pdf', 'file' => $pdfPath, 'sha256' => hash('sha256', $pdf), 'size' => strlen($pdf)];

            $files = DB::table('wh_purchase_invoices')->where('purchase_order_id', $id)->orderBy('uploaded_at')->get(['id','original_name','storage_disk','storage_path','mime_type','file_size','sha256','uploaded_at']);
            foreach ($files as $index => $file) {
                $disk = Storage::disk((string) $file->storage_disk);
                if (! $disk->exists((string) $file->storage_path)) {
                    throw ValidationException::withMessages(['ids' => ["Lampiran invoice {$file->original_name} untuk PO {$doc['number']} tidak ditemukan di storage."]]);
                }
                $size = (int) ($file->file_size ?: $disk->size((string) $file->storage_path));
                $estimatedSize += $size;
                if ($estimatedSize > self::MAX_PACKAGE_BYTES) throw ValidationException::withMessages(['ids' => ['Total paket Bulk PO melebihi 200 MB. Kurangi jumlah PO terpilih.']]);
                $content = $disk->get((string) $file->storage_path);
                $name = 'supplier-invoices/'.$poNumber.'/'.sprintf('%02d', $index + 1).'_'.$this->safeName((string) $file->original_name, true);
                $entries[] = ['name' => $name, 'content' => $content];
                $manifest[] = [
                    'purchase_order' => $doc['number'] ?? $id, 'type' => 'supplier_invoice', 'file' => $name,
                    'original_name' => (string) $file->original_name, 'mime_type' => (string) ($file->mime_type ?: 'application/octet-stream'),
                    'sha256' => (string) ($file->sha256 ?: hash('sha256', $content)), 'size' => strlen($content), 'uploaded_at' => (string) $file->uploaded_at,
                ];
            }
        }
        $manifestJson = json_encode(['generated_at' => now('Asia/Jakarta')->toIso8601String(), 'template_source' => 'PT Aneka Produk Berkah.xlsx', 'entries' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $entries[] = ['name' => 'manifest.json', 'content' => (string) $manifestJson];
        return [
            'content' => $this->zipStore($entries),
            'filename' => 'warehouse_purchase_order_bulk_'.now('Asia/Jakarta')->format('Ymd_His').'.zip',
            'content_type' => 'application/zip',
        ];
    }

    private function zipStore(array $entries): string
    {
        $body = '';
        $central = '';
        $offset = 0;
        $count = 0;
        foreach ($entries as $entry) {
            $name = str_replace('\\', '/', ltrim((string) $entry['name'], '/'));
            $content = (string) $entry['content'];
            $crc = hexdec(hash('crc32b', $content));
            $size = strlen($content);
            [$time, $date] = $this->dosTimeDate();
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $time, $date, $crc, $size, $size, strlen($name), 0).$name.$content;
            $body .= $local;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
            $offset += strlen($local);
            $count++;
        }
        $centralOffset = strlen($body);
        $body .= $central;
        $body .= pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $centralOffset, 0);
        return $body;
    }

    private function dosTimeDate(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta'));
        $hour = (int) $now->format('G'); $minute = (int) $now->format('i'); $second = (int) $now->format('s');
        $month = (int) $now->format('n'); $day = (int) $now->format('j'); $year = max(1980, (int) $now->format('Y'));
        $time = (($hour & 0x1f) << 11) | (($minute & 0x3f) << 5) | ((int) floor($second / 2) & 0x1f);
        $date = ((($year - 1980) & 0x7f) << 9) | (($month & 0x0f) << 5) | ($day & 0x1f);
        return [$time, $date];
    }

    private function safeName(string $name, bool $keepExtension = false): string
    {
        $name = trim(str_replace(['/', '\\'], '-', $name));
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'document';
        $name = trim($name, '. ');
        if ($name === '') $name = 'document';
        if (! $keepExtension) $name = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $name) ?: $name;
        return substr($name, 0, 150);
    }
}
