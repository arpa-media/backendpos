<?php

namespace App\Console\Commands;

use App\Services\Warehouse\FinanceV8\WarehouseFinanceCanonicalBridgeV8Service;
use Illuminate\Console\Command;

final class ErpRevWarehouseIteration08FinanceSyncCommand extends Command
{
    protected $signature = 'erp-rev:warehouse-iteration-08-finance-sync {--posting= : Sync only one Warehouse General Posting ID}';
    protected $description = 'Ensure Warehouse→Finance canonical CoA mapping and idempotently project Warehouse General Postings to global Finance GL.';

    public function handle(WarehouseFinanceCanonicalBridgeV8Service $bridge): int
    {
        $mapping=$bridge->ensureMappings();
        $this->info('Canonical CoA mapping: '.json_encode($mapping,JSON_UNESCAPED_SLASHES));

        $posting=trim((string)$this->option('posting'));
        $result=$posting!=='' ? $bridge->syncPosting($posting,null,false) : $bridge->syncAll(null);
        $this->table(['Metric','Value'],collect($result)->map(fn($v,$k)=>[(string)$k,is_scalar($v)?(string)$v:json_encode($v)])->values()->all());

        $status=$bridge->status();
        $unmapped=collect($status['warehouses'] ?? [])->where('finance_scope_ready',false)->values();
        if ($unmapped->isNotEmpty()) {
            $this->warn('Warehouse berikut belum dipetakan ke PT Finance; GL global projection ditahan sebagai PENDING_SCOPE:');
            foreach($unmapped as $w) $this->line(' - '.$w['code'].' · '.$w['name']);
            $this->line('Isi Finance → Chart of Account → Mapping PT Outlet. Jangan menebak PT Warehouse.');
        }
        return ((int)($result['failed'] ?? 0))>0 || (($result['status'] ?? '')==='FAILED') ? self::FAILURE : self::SUCCESS;
    }
}
