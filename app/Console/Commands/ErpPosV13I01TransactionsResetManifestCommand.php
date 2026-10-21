<?php

namespace App\Console\Commands;

use App\Services\ErpReset\V13I01ManifestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/** CLI only; no route, permission, migration, DELETE, DDL or queue dispatch. */
final class ErpPosV13I01TransactionsResetManifestCommand extends Command
{
    protected $signature = 'erp:transactions-reset
        {--scope=all : all atau stock,warehouse,purchasing,finance,cogs}
        {--full : Semua tanggal / seluruh transaksi pada scope}
        {--month= : Periode tanggal bisnis YYYY-MM}
        {--from= : Awal tanggal bisnis YYYY-MM-DD}
        {--to= : Akhir tanggal bisnis YYYY-MM-DD (inklusif)}
        {--dry-run : Preview read-only (selalu aktif pada I01)}
        {--scan=estimate : estimate (cepat, approximate) atau exact (COUNT seluruh tabel, lebih berat)}
        {--details : Rekap outlet, bulan, dan nominal pada beberapa tabel prioritas (query agregasi, bisa lambat)}
        {--max-details=12 : Jumlah tabel untuk agregasi detail, 1..100}
        {--output= : Nama file JSON relatif storage/app/erp-reset/previews (hanya a-z0-9_- dan .json)}
        {--no-save : Tidak menulis file JSON; hanya menampilkan terminal}
        {--execute : DITOLAK di I01, hanya tersedia mulai I02 dengan guard lengkap}
        {--plan= : DITOLAK di I01, hash hanya identifikasi rencana}
        {--confirm= : DITOLAK di I01}';

    protected $description = 'V13 I01: manifest/dry-run aman untuk reset transaksi ERP, TANPA penghapusan data.';

    public function handle(V13I01ManifestService $service): int
    {
        if ($this->option('execute') || $this->option('plan') !== null || $this->option('confirm') !== null) {
            $this->error('V13 I01 READ-ONLY: --execute, --plan, dan --confirm tidak tersedia. Tidak ada data yang diubah.');
            return self::FAILURE;
        }
        try {
            if ($this->option('scan') === 'exact' || $this->option('details')) {
                $this->warn('Mode detail/exact melakukan SELECT agregasi. Pada database besar bisa memerlukan waktu lama; tidak melakukan perubahan data.');
            }
            $report = $service->build([
                'scope' => $this->option('scope'), 'full' => $this->option('full'),
                'month' => $this->option('month'), 'from' => $this->option('from'), 'to' => $this->option('to'),
                'scan' => $this->option('scan'), 'details' => $this->option('details'),
                'max_details' => $this->option('max-details'),
                'progress' => function (string $stage, int $index, int $total, string $table): void {
                    if ($index === 1 || $index % 10 === 0 || $index === $total) {
                        $this->line('['.$stage.'] '.$index.'/'.$total.' — '.$table);
                    }
                },
            ]);
        } catch (Throwable $e) {
            $this->error('Dry-run gagal: '.$e->getMessage());
            $this->warn('Semua operasi pada I01 bersifat read-only.');
            return self::FAILURE;
        }

        $s = $report['summary'];
        $this->info('ERP POS '.$report['release'].' — READ-ONLY RESET MANIFEST');
        $this->table(['Parameter', 'Nilai'], [
            ['Database / Environment', $report['database'].' / '.$report['environment']],
            ['Scope', implode(', ', $report['scope'])],
            ['Mode', $report['period']['mode']],
            ['Business Date', ($report['period']['from'] ?? 'ALL').' — '.($report['period']['to'] ?? 'ALL')],
            ['Baseline / Live tables', $s['dump_tables'].' / '.$s['live_tables']],
            ['Selected transaction tables', $s['selected_tables']],
            ['Estimated total rows (NOT period)', number_format($s['estimated_selected_rows'])],
            ['Scan', $report['scan'].($report['scan'] === 'estimate' ? ' (approximate, tidak menghitung periode)' : ' (COUNT(*) aktual)')],
            ['Blockers / Warnings', $s['blockers'].' / '.$s['warnings']],
            ['New / Missing tables', count($report['drift']['new_tables']).' / '.count($report['drift']['missing_tables'])],
            ['Plan', $report['plan_id']],
            ['Status', $report['result'].' — EXECUTOR DISABLED'],
        ]);
        if ($report['scan'] === 'exact') {
            $this->info('Jumlah baris aktual di seluruh tabel target: '.number_format($s['exact_total_rows']));
            $this->info('Jumlah baris periode pada tabel yang dapat dipetakan: '.number_format($s['exact_period_rows_scannable']).' (bukan total lengkap jika ada blocker tanggal).');
        }
        if ($this->option('details')) {
            $detailRows = [];
            foreach ($report['tables'] as $table => $item) {
                if (empty($item['detail']['rows'])) continue;
                foreach (array_slice($item['detail']['rows'], 0, 3) as $r) {
                    $values = [];
                    foreach ($item['detail']['measure_columns'] as $measure) {
                        $values[] = $measure.'='.($r['sum_'.$measure] ?? '?');
                    }
                    $detailRows[] = [$table, $r['outlet_or_warehouse_id'] ?? '-', $r['month_key'] ?? '-', $r['rows_count'] ?? 0, implode(', ', $values)];
                }
            }
            if ($detailRows) $this->table(['Tabel', 'Outlet/Warehouse ID', 'Bulan', 'Records', 'Nominal/Qty (SUM)'], $detailRows);
            $this->warn('Detail terminal maks. 3 grup per tabel; semua grup sampai batas 100 tersedia pada JSON.');
        }
        $this->table(['Scope', 'Tabel transaksi'], array_map(static fn ($domain, $count) => [$domain, $count],
            array_keys($s['selected_by_domain']), array_values($s['selected_by_domain'])));

        foreach (array_slice($report['blockers'], 0, 15) as $blocker) {
            $this->warn('[BLOCK] '.$blocker['code'].' / '.($blocker['table'] ?? '-').' — '.$blocker['message']);
        }
        if ($s['blockers'] > 15) $this->warn('... '.($s['blockers'] - 15).' blocker lain ada di JSON.');
        foreach (array_slice($report['warnings'], 0, 5) as $warning) {
            $this->line('[INFO] '.$warning['code'].' / '.($warning['table'] ?? '-'));
        }
        $this->warn('Tidak ada DELETE, UPDATE, TRUNCATE, ALTER, lock, atau posting ulang.');
        if ($report['scan'] === 'estimate') {
            $this->warn('TABLE_ROWS dari InnoDB adalah estimasi seluruh tabel, BUKAN jumlah record pada bulan yang dipilih. Gunakan --scan=exact saat maintenance window jika perlu.');
        }

        if (! $this->option('no-save')) {
            try {
                $filename = trim((string) $this->option('output'));
                if ($filename === '') {
                    $filename = 'v13_i01_'.date('Ymd_His').'_'.strtolower(substr($report['plan_sha256'], 0, 12)).'.json';
                }
                if (! preg_match('/^[A-Za-z0-9_-]+\.json$/D', $filename)) {
                    throw new \InvalidArgumentException('--output hanya nama file JSON sederhana; tanpa path.');
                }
                $dir = storage_path('app/erp-reset/previews');
                File::ensureDirectoryExists($dir);
                $destination = $dir.DIRECTORY_SEPARATOR.$filename;
                if (File::exists($destination)) throw new \RuntimeException('File sudah ada; tidak ditimpa: '.$filename);
                File::put($destination, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $this->info('Laporan JSON: '.$destination);
            } catch (Throwable $e) {
                $this->error('Gagal menyimpan laporan: '.$e->getMessage());
                return self::FAILURE;
            }
        }
        // A blocked plan is a successful diagnosis, not a failed read-only command.
        // Exit code 2 signals it cannot proceed to an executor.
        return $s['blockers'] > 0 ? self::INVALID : self::SUCCESS;
    }
}
