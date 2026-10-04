<?php

namespace App\Console\Commands;

use App\Services\GeneralAffair\GeneralAffairMediaUrlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class ErpPosV10I21GeneralAffairMediaPreviewHardeningCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i21-ga-media-preview-hardening-check';
    protected $description = 'Verify ERP POS V10 I21 General Affair unified media preview hardening';

    public function handle(): int
    {
        $base = base_path();
        $files = [
            'media service' => $base.'/app/Services/GeneralAffair/GeneralAffairMediaUrlService.php',
            'media controller' => $base.'/app/Http/Controllers/Api/V1/GeneralAffair/GeneralAffairMediaController.php',
            'media route' => $base.'/routes/general_affair_modules/21_media_preview_hardening.php',
        ];

        $checks = [];
        foreach ($files as $label => $path) $checks[$label] = is_file($path);

        $routeUris = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();
        $checks['media route registered'] = in_array('api/v1/general-affair/media/{token}', $routeUris, true);

        $targets = [
            'Ticket' => $base.'/app/Http/Controllers/Api/V1/GeneralAffair/GeneralAffairTicketController.php',
            'CCTV' => $base.'/app/Http/Controllers/Api/V1/GeneralAffair/GeneralAffairCctvRequestController.php',
            'Costing' => $base.'/app/Http/Controllers/Api/V1/GeneralAffair/GeneralAffairCostingController.php',
            'Asset/Inventory' => $base.'/app/Services/GeneralAffair/AssetInventoryPhotoService.php',
            'Inventory Logs' => $base.'/app/Services/GeneralAffair/InventoryMovementPhotoService.php',
            'Inventory Audit' => $base.'/app/Services/GeneralAffair/InventoryAuditPhotoService.php',
        ];

        foreach ($targets as $label => $path) {
            $source = is_file($path) ? (string) file_get_contents($path) : '';
            $checks[$label.' uses unified media URL'] = str_contains($source, 'media->url(');
        }

        $service = app(GeneralAffairMediaUrlService::class);
        $method = new \ReflectionMethod($service, 'decode');
        $checks['media decoder available'] = $method->isPublic();

        $route = collect(Route::getRoutes())->first(fn ($item) => $item->uri() === 'api/v1/general-affair/media/{token}');
        $middleware = $route ? $route->gatherMiddleware() : [];
        $checks['browser image route does not require auth:sanctum'] = $route !== null && ! in_array('auth:sanctum', $middleware, true);

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>').'  '.$label);
            if (! $ok) $failed = true;
        }

        $this->newLine();
        if ($failed) {
            $this->error('ERP POS V10 I21 GA Media Preview Hardening is NOT READY. Review failed checks above.');
            return self::FAILURE;
        }

        $this->info('ERP POS V10 I21 GA Media Preview Hardening is READY.');
        $this->line('Tidak ada migration, menu baru, atau perubahan frontend Android pada I21.');
        return self::SUCCESS;
    }
}
