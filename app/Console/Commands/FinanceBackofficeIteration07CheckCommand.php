<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceOpenXmlXlsxWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class FinanceBackofficeIteration07CheckCommand extends Command
{
    protected $signature='finance:backoffice-iteration-07-check';
    protected $description='Smoke check Financial Statement Export Center dan XLSX writer.';

    public function handle(FinanceOpenXmlXlsxWriter $writer): int
    {
        $checks=[];
        foreach(['finance_chart_of_accounts','finance_journal_entries','finance_journal_entry_lines','finance_financial_statement_rules'] as $table){
            $checks["Table {$table}"]=Schema::hasTable($table);
        }
        foreach([
            'finance.statement-export.options','finance.statement-export.balance-sheet',
            'finance.statement-export.profit-loss','finance.statement-export.cash-flow',
        ] as $route){
            $checks["Route {$route}"]=Route::has($route);
        }
        $checks['Access menu Financial Statement Export']=Schema::hasTable('access_menus')&&DB::table('access_menus')
            ->where('code','finance-financial-statement-export')->where('path','/finance/financial-statement-export')->where('is_active',true)->exists();
        $checks['Permission export view']=Schema::hasTable('permissions')&&DB::table('permissions')->where('name','finance.financial_statement_export.view')->exists();

        try{
            $path=storage_path('app/tmp/finance-statement-iter07-smoke.xlsx');
            $writer->write($path,'Smoke Test',[
                ['cells'=>[
                    FinanceOpenXmlXlsxWriter::cell('SMOKE XLSX',FinanceOpenXmlXlsxWriter::STYLE_TITLE,'s'),
                ]],
                ['cells'=>[
                    FinanceOpenXmlXlsxWriter::cell('Account',FinanceOpenXmlXlsxWriter::STYLE_HEADER,'s'),
                    FinanceOpenXmlXlsxWriter::cell('Amount',FinanceOpenXmlXlsxWriter::STYLE_HEADER,'s'),
                ]],
                ['cells'=>[
                    FinanceOpenXmlXlsxWriter::cell('Bank',FinanceOpenXmlXlsxWriter::STYLE_TEXT,'s'),
                    FinanceOpenXmlXlsxWriter::cell(123456.78,FinanceOpenXmlXlsxWriter::STYLE_NUMBER,'n'),
                ]],
            ],['A1:B1'],[1=>24,2=>20],2,'Finance Smoke Check');
            $prefix=is_file($path)?file_get_contents($path,false,null,0,2):'';
            $checks['XLSX writer PK signature']=$prefix==='PK'&&filesize($path)>1000;
            @unlink($path);
        }catch(Throwable $e){
            $checks['XLSX writer PK signature']=false;
            $this->warn('XLSX writer: '.$e->getMessage());
        }

        $rows=collect($checks)->map(fn($ok,$name)=>[$name,$ok?'OK':'FAILED']);
        $this->table(['Check','Result'],$rows->values()->all());
        if($rows->contains(fn($r)=>$r[1]!=='OK')){
            $this->error('Finance Backoffice Iterasi 07 smoke check FAILED.');
            return self::FAILURE;
        }
        $this->info('Finance Backoffice Iterasi 07 smoke check OK.');
        return self::SUCCESS;
    }
}
