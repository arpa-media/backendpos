<?php

namespace App\Services\ErpReset;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * V13 I02 isolated-clone reset executor.
 * - No DDL/TRUNCATE/SET FOREIGN_KEY_CHECKS.
 * - Never used on production, or for historical/monthly/single domain scopes.
 * - Filesystem signed plan, resumable bounded DELETE chunks, fail closed.
 * - No scheduler/source repost suppression exists yet: production execution MUST wait I03.
 */
final class V13I02SafeResetService
{
    private const SCOPES = ['stock', 'warehouse', 'purchasing', 'finance', 'cogs'];
    private const CONFIRM = 'RESET ERP TRANSACTIONS';

    public function __construct(
        private readonly V13I02ManifestService $manifest,
        private readonly V13I02DependencyPlanner $planner
    ) {}

    public function prepare(array $options): array
    {
        $report = $this->manifest->build([
            'scope' => $options['scope'] ?? 'all', 'full' => $options['full'] ?? false,
            'month' => $options['month'] ?? null, 'from' => $options['from'] ?? null,
            'to' => $options['to'] ?? null, 'scan' => 'estimate',
        ]);
        $selected = [];
        foreach ($report['tables'] as $name => $row) {
            if ($row['included']) $selected[$name] = true;
        }
        $blockers = [];
        foreach ($report['blockers'] as $block) {
            // Non-null incoming references are checked against actual data separately below.
            if ($block['code'] !== 'INBOUND_CROSS_SCOPE_FK') {
                $blockers[] = $block['code'].' / '.($block['table'] ?? '?');
            }
        }
        $complete = $report['period']['mode'] === 'full' && $report['scope'] === self::SCOPES;
        if (!$complete) {
            $blockers[] = 'UNSUPPORTED_PARTIAL_RESET: I02 hanya eksekusi FULL+ALL pada DB clone; periode & multi-scope disiapkan di I03 setelah replay/zero proof.';
        }
        if (count($selected) !== 202) {
            $blockers[] = 'BASELINE_SCOPE_COUNT_DRIFT: expected 202, got '.count($selected);
        }
        $metadata = $this->readMetadata($report['database']);
        foreach (array_keys($selected) as $table) {
            if (($metadata['pk'][$table] ?? []) !== ['id']) {
                $blockers[] = 'PRIMARY_KEY_ID_REQUIRED: '.$table;
            }
            if (in_array($table, $metadata['delete_triggers'], true)) {
                $blockers[] = 'DELETE_TRIGGER_UNREVIEWED: '.$table;
            }
        }
        foreach ($metadata['fks'] as $fk) {
            $c = $fk['child']; $p = $fk['parent'];
            if (!isset($selected[$p]) || isset($selected[$c])) continue;
            if (!isset($report['tables'][$c])) {
                $blockers[] = 'UNKNOWN_EXTERNAL_CHILD_TABLE: '.$c.' -> '.$p;
                continue;
            }
            if (count($fk['columns']) !== 1) {
                $blockers[] = 'EXTERNAL_COMPOSITE_FK: '.$c.' -> '.$p;
                continue;
            }
            // If the external HR/misc rows all have NULL link, the FK does not block
            // deletion. Never modify a protected/excluded/outside-scope table.
            $column = $fk['columns'][0];
            if (DB::table($c)->whereNotNull($column)->exists()) {
                $blockers[] = 'EXTERNAL_REFERENCE_PRESENT: '.$c.'.'.$column.' -> '.$p.'; outside-scope records remain protected.';
            }
        }
        $graph = $this->planner->plan($selected, $metadata['fks'], $metadata['nullable']);
        foreach ($graph['detaches'] as $detach) {
            if (in_array($detach['table'], $metadata['update_triggers'], true)) {
                $blockers[] = 'UPDATE_TRIGGER_ON_FK_DETACH: '.$detach['table'];
            }
        }
        array_push($blockers, ...$graph['blockers']);
        if (count($graph['order']) !== count($selected)) {
            $blockers[] = 'INCOMPLETE_FK_ORDER';
        }
        $structuralHash = hash('sha256', $this->encode([
            'schema' => $report['schema_sha256'],
            'fks' => $metadata['fks'], 'pk' => $metadata['pk'],
            'nullable' => $metadata['nullable'], 'delete_triggers' => $metadata['delete_triggers'],
            'update_triggers' => $metadata['update_triggers'],
        ]));
        $backup = $this->backupEvidence($options['backup_file'] ?? null, $options['backup_sha256'] ?? null);
        if (!($backup['verified'] ?? false)) {
            $blockers[] = 'VERIFIED_BACKUP_REQUIRED: '.($backup['error'] ?? 'provide --backup-file and matching --backup-sha256');
        }
        $blockers = array_values(array_unique($blockers));
        $base = [
            'release' => 'V13-I02', 'created_at' => now()->toIso8601String(),
            'purpose' => 'ISOLATED_CLONE_FULL_RESET_ONLY',
            'database' => $report['database'], 'environment' => app()->environment(),
            'scope' => $report['scope'], 'period' => $report['period'],
            'manifest_schema_sha256' => $report['schema_sha256'],
            'structure_sha256' => $structuralHash,
            'selected_tables' => array_keys($selected),
            'delete_order' => $graph['order'], 'detach_links' => $graph['detaches'],
            'backup' => $backup, 'blockers' => $blockers,
            'warnings' => [
                'BACKUP_HASH_ONLY: sha256 match does NOT prove ability to restore. Require actual restore rehearsal.',
                'AUTO_REPOST_GUARD_NOT_INSTALLED: do not use against a live POS. Worker, scheduler and API writes must be off.',
                'I03 RECONCILIATION MISSING: zero ledger reconciliation in production not yet supported.',
            ],
            'i01_plan_sha256' => $report['plan_sha256'],
        ];
        $id = 'V13I02-'.strtoupper(substr(hash('sha256', $this->encode($base)), 0, 20));
        $base['plan_id'] = $id;
        $payload = $this->signed($base);
        if (!$blockers) {
            $this->writeImmutable($this->path('plans', $id), $payload);
        }
        return [
            'ready' => !$blockers, 'plan_id' => $id, 'database' => $report['database'],
            'environment' => app()->environment(), 'scope' => $report['scope'],
            'period' => $report['period'], 'tables' => count($selected),
            'delete_order' => count($graph['order']), 'detach_links' => $graph['detaches'],
            'blockers' => $blockers, 'backup' => $backup,
            'file' => !$blockers ? $this->path('plans', $id) : null,
        ];
    }

    public function execute(string $planId, string $confirm, int $chunk, array $ack, int $maxChunks = 0, bool $resume = false, ?callable $progress = null): array
    {
        $this->assertSandbox();
        if ($confirm !== self::CONFIRM) throw new RuntimeException('Confirmation phrase tidak cocok.');
        foreach (['restore_tested', 'writers_stopped', 'autopost_stopped'] as $key) {
            if (($ack[$key] ?? false) !== true) throw new RuntimeException('Required acknowledgment missing: '.$key);
        }
        if ($chunk < 50 || $chunk > 1000) throw new RuntimeException('--chunk harus 50..1000.');
        if ($maxChunks < 0 || $maxChunks > 100000) throw new RuntimeException('--max-chunks tidak valid.');
        $this->assertPlanId($planId);
        $plan = $this->loadSigned($this->path('plans', $planId));
        if ($plan['plan_id'] !== $planId || $plan['blockers'] || $plan['scope'] !== self::SCOPES || $plan['period']['mode'] !== 'full') {
            throw new RuntimeException('Plan mismatch/incomplete.');
        }
        if ($plan['database'] !== DB::connection()->getDatabaseName() || $plan['environment'] !== app()->environment()) {
            throw new RuntimeException('DB/environment changed since plan was signed.');
        }
        $this->verifyBackup($plan['backup']);
        $lockName = 'v13_i02_'.substr(hash('sha256', $plan['database']), 0, 40);
        if ((int) (DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->acquired ?? 0) !== 1) {
            throw new RuntimeException('A reset run has already acquired the database lock.');
        }
        try {
            $this->assertSandbox();
            $this->assertUnchangedSchema($plan);
            $stateFile = $this->path('runs', $planId);
            if ($resume) {
                $state = $this->loadSigned($stateFile);
                if (($state['plan_id'] ?? null) !== $planId || !in_array($state['phase'], ['snapshot','detach','delete','paused','failed'], true)) {
                    throw new RuntimeException('Tidak ada run aktif yang bisa di-resume.');
                }
                if (in_array($state['phase'], ['paused','failed'], true)) $state['phase'] = $state['resume_phase'];
            } else {
                if (File::exists($stateFile)) throw new RuntimeException('Run already exists; use --resume='.$planId.'; never begin duplicate run.');
                $state = [
                    'plan_id' => $planId, 'phase' => 'snapshot', 'resume_phase' => null,
                    'snapshot_index' => 0, 'before_counts' => [], 'detach_index' => 0, 'table_index' => 0, 'rows_deleted' => 0,
                    'links_detached' => 0, 'by_table_deleted' => [],
                    'started_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
                ];
                $this->saveSigned($stateFile, $state);
            }
            $operations = 0;
            try {
                while ($state['phase'] === 'snapshot' && $state['snapshot_index'] < count($plan['selected_tables'])) {
                    $table = $plan['selected_tables'][$state['snapshot_index']];
                    $state['before_counts'][$table] = (int) DB::table($table)->count();
                    $state['snapshot_index']++;
                    $this->saveSigned($stateFile, $state);
                    if ($progress && $state['snapshot_index'] % 20 === 0) $progress($state);
                }
                if ($state['phase'] === 'snapshot') {
                    $state['phase'] = 'detach';
                    $this->saveSigned($stateFile, $state);
                }
                while ($state['phase'] === 'detach' && $state['detach_index'] < count($plan['detach_links'])) {
                    $spec = $plan['detach_links'][$state['detach_index']];
                    $ids = DB::table($spec['table'])->whereNotNull($spec['column'])->orderBy('id')->limit($chunk)->pluck('id')->all();
                    if (!$ids) {
                        $state['detach_index']++;
                        $this->saveSigned($stateFile, $state);
                        continue;
                    }
                    $affected = DB::transaction(static fn() => DB::table($spec['table'])->whereIn('id', $ids)->whereNotNull($spec['column'])->update([$spec['column'] => null]), 1);
                    $state['links_detached'] += $affected;
                    $this->saveSigned($stateFile, $state);
                    $operations++;
                    if ($progress && ($operations === 1 || $operations % 20 === 0)) $progress($state);
                    if ($maxChunks > 0 && $operations >= $maxChunks) return $this->pause($stateFile, $state);
                }
                if ($state['phase'] === 'detach') {
                    $state['phase'] = 'delete';
                    $this->saveSigned($stateFile, $state);
                }
                while ($state['phase'] === 'delete' && $state['table_index'] < count($plan['delete_order'])) {
                    $table = $plan['delete_order'][$state['table_index']];
                    $ids = DB::table($table)->orderBy('id')->limit($chunk)->pluck('id')->all();
                    if (!$ids) {
                        $state['table_index']++;
                        $this->saveSigned($stateFile, $state);
                        continue;
                    }
                    // No FK_DISABLE; child-first ordering. Transaction per small chunk only.
                    $deleted = DB::transaction(static fn() => DB::table($table)->whereIn('id', $ids)->delete(), 1);
                    $state['by_table_deleted'][$table] = ($state['by_table_deleted'][$table] ?? 0) + $deleted;
                    $state['rows_deleted'] += $deleted;
                    $this->saveSigned($stateFile, $state);
                    $operations++;
                    if ($progress && ($operations === 1 || $operations % 20 === 0)) $progress($state);
                    if ($maxChunks > 0 && $operations >= $maxChunks) return $this->pause($stateFile, $state);
                }
                // A quick exact remaining count. An additional I03 full ledger proof is still required.
                $remaining = []; $after = [];
                foreach ($plan['selected_tables'] as $table) {
                    $count = (int) DB::table($table)->count();
                    $after[$table] = $count;
                    if ($count !== 0) $remaining[$table] = $count;
                }
                $state['after_counts'] = $after;
                $state['remaining_rows'] = $remaining;
                $state['phase'] = !$remaining ? 'complete_awaiting_i03' : 'failed';
                $state['resume_phase'] = $remaining ? 'delete' : null;
                $state['finished_at'] = now()->toIso8601String();
                $this->saveSigned($stateFile, $state);
                return $state;
            } catch (Throwable $e) {
                $state['resume_phase'] = $state['phase'];
                $state['phase'] = 'failed';
                $state['last_error'] = $e->getMessage();
                $this->saveSigned($stateFile, $state);
                throw $e;
            }
        } finally {
            DB::select('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    public function status(string $planId): array
    {
        $this->assertPlanId($planId);
        return $this->loadSigned($this->path('runs', $planId));
    }

    private function pause(string $stateFile, array $state): array
    {
        $state['resume_phase'] = $state['phase'];
        $state['phase'] = 'paused';
        $this->saveSigned($stateFile, $state);
        return $state;
    }

    private function assertUnchangedSchema(array $plan): void
    {
        $report = $this->manifest->build(['scope'=>'all', 'full'=>true, 'scan'=>'estimate']);
        if ($report['schema_sha256'] !== $plan['manifest_schema_sha256']) {
            throw new RuntimeException('Schema drift since plan creation. STOP and prepare a new plan.');
        }
        $meta = $this->readMetadata($report['database']);
        $current = hash('sha256', $this->encode([
            'schema'=>$report['schema_sha256'], 'fks'=>$meta['fks'],
            'pk'=>$meta['pk'], 'nullable'=>$meta['nullable'], 'delete_triggers'=>$meta['delete_triggers'],
            'update_triggers'=>$meta['update_triggers'],
        ]));
        if (!hash_equals($plan['structure_sha256'], $current)) {
            throw new RuntimeException('Structural FK/PK/nullability/trigger drift. STOP.');
        }
        if ($report['scope'] !== $plan['scope'] || $report['period'] !== $plan['period']) {
            throw new RuntimeException('Manifest scope mismatch.');
        }
        // On resume, protected tables still cannot point to scheduled-for-deletion tables.
        $selected = array_fill_keys($plan['selected_tables'], true);
        foreach ($meta['fks'] as $fk) {
            if (!isset($selected[$fk['parent']]) || isset($selected[$fk['child']])) continue;
            if (count($fk['columns']) !== 1 || DB::table($fk['child'])->whereNotNull($fk['columns'][0])->exists()) {
                throw new RuntimeException('Incoming external FK has active references: '.$fk['child'].' -> '.$fk['parent']);
            }
        }
    }

    private function readMetadata(string $schema): array
    {
        $fks = []; $nullable = []; $pk = []; $triggers = []; $updateTriggers = [];
        $rows = DB::select('SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, IS_NULLABLE AS nullable
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?', [$schema]);
        foreach ($rows as $r) $nullable[$r->tbl][$r->col] = strtoupper($r->nullable) === 'YES';
        $rows = DB::select("SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, ORDINAL_POSITION AS ord
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY TABLE_NAME, ORDINAL_POSITION", [$schema]);
        foreach ($rows as $r) $pk[$r->tbl][] = $r->col;
        $rows = DB::select('SELECT TABLE_NAME AS child_table, REFERENCED_TABLE_NAME AS parent_table, COLUMN_NAME AS child_column,
                REFERENCED_COLUMN_NAME AS parent_column, CONSTRAINT_NAME AS constraint_name, ORDINAL_POSITION AS col_order
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION', [$schema]);
        foreach ($rows as $r) {
            $key = $r->child_table.'|'.$r->constraint_name;
            if (!isset($fks[$key])) $fks[$key] = [
                'child'=>$r->child_table, 'parent'=>$r->parent_table, 'constraint'=>$r->constraint_name,
                'columns'=>[], 'parent_columns'=>[],
            ];
            $fks[$key]['columns'][] = $r->child_column;
            $fks[$key]['parent_columns'][] = $r->parent_column;
        }
        ksort($fks, SORT_STRING); ksort($nullable, SORT_STRING); ksort($pk, SORT_STRING);
        $rows = DB::select("SELECT EVENT_OBJECT_TABLE AS tbl, EVENT_MANIPULATION AS action FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = ? AND EVENT_MANIPULATION IN ('DELETE','UPDATE')", [$schema]);
        foreach ($rows as $r) {
            if ($r->action === 'DELETE') $triggers[] = $r->tbl;
            if ($r->action === 'UPDATE') $updateTriggers[] = $r->tbl;
        }
        sort($triggers, SORT_STRING); sort($updateTriggers, SORT_STRING);
        return ['fks'=>array_values($fks), 'nullable'=>$nullable, 'pk'=>$pk,
            'delete_triggers'=>$triggers, 'update_triggers'=>$updateTriggers];
    }

    private function backupEvidence(?string $file, ?string $sha): array
    {
        $file = trim((string) $file); $sha = strtolower(trim((string) $sha));
        if ($file === '' || !preg_match('/^[a-f0-9]{64}$/', $sha)) return ['verified'=>false, 'error'=>'backup file and 64-digit SHA256 are required'];
        $real = realpath($file);
        if ($real === false || !is_file($real) || !is_readable($real)) return ['verified'=>false, 'error'=>'backup file not accessible'];
        if (filesize($real) < 1000) return ['verified'=>false, 'error'=>'backup file too small'];
        if (str_starts_with($real, base_path().DIRECTORY_SEPARATOR)) return ['verified'=>false, 'error'=>'backup must be outside project root'];
        $calculated = hash_file('sha256', $real);
        if ($calculated === false || !hash_equals($sha, strtolower($calculated))) return ['verified'=>false, 'error'=>'backup sha256 mismatch'];
        return ['verified'=>true, 'path'=>$real, 'sha256'=>$sha, 'bytes'=>filesize($real), 'mtime'=>filemtime($real)];
    }

    private function verifyBackup(array $backup): void
    {
        if (!($backup['verified'] ?? false) || !is_file($backup['path']) ||
            filesize($backup['path']) !== $backup['bytes'] || filemtime($backup['path']) !== $backup['mtime'] ||
            !hash_equals($backup['sha256'], (string) hash_file('sha256', $backup['path']))) {
            throw new RuntimeException('Verified backup changed/missing. Stop.');
        }
    }

    private function assertSandbox(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('Only MySQL/MariaDB are supported.');
        }
        $env = strtolower(app()->environment());
        $db = strtolower(DB::connection()->getDatabaseName());
        if (!in_array($env, ['local','testing'], true) || !preg_match('/(^|_)(clone|test|testing|sandbox|resetlab)(_|$)/', $db)) {
            throw new RuntimeException('PRODUCTION_GUARD: execution only on isolated DB named *_clone / *_test / *_sandbox / *_resetlab with APP_ENV=local|testing.');
        }
    }

    private function signed(array $data): array
    {
        if (empty(config('app.key'))) throw new RuntimeException('APP_KEY missing; cannot sign plan.');
        unset($data['hmac']);
        $data['hmac'] = hash_hmac('sha256', $this->encode($data), (string) config('app.key'));
        return $data;
    }

    private function loadSigned(string $file): array
    {
        if (!File::exists($file)) throw new RuntimeException('Plan/run file not found: '.basename($file));
        $json = json_decode((string) File::get($file), true, 512, JSON_THROW_ON_ERROR);
        $sig = $json['hmac'] ?? '';
        $actual = $this->signed($json);
        if (!is_string($sig) || !hash_equals($sig, $actual['hmac'])) throw new RuntimeException('Plan/run HMAC mismatch.');
        unset($json['hmac']);
        return $json;
    }

    private function writeImmutable(string $path, array $json): void
    {
        File::ensureDirectoryExists(dirname($path), 0700, true);
        if (File::exists($path)) throw new RuntimeException('Existing plan cannot be overwritten: '.basename($path));
        $this->atomicWrite($path, $json);
    }
    private function saveSigned(string $path, array $json): void
    {
        File::ensureDirectoryExists(dirname($path), 0700, true);
        $json['updated_at'] = now()->toIso8601String();
        $this->atomicWrite($path, $this->signed($json));
    }
    private function atomicWrite(string $path, array $json): void
    {
        $temp = $path.'.tmp.'.getmypid();
        if (file_put_contents($temp, $this->encode($json), LOCK_EX) === false || !rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Cannot persist signed reset audit file. Stop.');
        }
        @chmod($path, 0600);
    }
    private function path(string $folder, string $id): string
    {
        $this->assertPlanId($id);
        return storage_path('app/erp-reset/v13-i02/'.$folder.'/'.$id.'.json');
    }
    private function assertPlanId(string $id): void
    {
        if (!preg_match('/^V13I02-[A-F0-9]{20}$/D', $id)) throw new RuntimeException('Invalid plan ID.');
    }
    private function encode(array $json): string
    {
        return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
