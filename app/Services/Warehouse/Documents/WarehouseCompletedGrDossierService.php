<?php

namespace App\Services\Warehouse\Documents;

use App\Services\Warehouse\LogisticsV3\WarehouseLogisticsV3Service;
use App\Services\Warehouse\SalesV3\WarehouseSalesDemandV3Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Read-only, scope-checked dossier: source -> all sibling DO/GR -> chronological events. */
final class WarehouseCompletedGrDossierService
{
    private const COMPANY = [
        'name' => 'PT. Aneka Produk Berkah',
        'address' => 'Jl. Candi Penataran No. 43C, Malang, Jawa Timur',
        'phone' => '+62822 3461 7007',
        'email' => 'warehouse.jayagroup@gmail.com',
        'brand_footer' => 'TOKO KOPI JAYA',
    ];

    public function __construct(
        private readonly WarehouseLogisticsV3Service $logistics,
        private readonly WarehouseSalesDemandV3Service $sales,
        private readonly WarehouseCanonicalPdfRenderer $renderer,
    ) {}

    public function render(string $warehouseId, string $receiptId): array
    {
        // Detail service enforces selected warehouse scope before any related data is read.
        $target = $this->logistics->goodsReceiptDetail($warehouseId, $receiptId);
        if ((string) ($target['status'] ?? '') !== 'completed') {
            throw ValidationException::withMessages(['status' => ['Berkas lengkap hanya tersedia untuk Goods Receipt yang sudah Completed.']]);
        }
        $sourceType = (string) ($target['source_type'] ?? '');
        $sourceId = (string) ($target['source_id'] ?? '');
        if ($sourceId === '') {
            throw ValidationException::withMessages(['source' => ['Referensi sumber Goods Receipt tidak tersedia.']]);
        }

        $deliveryRows = DB::table('wh_v3_delivery_orders')
            ->where('warehouse_id', $warehouseId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'delivery_number', 'prepare_request_id', 'created_at']);
        if ($deliveryRows->count() > 100) {
            throw ValidationException::withMessages(['documents' => ['Jumlah DO terkait melebihi batas aman 100 dokumen. Hubungi administrator untuk ekspor arsip besar.']]);
        }
        if (! $deliveryRows->contains(fn ($row) => (string) $row->id === (string) $target['delivery_order_id'])) {
            throw ValidationException::withMessages(['documents' => ['DO Goods Receipt tidak ditemukan dalam lingkup dokumen sumber.']]);
        }

        $source = null;
        if ($sourceType === 'stock_request') {
            $source = $this->sales->stockRequestDetail($warehouseId, $sourceId);
        }
        $documents = [];
        $events = [];
        $sourceNumber = (string) ($source['request_number'] ?? $target['source_number'] ?? '-');
        $destination = (array) ($target['destination'] ?? []);
        $warehouse = (array) ($target['warehouse'] ?? []);
        $coverRows = [
            ['1', 'Sumber', $sourceNumber, $sourceType === 'stock_request' ? 'Stock Request' : $sourceType],
            ['2', 'Good Receipt Terpilih', (string) $target['goods_receipt_number'], 'Completed'],
            ['3', 'Jumlah DO Terkait', (string) $deliveryRows->count(), 'Seluruh DO dalam sumber yang sama'],
        ];
        $documents[] = $this->doc('Berkas Lengkap Good Receipt', 'DOSSIER', (string) $target['goods_receipt_number'],
            (string) ($target['completed_at'] ?? ''), 'COMPLETED', $warehouse, $destination,
            [['label' => 'Stock Request / Sumber', 'value' => $sourceNumber], ['label' => 'GR Terpilih', 'value' => (string) $target['goods_receipt_number']]],
            [['label' => '#', 'width' => 8], ['label' => 'Dokumen', 'width' => 36], ['label' => 'Referensi', 'width' => 38], ['label' => 'Status', 'width' => 18]], $coverRows,
            'Termasuk semua DO dan GR yang terkait sumber ini. Status dokumen lain ditampilkan apa adanya.');

        if ($source !== null) {
            $rows = [];
            foreach ((array) ($source['items'] ?? []) as $i => $item) {
                $rows[] = [(string) ($i + 1), $this->itemName($item), $this->qty($item['requested_qty_uom'] ?? 0),
                    $this->qty($item['approved_qty_uom'] ?? 0), (string) ($item['uom_code'] ?? 'UNIT')];
            }
            $documents[] = $this->doc('Lampiran - Stock Request', 'WSR', $sourceNumber, (string) ($source['request_date'] ?? ''),
                (string) ($source['approval_status'] ?? '-'), $warehouse, $destination,
                [['label' => 'Diajukan', 'value' => (string) ($source['submitted_at'] ?? '-')],
                 ['label' => 'Disetujui', 'value' => (string) ($source['approved_at'] ?? '-')]],
                [['label' => '#', 'width' => 8], ['label' => 'SKU / Item', 'width' => 48], ['label' => 'Request', 'width' => 14],
                 ['label' => 'Approved', 'width' => 16], ['label' => 'UOM', 'width' => 14]], $rows,
                'Dokumen sumber asli. Tidak mengubah persetujuan maupun stok.', (string) ($source['notes'] ?? ''));
            $this->addEvents($events, (array) ($source['timeline'] ?? []), 'Stock Request '.$sourceNumber);
            foreach (['submitted_at' => 'Diajukan', 'approved_at' => 'Disetujui'] as $field => $action) {
                if (! empty($source[$field])) $this->addMilestone($events, $source[$field], 'Stock Request '.$sourceNumber, $action);
            }
        } else {
            $documents[] = $this->doc('Referensi Sumber', 'SOURCE', $sourceNumber, '', strtoupper($sourceType), $warehouse, $destination,
                [['label' => 'Jenis sumber', 'value' => $sourceType]],
                [['label' => 'Informasi', 'width' => 35], ['label' => 'Nilai', 'width' => 65]],
                [['Nomor sumber', $sourceNumber], ['Jenis sumber', $sourceType]],
                'Jenis sumber ini bukan Stock Request. Dokumen WSR tidak dibuat agar tidak menampilkan referensi fiktif.');
        }

        // Prepare events are collected once even if many DOs share the same prepare.
        $prepareIds = $deliveryRows->pluck('prepare_request_id')->filter()->unique()->values()->all();
        if ($prepareIds !== [] && Schema::hasTable('wh_v3_logistics_events')) {
            $prepareEvents = DB::table('wh_v3_logistics_events as e')
                ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
                ->where('e.document_type', 'prepare_request')->whereIn('e.document_id', $prepareIds)
                ->orderBy('e.occurred_at')->get(['e.document_id','e.occurred_at','e.event_type','e.message','u.name as actor_name']);
            foreach ($prepareEvents as $event) {
                $this->addMilestone($events, $event->occurred_at, 'Checker Prepare', (string) $event->event_type,
                    (string) ($event->actor_name ?? ''), (string) ($event->message ?? ''));
            }
        }

        $relatedGrs = DB::table('wh_v3_goods_receipts')->where('warehouse_id', $warehouseId)
            ->whereIn('delivery_order_id', $deliveryRows->pluck('id')->all())
            ->get(['id','delivery_order_id'])->keyBy('delivery_order_id');

        foreach ($deliveryRows as $orderRow) {
            $order = $this->logistics->deliveryOrderDetail($warehouseId, (string) $orderRow->id);
            $rows = [];
            foreach ((array) ($order['items'] ?? []) as $i => $item) {
                $rows[] = [(string) ($i + 1), $this->itemName($item), $this->qty($item['requested_qty_uom'] ?? 0),
                    $this->qty($item['approved_qty_uom'] ?? 0), $this->qty($item['sent_qty_uom'] ?? 0),
                    (string) ($item['uom_code'] ?? 'UNIT')];
            }
            $documents[] = $this->doc('Lampiran - Delivery Order', 'DO', (string) $order['delivery_number'],
                (string) ($order['dispatched_at'] ?? ''), (string) ($order['status'] ?? '-'), $warehouse, $destination,
                [['label' => 'Stock Request', 'value' => $sourceNumber],
                 ['label' => 'Pengirim', 'value' => (string) ($order['sender']['name'] ?? '-')],
                 ['label' => 'Dispatched', 'value' => (string) ($order['dispatched_at'] ?? '-')]],
                [['label' => '#', 'width' => 7], ['label' => 'SKU / Item', 'width' => 41], ['label' => 'Request', 'width' => 13],
                 ['label' => 'Approved', 'width' => 13], ['label' => 'Kirim', 'width' => 13], ['label' => 'UOM', 'width' => 13]],
                $rows, 'Detail DO sesuai snapshot pengiriman; Qty tidak dijumlah antar UOM berbeda.', (string) ($order['notes'] ?? ''));
            $this->addEvents($events, (array) ($order['timeline'] ?? []), 'DO '.(string) $order['delivery_number']);
            $this->addMilestone($events, $order['dispatched_at'] ?? null, 'DO '.(string) $order['delivery_number'], 'Dikirim');

            $grRow = $relatedGrs->get((string) $orderRow->id);
            if (! $grRow) continue;
            $receipt = (string) $grRow->id === $receiptId
                ? $target : $this->logistics->goodsReceiptDetail($warehouseId, (string) $grRow->id);
            $rows = [];
            foreach ((array) ($receipt['items'] ?? []) as $i => $item) {
                $rows[] = [(string) ($i + 1), $this->itemName($item), $this->qty($item['sent_qty_uom'] ?? 0),
                    $this->qty($item['received_qty_uom'] ?? 0), $this->qty($item['not_received_qty_uom'] ?? 0),
                    (string) ($item['uom_code'] ?? 'UNIT')];
            }
            $number = (string) $receipt['goods_receipt_number'];
            $documents[] = $this->doc('Lampiran - Good Receipt', 'GR', $number,
                (string) ($receipt['receipt_date'] ?? ''), (string) ($receipt['status'] ?? '-'), $warehouse, $destination,
                [['label' => 'Delivery Order', 'value' => (string) $order['delivery_number']],
                 ['label' => 'Receiver', 'value' => (string) ($receipt['receiver']['name'] ?? '-')],
                 ['label' => 'Diterima', 'value' => (string) ($receipt['received_at'] ?? '-')],
                 ['label' => 'Completed', 'value' => (string) ($receipt['completed_at'] ?? '-')]],
                [['label' => '#', 'width' => 7], ['label' => 'SKU / Item', 'width' => 43], ['label' => 'Dikirim', 'width' => 14],
                 ['label' => 'Diterima', 'width' => 14], ['label' => 'Selisih', 'width' => 12], ['label' => 'UOM', 'width' => 10]],
                $rows, $receiptId === (string) $receipt['id'] ? 'Goods Receipt terpilih sebagai titik akhir berkas.' : 'Goods Receipt terkait DO yang sama sumbernya.',
                (string) ($receipt['notes'] ?? ''));
            $this->addEvents($events, (array) ($receipt['timeline'] ?? []), 'GR '.$number);
            $this->addMilestone($events, $receipt['received_at'] ?? null, 'GR '.$number, 'Diterima');
            $this->addMilestone($events, $receipt['completed_at'] ?? null, 'GR '.$number, 'Completed');
        }

        // Optional partial batches introduced by the receiving extension, all read-only.
        if (Schema::hasTable('wh_v3_partial_receipt_batches')) {
            $batches = DB::table('wh_v3_partial_receipt_batches as b')
                ->leftJoin('users as u', 'u.id', '=', 'b.received_by_user_id')
                ->whereIn('b.delivery_order_id', $deliveryRows->pluck('id')->all())
                ->orderBy('b.created_at')->get(['b.goods_receipt_id','b.receipt_date','b.created_at','b.close_shortage','u.name as actor_name']);
            foreach ($batches as $batch) {
                $this->addMilestone($events, $batch->created_at ?: $batch->receipt_date, 'GR (batch)',
                    $batch->close_shortage ? 'Tutup selisih penerimaan' : 'Partial receive', (string) ($batch->actor_name ?? ''));
            }
        }
        usort($events, static fn (array $a, array $b) => strcmp($a['sort'], $b['sort']));
        $timelineRows = [];
        foreach ($events as $i => $event) {
            $timelineRows[] = [(string) ($i + 1), $event['at'], $event['document'], $event['action'], $event['actor']];
        }
        $documents[] = $this->doc('Timeline - Stock Request s.d. Goods Receipt', 'AUDIT', $sourceNumber,
            (string) ($target['completed_at'] ?? ''), 'READ ONLY', $warehouse, $destination,
            [['label' => 'GR Completed', 'value' => (string) $target['goods_receipt_number']],
             ['label' => 'Jumlah Event', 'value' => (string) count($timelineRows)]],
            [['label' => '#', 'width' => 7], ['label' => 'Waktu Sistem', 'width' => 22], ['label' => 'Dokumen', 'width' => 27],
             ['label' => 'Peristiwa', 'width' => 29], ['label' => 'Aktor', 'width' => 15]],
            $timelineRows, 'Timeline digabung berdasarkan waktu event yang tersimpan. Tidak membuat event baru.');

        $pdf = $this->renderer->render($documents);
        if (! str_starts_with($pdf, '%PDF-')) throw new \RuntimeException('Renderer gagal menghasilkan berkas PDF valid.');
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $target['goods_receipt_number']);
        return ['content' => $pdf, 'filename' => 'GR_Berkas_Lengkap_'.$filename.'.pdf'];
    }

    private function doc(string $title, string $code, string $number, string $date, string $status, array $warehouse, array $destination,
        array $meta, array $columns, array $rows, string $description, string $notes = ''): array
    {
        return [
            'company' => self::COMPANY, 'title' => $title, 'code' => $code, 'number' => $number,
            'date' => $date !== '' ? $date : '-', 'status' => strtoupper($status),
            'reference' => '', 'left_party' => [
                'label' => 'Warehouse', 'name' => (string) ($warehouse['name'] ?? '-'),
                'company' => (string) ($warehouse['code'] ?? ''), 'address' => (string) ($warehouse['address'] ?? ''),
            ], 'right_party' => [
                'label' => 'Outlet / Tujuan', 'name' => (string) ($destination['name'] ?? '-'),
                'company' => (string) ($destination['code'] ?? ''), 'address' => (string) ($destination['address'] ?? ''),
            ], 'meta' => $meta, 'columns' => $columns, 'rows' => $rows,
            'totals' => [['label' => 'Total Baris', 'value' => (string) count($rows)]],
            'notes' => trim($description.' '.$notes), 'signatures' => [], 'attachments' => [],
        ];
    }

    private function addEvents(array &$events, array $items, string $number): void
    {
        foreach ($items as $item) {
            $action = (string) ($item['event_type'] ?? $item['event_code'] ?? '-');
            $actor = (string) ($item['actor']['name'] ?? '');
            $this->addMilestone($events, $item['created_at'] ?? null, $number, $action, $actor,
                (string) ($item['message'] ?? ''));
        }
    }

    private function addMilestone(array &$events, mixed $at, string $document, string $action, string $actor = '', string $message = ''): void
    {
        if ($at === null || trim((string) $at) === '') return;
        $time = (string) $at;
        $events[] = [
            'sort' => $time, 'at' => $time, 'document' => $document,
            'action' => trim(str_replace('_', ' ', $action)),
            'actor' => $actor !== '' ? $actor : '-', 'detail' => $message,
        ];
    }

    private function itemName(array $item): string
    {
        return trim((string) ($item['sku_code'] ?? '-').' · '.(string) ($item['item_name'] ?? '-'));
    }

    private function qty(mixed $value): string
    {
        return rtrim(rtrim(sprintf('%.4f', (float) $value), '0'), '.') ?: '0';
    }
}
