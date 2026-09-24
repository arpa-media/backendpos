<?php

namespace App\Services\Purchasing;

use InvalidArgumentException;

class PurchasingModuleRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $resolvedModules = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->resolve());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        $normalized = $this->normalizeKey($key);

        return $this->resolve()[$normalized] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolve(): array
    {
        if ($this->resolvedModules !== null) {
            return $this->resolvedModules;
        }

        $files = glob(config_path('purchasing_modules/*.php')) ?: [];
        sort($files, SORT_STRING);

        $modules = [];
        foreach ($files as $file) {
            $definition = require $file;
            if (! is_array($definition)) {
                continue;
            }

            $rows = array_is_list($definition)
                ? $definition
                : ($definition['modules'] ?? []);

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $normalized = $this->normalizeDefinition($row, basename($file));
                $modules[$normalized['key']] = $normalized;
            }
        }

        uasort($modules, function (array $left, array $right): int {
            $order = ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0));
            if ($order !== 0) {
                return $order;
            }

            return strcmp((string) $left['label'], (string) $right['label']);
        });

        return $this->resolvedModules = $modules;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeDefinition(array $row, string $source): array
    {
        $key = $this->normalizeKey((string) ($row['key'] ?? ''));
        $label = trim((string) ($row['label'] ?? ''));
        $path = '/' . ltrim(trim((string) ($row['path'] ?? '')), '/');
        $permissionBase = trim((string) ($row['permission'] ?? ''));

        if ($key === '' || $label === '' || $permissionBase === '') {
            throw new InvalidArgumentException("Purchasing module invalid pada {$source}: key, label, dan permission wajib diisi.");
        }

        if (! str_starts_with($path, '/purchasing/') && $path !== '/portal/purchasing/dashboard') {
            throw new InvalidArgumentException("Purchasing module {$key} memiliki path di luar portal Purchasing.");
        }

        return [
            'key' => $key,
            'code' => trim((string) ($row['code'] ?? ('purchasing-' . $key))),
            'label' => $label,
            'singular_label' => trim((string) ($row['singular_label'] ?? $label)),
            'description' => trim((string) ($row['description'] ?? '')),
            'path' => $path,
            'route_name' => trim((string) ($row['route_name'] ?? ('purchasing-' . $key))),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'permission' => $permissionBase,
            'permissions' => [
                'view' => $permissionBase . '.view',
                'create' => $permissionBase . '.create',
                'edit' => $permissionBase . '.update',
                'delete' => $permissionBase . '.delete',
            ],
            'document_prefix' => strtoupper(trim((string) ($row['document_prefix'] ?? 'DOC'))),
            'status_options' => array_values(array_filter((array) ($row['status_options'] ?? [
                'DRAFT',
                'AWAITING APPROVAL',
                'APPROVED',
                'REJECTED',
                'COMPLETED',
            ]))),
            'is_dashboard' => (bool) ($row['is_dashboard'] ?? false),
            'implementation_status' => trim((string) ($row['implementation_status'] ?? 'SHELL')),
            'source_file' => $source,
        ];
    }

    private function normalizeKey(string $value): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value) ?? '', '-'));
    }
}
