<?php

namespace App\Console\Commands;

use App\Models\Purchasing\DocumentAttachment;
use App\Services\Purchasing\PurchasingDocumentAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PurchasingIteration04CheckCommand extends Command
{
    protected $signature = 'purchasing:iteration-04-check';
    protected $description = 'Smoke check Iterasi 04 Fund Requests + attachment engine.';

    public function handle(): int
    {
        $checks = [];
        $checks['Attachment table'] = Schema::hasTable('pur_document_attachments');
        $checks['Attachment columns'] = Schema::hasTable('pur_document_attachments')
            && collect(['document_type','document_id','mime_type','file_size','original_file_size','compression_status','sha256','path'])
                ->every(fn (string $column): bool => Schema::hasColumn('pur_document_attachments', $column));
        $checks['Attachment model'] = class_exists(DocumentAttachment::class);
        $checks['Attachment service'] = class_exists(PurchasingDocumentAttachmentService::class);
        $checks['Upload route'] = Route::has('purchasing.fund-requests.attachments.store');
        $checks['Delete route'] = Route::has('purchasing.fund-requests.attachments.destroy');
        $checks['Protected preview route'] = Route::has('purchasing.document-attachments.content');

        $menuOk = true;
        if (Schema::hasTable('access_menus')) {
            $menu = DB::table('access_menus')->where('code', 'purchasing-fund-requests')->first();
            $menuOk = $menu
                && (string) $menu->name === 'Fund Requests'
                && (string) $menu->path === '/purchasing/fund-requests'
                && (bool) $menu->is_active;
        }
        $checks['Access Matrix Fund Requests'] = $menuOk;

        $frontendRoot = base_path('../frontend - Backoffice/src/modules/purchasing');
        $checks['Frontend attachment API'] = is_file($frontendRoot.'/lib/fundRequestApi.js')
            && str_contains((string) file_get_contents($frontendRoot.'/lib/fundRequestApi.js'), 'uploadFundRequestAttachment');
        $checks['Frontend print attachment'] = is_file($frontendRoot.'/pages/PurchasingFundRequestPrintPage.vue')
            && str_contains((string) file_get_contents($frontendRoot.'/pages/PurchasingFundRequestPrintPage.vue'), 'attachment-page');
        $checks['Frontend renamed menu'] = is_file($frontendRoot.'/menu-modules/03-fund-request.js')
            && str_contains((string) file_get_contents($frontendRoot.'/menu-modules/03-fund-request.js'), "label: 'Fund Requests'");

        $rows = collect($checks)->map(fn (bool $ok, string $name): array => [$name, $ok ? 'PASS' : 'FAIL'])->values()->all();
        $this->table(['Check', 'Result'], $rows);
        $passed = ! collect($checks)->contains(false);
        $this->line('Status: '.($passed ? 'PASSED' : 'FAILED'));

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
