<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrGoLiveAuditService;
use Illuminate\Console\Command;

class HrGoLiveAuditCommand extends Command
{
    protected $signature = 'hr:go-live-audit {--strict : Warning juga menjadi blocker} {--no-data : Lewati data reconciliation} {--no-persist : Jangan simpan run/check ke database} {--json= : Simpan report JSON ke path}';
    protected $description = 'Final Human Resource go-live gate Iterasi 20.';

    public function handle(HrGoLiveAuditService $service): int
    {
        $result=$service->run([
            'strict'=>(bool)$this->option('strict'),
            'include_data_checks'=>!(bool)$this->option('no-data'),
            'persist'=>!(bool)$this->option('no-persist'),
        ]);
        $summary=$result['summary'];
        $this->info('Human Resource Go-Live Gate — '.$summary['overall_status']);
        $this->line('Run: '.$summary['run_number']);
        $this->table(['Status','Category','Check','Message'],array_map(fn($c)=>[$c['status'],$c['category'],$c['check_code'],$c['message']],$result['checks']));
        $this->line(sprintf('PASS %d | WARN %d | FAIL %d | SKIP %d',$summary['counts']['pass'],$summary['counts']['warn'],$summary['counts']['fail'],$summary['counts']['skip']));

        if($path=trim((string)$this->option('json'))){
            $absolute=str_starts_with($path,'/')?$path:base_path($path);
            @mkdir(dirname($absolute),0775,true);
            file_put_contents($absolute,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $this->info('JSON report: '.$absolute);
        }
        return $summary['overall_status']==='FAILED'?self::FAILURE:self::SUCCESS;
    }
}
