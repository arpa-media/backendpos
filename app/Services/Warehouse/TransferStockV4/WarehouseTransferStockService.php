<?php

namespace App\Services\Warehouse\TransferStockV4;

use App\Services\Warehouse\SalesTransferV3\WarehouseLogisticsV7ExtensionService;
use App\Services\Warehouse\SalesTransferV3\WarehouseSalesTransferV3Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseTransferStockService
{
    public function __construct(
        private readonly WarehouseSalesTransferV3Service $legacy,
        private readonly WarehouseLogisticsV7ExtensionService $logistics,
        private readonly WarehouseTransferSkuCatalogService $catalog,
    ) {
    }

    public function options(string $warehouseId): array
    {
        $origin = DB::table('outlets')
            ->where('id', $warehouseId)
            ->where('is_active', true)
            ->whereRaw("LOWER(COALESCE(type,''))='warehouse'")
            ->first(['id', 'code', 'name']);

        if (! $origin) {
            throw ValidationException::withMessages(['warehouse_id' => ['Warehouse origin aktif tidak ditemukan.']]);
        }

        $warehouses = DB::table('outlets')
            ->where('is_active', true)
            ->whereRaw("LOWER(COALESCE(type,''))='warehouse'")
            ->where('id', '<>', $warehouseId)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ])->values()->all();

        return [
            'current_warehouse' => [
                'id' => (string) $origin->id,
                'code' => (string) $origin->code,
                'name' => (string) $origin->name,
            ],
            'warehouses' => $warehouses,
            'skus' => $this->catalog->forWarehouse($warehouseId),
        ];
    }

    public function list(string $warehouseId, array $filters): array
    {
        $result = $this->legacy->listTransfers($warehouseId, $filters);
        $result['items'] = collect($result['items'] ?? [])->map(function (array $row): array {
            $row['transfer_date'] = $row['date'] ?? null;
            return $row;
        })->values()->all();
        return $result;
    }

    public function detail(string $warehouseId, string $id): array
    {
        $detail = $this->legacy->detailTransfer($warehouseId, $id);
        $detail['transfer_date'] = $detail['date'] ?? null;
        $origin = DB::table('outlets')->where('id', $warehouseId)->first(['id', 'code', 'name']);
        $detail['origin'] = $origin ? [
            'id' => (string) $origin->id,
            'code' => (string) $origin->code,
            'name' => (string) $origin->name,
        ] : null;

        $prepareId = $detail['prepare_request_id'] ?? null;
        if ($prepareId) {
            $delivery = DB::table('wh_v3_delivery_orders')->where('prepare_request_id', $prepareId)->first();
            $gr = $delivery ? DB::table('wh_v3_goods_receipts')->where('delivery_order_id', $delivery->id)->first() : null;
            if ($gr) {
                $detail['logistics']['goods_receipt'] = array_merge($detail['logistics']['goods_receipt'] ?? [], [
                    'id' => (string) $gr->id,
                    'number' => (string) $gr->goods_receipt_number,
                    'status' => (string) $gr->status,
                    'origin_ledger_posting_id' => $gr->ledger_posting_id ? (string) $gr->ledger_posting_id : null,
                    'destination_ledger_posting_id' => $gr->destination_ledger_posting_id ? (string) $gr->destination_ledger_posting_id : null,
                    'outgoing_invoice_id' => $gr->outgoing_invoice_id ? (string) $gr->outgoing_invoice_id : null,
                ]);
            }
        }
        return $detail;
    }

    public function save(string $warehouseId, array $payload, string $userId, ?string $id = null): array
    {
        return $this->legacy->saveTransfer($warehouseId, $payload, $userId, $id);
    }

    public function submit(string $warehouseId, string $id, string $userId): array
    {
        return $this->legacy->submit('transfer_stock', $warehouseId, $id, $userId);
    }

    public function approve(string $warehouseId, string $id, array $payload, string $userId): array
    {
        return $this->legacy->approve('transfer_stock', $warehouseId, $id, $payload, $userId);
    }

    public function receivingList(string $warehouseId, array $filters): array
    {
        return $this->logistics->warehouseDeliveries($warehouseId, $filters);
    }

    public function receivingDetail(string $warehouseId, string $deliveryId): array
    {
        return $this->logistics->warehouseDeliveryDetail($warehouseId, $deliveryId);
    }

    public function receive(string $warehouseId, string $deliveryId, array $payload, string $userId): array
    {
        return $this->logistics->receiveAtWarehouse($warehouseId, $deliveryId, $payload, $userId);
    }
}
