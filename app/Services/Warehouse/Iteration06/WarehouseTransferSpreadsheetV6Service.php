<?php

namespace App\Services\Warehouse\Iteration06;

use App\Services\Support\SimpleXlsxService;
use App\Services\Warehouse\TransferStockV4\WarehouseTransferSkuCatalogService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class WarehouseTransferSpreadsheetV6Service
{
    public function __construct(
        private readonly SimpleXlsxService $xlsx,
        private readonly WarehouseTransferSkuCatalogService $catalog,
    ) {}

    public function template(string $warehouseId): Response
    {
        $origin = $this->warehouse($warehouseId);
        $destinations = DB::table('outlets')
            ->where('is_active', true)
            ->whereRaw("LOWER(COALESCE(type,''))='warehouse'")
            ->where('id', '<>', $warehouseId)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
        $skus = $this->catalog->forWarehouse($warehouseId);
        $readyStock = $this->readyStock($warehouseId, array_values(array_filter(array_map(fn ($sku) => (string) ($sku['id'] ?? ''), $skus))));

        $skuRows = [['sku_code', 'item_name', 'uom_code', 'uom_name', 'conversion_to_base', 'base_uom_code', 'actual_stock_base', 'dispatch_ready_base']];
        foreach ($skus as $sku) {
            foreach (($sku['uoms'] ?? []) as $uom) {
                if (($uom['is_request_enabled'] ?? true) === false) continue;
                $skuRows[] = [
                    $sku['sku_code'] ?? '', $sku['name'] ?? '', $uom['code'] ?? '', $uom['name'] ?? '',
                    $uom['conversion_factor'] ?? 1, $sku['base_uom']['code'] ?? '', $sku['on_hand_qty'] ?? 0, $readyStock[(string) ($sku['id'] ?? '')] ?? 0,
                ];
            }
        }

        $destinationRows = [['warehouse_code', 'warehouse_name']];
        foreach ($destinations as $row) $destinationRows[] = [(string) $row->code, (string) $row->name];

        return $this->xlsx->downloadWorkbook('template_transfer_stock_'.$origin->code.'_'.now('Asia/Jakarta')->format('Ymd_His').'.xlsx', [
            ['name' => 'TRANSFER STOCK', 'rows' => [
                ['origin_warehouse_code', 'destination_warehouse_code', 'transfer_date', 'needed_date', 'sku_code', 'uom_code', 'qty', 'notes'],
                [(string) $origin->code, '', now('Asia/Jakarta')->toDateString(), now('Asia/Jakarta')->toDateString(), '', '', '', ''],
            ]],
            ['name' => 'MASTER WAREHOUSE', 'rows' => $destinationRows],
            ['name' => 'MASTER SKU UOM', 'rows' => $skuRows],
            ['name' => 'PETUNJUK', 'rows' => [
                ['TRANSFER STOCK IMPORT — ERP REV ITERATION 06'],
                ['1', 'Satu file hanya untuk satu dokumen Transfer Stock dan satu Warehouse tujuan.'],
                ['2', 'origin_warehouse_code wajib sama dengan Warehouse yang sedang dipilih di Portal Warehouse.'],
                ['3', 'destination_warehouse_code ambil dari sheet MASTER WAREHOUSE dan wajib berbeda dari origin.'],
                ['4', 'transfer_date dan needed_date gunakan YYYY-MM-DD. needed_date tidak boleh sebelum transfer_date.'],
                ['5', 'sku_code + uom_code ambil dari MASTER SKU UOM. SKU tidak boleh duplikat dalam satu dokumen.'],
                ['6', 'qty adalah Qty dalam UOM transaksi. Import hanya mengisi form Draft; user tetap menekan Simpan Draft.'],
                ['7', 'Submit/Approve akan memvalidasi stock batch yang benar-benar siap dispatch, bukan hanya angka tampilan.'],
            ]],
        ]);
    }

    public function preview(string $warehouseId, UploadedFile $file): array
    {
        $origin = $this->warehouse($warehouseId);
        $sheet = $this->selectSheet($this->xlsx->readWorksheets($file));
        $rows = array_values($sheet['rows']);
        if (count($rows) < 2) {
            throw ValidationException::withMessages(['file' => ['Sheet TRANSFER STOCK belum memiliki baris data.']]);
        }

        $header = array_shift($rows);
        $map = $this->headerMap((array) $header);
        foreach (['origin_warehouse_code','destination_warehouse_code','transfer_date','sku_code','uom_code','qty'] as $required) {
            if (! isset($map[$required])) throw ValidationException::withMessages(['file' => ["Header {$required} wajib tersedia."]]);
        }

        $warehouses = DB::table('outlets')->where('is_active', true)->whereRaw("LOWER(COALESCE(type,''))='warehouse'")
            ->get(['id', 'code', 'name'])->keyBy(fn ($row) => mb_strtoupper(trim((string) $row->code)));
        $catalogRows = $this->catalog->forWarehouse($warehouseId);
        $skus = collect($catalogRows)->keyBy(fn ($row) => mb_strtoupper(trim((string) ($row['sku_code'] ?? ''))));
        $readyStock = $this->readyStock($warehouseId, array_values(array_filter(array_map(fn ($sku) => (string) ($sku['id'] ?? ''), $catalogRows))));

        $payload = null;
        $seenSku = [];
        $warnings = [];
        $items = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $lineNo = $index + 2;
            $row = (array) $row;
            if (collect($row)->every(fn ($value) => trim((string) $value) === '')) continue;
            $get = fn (string $key): string => trim((string) ($row[$map[$key] ?? -1] ?? ''));
            try {
                $originCode = mb_strtoupper($get('origin_warehouse_code'));
                if ($originCode !== mb_strtoupper((string) $origin->code)) {
                    throw new \RuntimeException("Origin {$originCode} tidak sama dengan Warehouse aktif {$origin->code}.");
                }
                $destinationCode = mb_strtoupper($get('destination_warehouse_code'));
                $destination = $warehouses->get($destinationCode);
                if (! $destination) throw new \RuntimeException("Warehouse tujuan {$destinationCode} tidak ditemukan/aktif.");
                if ((string) $destination->id === $warehouseId) throw new \RuntimeException('Warehouse tujuan harus berbeda dari origin.');

                $transferDate = $this->date($get('transfer_date'), 'transfer_date');
                $neededDateRaw = $get('needed_date');
                $neededDate = $neededDateRaw === '' ? $transferDate : $this->date($neededDateRaw, 'needed_date');
                if ($neededDate < $transferDate) throw new \RuntimeException('needed_date tidak boleh sebelum transfer_date.');

                $signature = [$destinationCode, $transferDate, $neededDate];
                if ($payload === null) {
                    $payload = [
                        'destination_warehouse_id' => (string) $destination->id,
                        'destination_warehouse_code' => (string) $destination->code,
                        'destination_warehouse_name' => (string) $destination->name,
                        'transfer_date' => $transferDate,
                        'needed_date' => $neededDate,
                        'notes' => 'Imported from XLSX · '.now('Asia/Jakarta')->format('Y-m-d H:i:s'),
                    ];
                    $payload['_signature'] = $signature;
                } elseif ($payload['_signature'] !== $signature) {
                    throw new \RuntimeException('Semua baris harus memiliki Warehouse tujuan, transfer_date, dan needed_date yang sama.');
                }

                $skuCode = mb_strtoupper($get('sku_code'));
                $sku = $skus->get($skuCode);
                if (! $sku) throw new \RuntimeException("SKU {$skuCode} tidak ditemukan/aktif untuk Warehouse origin.");
                if (isset($seenSku[(string) $sku['id']])) throw new \RuntimeException("SKU {$skuCode} duplikat. Satu SKU hanya boleh satu baris per Transfer Stock.");

                $uomCode = mb_strtoupper($get('uom_code'));
                $uom = collect($sku['uoms'] ?? [])->first(fn ($u) => mb_strtoupper((string) ($u['code'] ?? '')) === $uomCode && ($u['is_request_enabled'] ?? true) !== false);
                if (! $uom) throw new \RuntimeException("UOM {$uomCode} tidak valid untuk SKU {$skuCode}.");

                $qtyText = str_replace(',', '.', $get('qty'));
                if (! is_numeric($qtyText) || (float) $qtyText <= 0) throw new \RuntimeException('qty wajib numerik dan lebih besar dari nol.');
                $qty = round((float) $qtyText, 4);
                $base = round($qty * (float) ($uom['conversion_factor'] ?? 0), 4);
                if ($base <= 0) throw new \RuntimeException('Konversi UOM ke Base tidak valid.');
                $onHand = round((float) ($sku['on_hand_qty'] ?? 0), 4);
                $ready = round((float) ($readyStock[(string) $sku['id']] ?? 0), 4);
                if ($base > $ready + 0.0001) {
                    $warnings[] = "Baris {$lineNo} · {$skuCode}: kebutuhan {$base} base melebihi stock batch siap dispatch {$ready} base (Actual Stock tampilan {$onHand} base). Draft tetap dapat direview, tetapi Submit/Approve akan ditolak sampai stock siap.";
                }

                $seenSku[(string) $sku['id']] = true;
                $items[] = [
                    'sku_id' => (string) $sku['id'], 'sku_code' => (string) $sku['sku_code'], 'item_name' => (string) $sku['name'],
                    'uom_id' => (string) $uom['id'], 'uom_code' => (string) $uom['code'], 'requested_qty_uom' => $qty,
                    'requested_qty_base' => $base, 'notes' => $get('notes') ?: null,
                ];
            } catch (\Throwable $e) {
                $errors[] = ['line' => $lineNo, 'message' => $e->getMessage()];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => array_map(fn ($e) => "Baris {$e['line']}: {$e['message']}", array_slice($errors, 0, 25))]);
        }
        if ($payload === null || $items === []) {
            throw ValidationException::withMessages(['file' => ['Tidak ada baris Transfer Stock valid untuk diimport.']]);
        }
        unset($payload['_signature']);
        $payload['items'] = $items;

        return [
            'payload' => $payload,
            'summary' => [
                'origin' => ['id' => $warehouseId, 'code' => (string) $origin->code, 'name' => (string) $origin->name],
                'destination' => ['id' => $payload['destination_warehouse_id'], 'code' => $payload['destination_warehouse_code'], 'name' => $payload['destination_warehouse_name']],
                'line_count' => count($items),
                'total_qty_base' => round(array_sum(array_column($items, 'requested_qty_base')), 4),
                'sheet_name' => $sheet['name'],
            ],
            'warnings' => $warnings,
        ];
    }

    /** @param array<int,string> $skuIds @return array<string,float> */
    private function readyStock(string $warehouseId, array $skuIds): array
    {
        if ($skuIds === []) return [];
        return DB::table('wh_batch_balances')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('sku_id', $skuIds)
            ->select('sku_id')
            ->selectRaw('COALESCE(SUM(GREATEST(on_hand_qty - reserved_qty - quarantine_qty, 0)), 0) AS ready_qty')
            ->groupBy('sku_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->sku_id => round((float) $row->ready_qty, 4)])
            ->all();
    }

    private function warehouse(string $id): object
    {
        $row = DB::table('outlets')->where('id', $id)->where('is_active', true)->whereRaw("LOWER(COALESCE(type,''))='warehouse'")->first(['id', 'code', 'name']);
        if (! $row) throw ValidationException::withMessages(['warehouse_id' => ['Warehouse aktif tidak ditemukan.']]);
        return $row;
    }

    private function selectSheet(array $worksheets): array
    {
        foreach ($worksheets as $sheet) {
            if (mb_strtoupper(trim((string) ($sheet['name'] ?? ''))) === 'TRANSFER STOCK') return $sheet;
        }
        foreach ($worksheets as $sheet) {
            $rows = (array) ($sheet['rows'] ?? []);
            if ($rows && isset($this->headerMap((array) $rows[0])['sku_code'])) return $sheet;
        }
        throw ValidationException::withMessages(['file' => ['Worksheet TRANSFER STOCK tidak ditemukan.']]);
    }

    private function headerMap(array $header): array
    {
        $aliases = [
            'origin_warehouse_code' => ['origin_warehouse_code','origin warehouse code','warehouse origin','origin'],
            'destination_warehouse_code' => ['destination_warehouse_code','destination warehouse code','warehouse tujuan','destination'],
            'transfer_date' => ['transfer_date','transfer date','tanggal transfer'],
            'needed_date' => ['needed_date','needed date','tanggal kebutuhan'],
            'sku_code' => ['sku_code','sku code','kode sku','sku'],
            'uom_code' => ['uom_code','uom code','uom','satuan'],
            'qty' => ['qty','quantity','jumlah'],
            'notes' => ['notes','note','catatan'],
        ];
        $map = [];
        foreach ($header as $index => $raw) {
            $value = mb_strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['-','_'], ' ', (string) $raw))));
            foreach ($aliases as $key => $candidates) {
                foreach ($candidates as $candidate) {
                    $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['-','_'], ' ', $candidate))));
                    if ($value === $normalized) { $map[$key] = $index; break 2; }
                }
            }
        }
        return $map;
    }

    private function date(string $value, string $field): string
    {
        if ($value === '') throw new \RuntimeException("{$field} wajib diisi.");
        if (is_numeric($value) && (float) $value > 20000) {
            return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $value))->format('Y-m-d');
        }
        try { return Carbon::parse($value)->format('Y-m-d'); }
        catch (\Throwable) { throw new \RuntimeException("{$field} tidak valid. Gunakan YYYY-MM-DD."); }
    }
}
