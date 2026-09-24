<?php

namespace App\Services\Warehouse\ProductionV3\I06;

use App\Services\Warehouse\Pricing\WarehouseProductionPriceBandResolverI06;
use App\Services\Warehouse\ProductionV3\WarehouseProductionV3Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseProductionPricingI06Service
{
    public function __construct(
        private readonly WarehouseProductionV3Service $production,
        private readonly WarehouseProductionPriceBandResolverI06 $bands,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function priceBands(string $warehouseId, string $productionId): array
    {
        $exists = DB::table('wh_productions')->where('warehouse_id', $warehouseId)->where('id', $productionId)->exists();
        if (! $exists) abort(404);

        return DB::table('wh_production_outputs as output')
            ->join('stk_skus as sku', 'sku.id', '=', 'output.sku_id')
            ->where('output.production_id', $productionId)
            ->orderBy('sku.name')
            ->get(['output.id as production_output_id','output.sku_id','sku.sku_code','sku.name as item_name'])
            ->map(function (object $row) use ($warehouseId): array {
                $snapshot = $this->bands->bands($warehouseId, (string) $row->sku_id);
                return [
                    'production_output_id' => (string) $row->production_output_id,
                    'sku_id' => (string) $row->sku_id,
                    'sku_code' => (string) $row->sku_code,
                    'item_name' => (string) $row->item_name,
                    'source' => WarehouseProductionPriceBandResolverI06::SOURCE,
                    'basis' => 'PER_BASE_UOM',
                    'bands' => $snapshot['bands'],
                ];
            })->values()->all();
    }

    /** @return array<string,mixed> */
    public function saveResult(string $warehouseId, string $productionId, ?string $resultId, array $payload, string $userId): array
    {
        return DB::transaction(function () use ($warehouseId,$productionId,$resultId,$payload,$userId): array {
            $base = $this->production->saveResultDraft($warehouseId, $productionId, $resultId, $payload, $userId);
            $savedId = (string) ($base['id'] ?? $resultId ?? '');
            if ($savedId === '') throw ValidationException::withMessages(['result'=>['ID Draft Production Result tidak terbentuk.']]);

            $inputByOutput = collect($payload['items'] ?? [])->keyBy(fn (array $line): string => (string) ($line['production_output_id'] ?? ''));
            $items = DB::table('wh_v3_production_result_items')->where('result_id', $savedId)->lockForUpdate()->get();
            foreach ($items as $item) {
                $line = $inputByOutput->get((string) $item->production_output_id);
                $band = strtoupper(trim((string) ($line['selected_price_band'] ?? 'AVG')));
                $pricing = $this->bands->resolve($warehouseId, (string) $item->sku_id, $band);
                $lineValue = round((float) $item->qty_base * (float) $pricing['selected_price'], 2);
                DB::table('wh_v3_production_result_items')->where('id', $item->id)->update([
                    'price_source_snapshot' => WarehouseProductionPriceBandResolverI06::SOURCE,
                    'price_band_snapshot' => $pricing['selected_band'],
                    'price_snapshot' => $pricing['selected_price'],
                    'price_line_value_snapshot' => $lineValue,
                    'price_rule_snapshot' => json_encode($pricing),
                    'updated_at' => now(),
                ]);
            }

            $this->event($productionId, 'production_result_price_band_snapshotted', $userId, [
                'result_id' => $savedId,
                'source' => WarehouseProductionPriceBandResolverI06::SOURCE,
                'contract_version' => WarehouseProductionPriceBandResolverI06::CONTRACT_VERSION,
            ]);

            return $this->resultDetail($warehouseId, $productionId, $savedId);
        }, 5);
    }

    /** @return array<string,mixed> */
    public function approveResult(string $warehouseId, string $productionId, string $resultId, string $userId): array
    {
        return DB::transaction(function () use ($warehouseId,$productionId,$resultId,$userId): array {
            $production = DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('id',$productionId)->lockForUpdate()->first();
            if (! $production) abort(404);
            $result = DB::table('wh_v3_production_results')->where('warehouse_id',$warehouseId)->where('production_id',$productionId)->where('id',$resultId)->lockForUpdate()->first();
            if (! $result) abort(404);
            $this->assertPriceSnapshots($resultId, true);
            $this->production->approveResult($warehouseId, $productionId, $resultId, $userId);
            $this->syncSelectedOutputValues($productionId);
            $this->event($productionId, 'production_result_price_band_approved', $userId, [
                'result_id' => $resultId,
                'source' => WarehouseProductionPriceBandResolverI06::SOURCE,
            ]);
            return $this->resultDetail($warehouseId, $productionId, $resultId);
        }, 5);
    }

    /** @return array<string,mixed> */
    public function rejectResult(string $warehouseId, string $productionId, string $resultId, string $reason, string $userId): array
    {
        DB::transaction(function () use ($warehouseId,$productionId,$resultId,$reason,$userId): void {
            $production = DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('id',$productionId)->lockForUpdate()->first();
            if (! $production) abort(404);
            if ((string) $production->status !== 'on_progress') throw ValidationException::withMessages(['status'=>['Reject hasil hanya dapat dilakukan saat Production Order ongoing.']]);
            $result = DB::table('wh_v3_production_results')->where('warehouse_id',$warehouseId)->where('production_id',$productionId)->where('id',$resultId)->lockForUpdate()->first();
            if (! $result) abort(404);
            if ((string) $result->status === 'approved') throw ValidationException::withMessages(['status'=>['Approved Production Result tidak dapat di-reject.']]);
            if ((string) $result->status === 'rejected') return;
            if ((string) $result->status !== 'draft') throw ValidationException::withMessages(['status'=>['Hanya Draft Production Result yang dapat di-reject.']]);
            $this->assertUnposted($resultId, $result);
            DB::table('wh_v3_production_result_items')->where('result_id',$resultId)->update(['status'=>'rejected','updated_at'=>now()]);
            DB::table('wh_v3_production_results')->where('id',$resultId)->update([
                'status'=>'rejected','rejection_reason'=>trim($reason),'rejected_by_user_id'=>$userId,'rejected_at'=>now(),
                'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ]);
            $this->event($productionId,'production_result_rejected',$userId,['result_id'=>$resultId,'reason'=>trim($reason)]);
        },5);
        return $this->resultDetail($warehouseId,$productionId,$resultId);
    }

    /** @return array<string,mixed> */
    public function deleteResult(string $warehouseId, string $productionId, string $resultId, string $userId): array
    {
        return DB::transaction(function () use ($warehouseId,$productionId,$resultId,$userId): array {
            $production = DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('id',$productionId)->lockForUpdate()->first();
            if (! $production) abort(404);
            if ((string) $production->status !== 'on_progress') throw ValidationException::withMessages(['status'=>['Production Result hanya dapat dihapus saat Production Order ongoing.']]);
            $result = DB::table('wh_v3_production_results')->where('warehouse_id',$warehouseId)->where('production_id',$productionId)->where('id',$resultId)->lockForUpdate()->first();
            if (! $result) abort(404);
            if (! in_array((string) $result->status,['draft','rejected'],true)) throw ValidationException::withMessages(['status'=>['Hanya Draft/Rejected Production Result yang dapat dihapus. Approved Result immutable.']]);
            $this->assertUnposted($resultId,$result);
            $this->event($productionId,'production_result_deleted',$userId,[
                'result_id'=>$resultId,'result_number'=>(string)$result->result_number,'status'=>(string)$result->status,
                'pricing_source'=>WarehouseProductionPriceBandResolverI06::SOURCE,
            ]);
            DB::table('wh_v3_production_results')->where('id',$resultId)->delete();
            return ['id'=>$resultId,'result_number'=>(string)$result->result_number,'status'=>(string)$result->status,'deleted'=>true];
        },5);
    }

    /** @return array<string,mixed> */
    public function resultDetail(string $warehouseId, string $productionId, string $resultId): array
    {
        $base = $this->production->resultDetail($warehouseId,$productionId,$resultId);
        $snapshots = DB::table('wh_v3_production_result_items')->where('result_id',$resultId)->get([
            'id','price_source_snapshot','price_band_snapshot','price_snapshot','price_line_value_snapshot','price_rule_snapshot'
        ])->keyBy(fn (object $row): string => (string) $row->id);
        $base['items'] = collect($base['items'] ?? [])->map(function (array $item) use ($snapshots): array {
            $p = $snapshots->get((string) $item['id']);
            if (! $p) return $item;
            return $item + [
                'price_source_snapshot' => $p->price_source_snapshot,
                'price_band_snapshot' => $p->price_band_snapshot,
                'price_snapshot' => $p->price_snapshot !== null ? (float) $p->price_snapshot : null,
                'price_line_value_snapshot' => $p->price_line_value_snapshot !== null ? (float) $p->price_line_value_snapshot : null,
                'price_rule_snapshot' => $this->json($p->price_rule_snapshot),
            ];
        })->values()->all();
        $base['pricing_contract'] = [
            'source'=>WarehouseProductionPriceBandResolverI06::SOURCE,
            'version'=>WarehouseProductionPriceBandResolverI06::CONTRACT_VERSION,
            'basis'=>'PER_BASE_UOM',
        ];
        return $base;
    }

    private function assertPriceSnapshots(string $resultId, bool $lock = false): void
    {
        $query = DB::table('wh_v3_production_result_items')->where('result_id',$resultId)->where('qty_base','>',0);
        if ($lock) $query->lockForUpdate();
        $items = $query->get();
        if ($items->isEmpty()) throw ValidationException::withMessages(['items'=>['Tidak ada Production Result positif.']]);
        foreach ($items as $item) {
            if ((string) ($item->price_source_snapshot ?? '') !== WarehouseProductionPriceBandResolverI06::SOURCE
                || ! in_array((string) ($item->price_band_snapshot ?? ''), ['MIN','AVG','MAX'], true)
                || (float) ($item->price_snapshot ?? 0) <= 0
                || (float) ($item->price_line_value_snapshot ?? 0) <= 0) {
                throw ValidationException::withMessages([
                    'selected_price_band' => ['Production Result belum memiliki snapshot Price Band lengkap. Edit dan simpan ulang Draft sebelum Approve.'],
                ]);
            }
        }
    }

    private function syncSelectedOutputValues(string $productionId): void
    {
        $outputs = DB::table('wh_production_outputs')->where('production_id',$productionId)->lockForUpdate()->get(['id']);
        foreach ($outputs as $output) {
            $rows = DB::table('wh_v3_production_result_items as item')
                ->join('wh_v3_production_results as result','result.id','=','item.result_id')
                ->where('result.production_id',$productionId)->where('result.status','approved')
                ->where('item.production_output_id',$output->id)->where('item.qty_base','>',0)
                ->get(['item.qty_base','item.price_band_snapshot','item.price_snapshot','item.price_line_value_snapshot']);
            if ($rows->isEmpty()) continue;
            $qty = (float) $rows->sum('qty_base');
            $lineValue = round((float) $rows->sum('price_line_value_snapshot'),2);
            $uniqueBands = $rows->pluck('price_band_snapshot')->filter()->unique()->values();
            $band = $uniqueBands->count() === 1 ? (string) $uniqueBands->first() : 'MIXED';
            $weighted = $qty > 0 ? round($lineValue / $qty, 6) : 0;
            DB::table('wh_production_outputs')->where('id',$output->id)->update([
                'selected_price_band'=>$band,'selected_price_snapshot'=>$weighted,'selected_line_value'=>$lineValue,'updated_at'=>now(),
            ]);
        }
        $selectedOutputValue = round((float) DB::table('wh_v3_production_result_items as item')
            ->join('wh_v3_production_results as result','result.id','=','item.result_id')
            ->where('result.production_id',$productionId)->where('result.status','approved')
            ->sum('item.price_line_value_snapshot'),2);
        DB::table('wh_productions')->where('id',$productionId)->update(['selected_output_value'=>$selectedOutputValue,'updated_at'=>now()]);
    }

    private function assertUnposted(string $resultId, object $result): void
    {
        if (($result->ledger_posting_id ?? null) || ($result->idempotency_key ?? null) || ($result->approved_at ?? null) || ($result->approved_by_user_id ?? null)) {
            throw ValidationException::withMessages(['result'=>['Production Result sudah memiliki jejak approval/ledger dan tidak dapat diubah/dihapus.']]);
        }
        if (DB::table('wh_v3_production_result_items')->where('result_id',$resultId)->where(function ($q): void {
            $q->whereNotNull('batch_id')->orWhere('status','approved')->orWhere('unit_cost_snapshot','>',0)->orWhere('total_cost_snapshot','>',0);
        })->exists()) {
            throw ValidationException::withMessages(['result'=>['Production Result sudah memiliki batch/cost/approval evidence dan tidak dapat diubah/dihapus.']]);
        }
        if (DB::table('wh_ledger_postings')->where('reference_type','wh_v3_production_result')->where('reference_id',$resultId)->exists()) {
            throw ValidationException::withMessages(['result'=>['Production Result sudah mempunyai Warehouse Ledger posting.']]);
        }
    }

    private function event(string $productionId, string $type, ?string $userId, array $payload = []): void
    {
        DB::table('wh_production_events')->insert([
            'id'=>(string)Str::ulid(),'production_id'=>$productionId,'event_type'=>$type,'idempotency_key'=>null,
            'payload'=>json_encode($payload),'actor_user_id'=>$userId,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array) $value;
        $decoded = json_decode((string)$value,true);
        return is_array($decoded) ? $decoded : [];
    }
}
