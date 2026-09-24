<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ErpPosFinalI07Hf01UserManagementFilterDeploymentCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i07-hf01-user-management-filter-deployment-check {--strict-build : Fail when no frontend dist contains the 3 UI filter labels}';
    protected $description = 'Verify User Management Assignment/Access Role/Access Level filters across both frontend source trees and production bundles.';

    public function handle(): int
    {
        $root = dirname(base_path());
        $frontends = [
            'frontend' => $root.DIRECTORY_SEPARATOR.'frontend',
            'frontend - Backoffice' => $root.DIRECTORY_SEPARATOR.'frontend - Backoffice',
        ];
        $markers = ['Filter Assignment', 'Filter Access Role', 'Filter Access Level', 'assignment_role_title', 'access_role_id', 'access_level_id'];
        $uiMarkers = ['Filter Assignment', 'Filter Access Role', 'Filter Access Level'];
        $failures = 0;
        $builtOk = 0;

        $controller = base_path('app/Http/Controllers/Api/V1/UserManagementController.php');
        $controllerBody = @file_get_contents($controller) ?: '';
        foreach (['assignment_role_title', 'access_role_id', 'access_level_id', "'assignment_roles' =>"] as $needle) {
            if (!str_contains($controllerBody, $needle)) {
                $this->error("Backend missing: {$needle}");
                $failures++;
            }
        }
        if ($failures === 0) {
            $this->info('PASS backend: filter contract I07 tersedia.');
        }

        foreach ($frontends as $label => $frontendRoot) {
            $sourcePath = $frontendRoot.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'pages'.DIRECTORY_SEPARATOR.'UserManagementPage.vue';
            $source = @file_get_contents($sourcePath) ?: '';
            if ($source === '') {
                $this->warn("{$label}: source tidak ditemukan ({$sourcePath}).");
                continue;
            }

            $missing = array_values(array_filter($markers, fn ($marker) => !str_contains($source, $marker)));
            if ($missing !== []) {
                $this->error("{$label}: source BELUM lengkap: ".implode(', ', $missing));
                $failures++;
            } else {
                $this->info("PASS {$label}: source memiliki 3 filter.");
            }

            $dist = $frontendRoot.DIRECTORY_SEPARATOR.'dist';
            if (!is_dir($dist)) {
                $this->warn("{$label}: dist/ tidak ditemukan; production build belum tersedia di workspace ini.");
                continue;
            }

            $bundle = '';
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dist, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['js', 'mjs'], true)) {
                    continue;
                }
                $bundle .= @file_get_contents($file->getPathname()) ?: '';
            }

            $missingDist = array_values(array_filter($uiMarkers, fn ($marker) => !str_contains($bundle, $marker)));
            if ($missingDist !== []) {
                $this->warn("{$label}: dist/ masih bundle lama; missing ".implode(', ', $missingDist));
            } else {
                $this->info("PASS {$label}: dist/ memuat 3 filter.");
                $builtOk++;
            }
        }

        if ($this->option('strict-build') && $builtOk === 0) {
            $this->error('Tidak ada production dist yang terverifikasi memuat 3 filter. Build/deploy frontend yang digunakan production.');
            $failures++;
        }

        if ($failures > 0) {
            $this->error("ERP POS FINAL I07-HF01 validation FAIL ({$failures} failure). ");
            return self::FAILURE;
        }

        $this->info('ERP POS FINAL I07-HF01 validation PASS.');
        return self::SUCCESS;
    }
}
