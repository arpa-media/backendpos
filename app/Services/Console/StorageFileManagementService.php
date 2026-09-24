<?php

namespace App\Services\Console;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class StorageFileManagementService
{
    private const ALLOWED_DISKS = ['local', 'public'];
    private const LIST_LIMIT = 10000;
    private const SUMMARY_MAX_ENTRIES = 50000;
    private const SUMMARY_MAX_SECONDS = 2.5;

    public function browse(string $disk, ?string $path = null, array $options = []): array
    {
        $disk = $this->assertDisk($disk);
        $path = $this->normalizePath($path);
        $root = $this->root($disk);
        $absolute = $this->existingDirectory($root, $path);
        $search = mb_strtolower(trim((string) ($options['search'] ?? '')));
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(200, max(20, (int) ($options['per_page'] ?? 100)));

        $entries = [];
        $matchedTotal = 0;
        $listingTruncated = false;
        $iterator = new \DirectoryIterator($absolute);
        foreach ($iterator as $entry) {
            if ($entry->isDot() || $entry->isLink()) {
                continue;
            }
            $name = $entry->getFilename();
            if ($search !== '' && !str_contains(mb_strtolower($name), $search)) {
                continue;
            }
            $matchedTotal++;
            if (count($entries) >= self::LIST_LIMIT) {
                $listingTruncated = true;
                continue;
            }
            $relative = $this->joinPath($path, $name);
            $isDirectory = $entry->isDir();
            $entries[] = [
                'name' => $name,
                'path' => $relative,
                'type' => $isDirectory ? 'folder' : 'file',
                'size_bytes' => $isDirectory ? null : max(0, (int) $entry->getSize()),
                'extension' => $isDirectory ? null : strtolower((string) pathinfo($name, PATHINFO_EXTENSION)),
                'modified_at' => $entry->getMTime() ? date(DATE_ATOM, $entry->getMTime()) : null,
                'downloadable' => ! $isDirectory,
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }
            return strnatcasecmp((string) $a['name'], (string) $b['name']);
        });

        $visibleTotal = count($entries);
        $offset = ($page - 1) * $perPage;
        $pageEntries = array_slice($entries, $offset, $perPage);
        $summary = $this->summary($disk, $path, $absolute);

        return [
            'disks' => $this->disks(),
            'current' => [
                'disk' => $disk,
                'path' => $path,
                'label' => $disk === 'public' ? 'Public Storage' : 'Private Storage',
                'breadcrumbs' => $this->breadcrumbs($path),
            ],
            'entries' => $pageEntries,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $listingTruncated ? $visibleTotal : $matchedTotal,
                'matched_total' => $matchedTotal,
                'last_page' => max(1, (int) ceil(($listingTruncated ? $visibleTotal : $matchedTotal) / $perPage)),
                'listing_truncated' => $listingTruncated,
                'list_limit' => self::LIST_LIMIT,
            ],
            'summary' => $summary,
            'recent_activity' => $this->recentActivity($disk),
        ];
    }

    public function createFolder(string $disk, ?string $parentPath, string $name, ?string $userId): array
    {
        $disk = $this->assertDisk($disk);
        $parentPath = $this->normalizePath($parentPath);
        $name = trim($name);
        if ($name === '' || in_array($name, ['.', '..'], true) || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Nama folder tidak valid.');
        }
        if (str_starts_with($name, '.')) {
            throw new InvalidArgumentException('Folder tersembunyi tidak dapat dibuat dari Console.');
        }

        $root = $this->root($disk);
        $parent = $this->existingDirectory($root, $parentPath);
        $relative = $this->joinPath($parentPath, $name);
        $target = $parent.DIRECTORY_SEPARATOR.$name;
        if (file_exists($target)) {
            throw new RuntimeException('File/folder dengan nama tersebut sudah ada.');
        }
        if (! @mkdir($target, 0775, false) && ! is_dir($target)) {
            throw new RuntimeException('Gagal membuat folder.');
        }
        $this->bumpVersion($disk);
        $this->audit($userId, 'create_folder', $disk, [$relative], null, 'success');

        return ['path' => $relative, 'name' => $name];
    }

    public function move(string $disk, array $paths, ?string $destinationPath, ?string $userId): array
    {
        $disk = $this->assertDisk($disk);
        $root = $this->root($disk);
        $destinationPath = $this->normalizePath($destinationPath);
        $destination = $this->existingDirectory($root, $destinationPath);
        $sources = $this->selectedExistingPaths($root, $paths);
        if ($sources === []) {
            throw new InvalidArgumentException('Pilih minimal satu file/folder.');
        }

        $planned = [];
        $plannedTargets = [];
        foreach ($sources as $source) {
            $sourceRelative = $source['relative'];
            $sourceAbsolute = $source['absolute'];
            $baseName = basename($sourceAbsolute);
            $targetRelative = $this->joinPath($destinationPath, $baseName);
            $targetAbsolute = $destination.DIRECTORY_SEPARATOR.$baseName;
            if ($source['is_dir'] && ($destinationPath === $sourceRelative || str_starts_with($destinationPath.'/', $sourceRelative.'/'))) {
                throw new InvalidArgumentException("Folder {$sourceRelative} tidak dapat dipindahkan ke dalam dirinya sendiri.");
            }
            if ($sourceAbsolute === $targetAbsolute) {
                throw new InvalidArgumentException("{$sourceRelative} sudah berada di folder tujuan.");
            }
            if (file_exists($targetAbsolute) || isset($plannedTargets[$targetRelative])) {
                throw new RuntimeException("Tujuan sudah memiliki/akan menerima {$baseName}. Pastikan nama item yang dipilih unik pada folder tujuan.");
            }
            $plannedTargets[$targetRelative] = true;
            $planned[] = compact('sourceRelative', 'sourceAbsolute', 'targetRelative', 'targetAbsolute') + ['is_dir' => $source['is_dir']];
        }

        $moved = [];
        try {
            foreach ($planned as $item) {
                $ok = $item['is_dir']
                    ? File::moveDirectory($item['sourceAbsolute'], $item['targetAbsolute'])
                    : File::move($item['sourceAbsolute'], $item['targetAbsolute']);
                if (! $ok) {
                    throw new RuntimeException('Gagal memindahkan '.$item['sourceRelative'].'.');
                }
                $moved[] = $item;
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($moved) as $item) {
                try {
                    if (file_exists($item['targetAbsolute']) && ! file_exists($item['sourceAbsolute'])) {
                        $item['is_dir']
                            ? File::moveDirectory($item['targetAbsolute'], $item['sourceAbsolute'])
                            : File::move($item['targetAbsolute'], $item['sourceAbsolute']);
                    }
                } catch (\Throwable) {
                    // Best-effort rollback; audit below captures the failure.
                }
            }
            $this->audit($userId, 'move', $disk, array_column($planned, 'sourceRelative'), $destinationPath, 'failed', $e->getMessage());
            throw $e;
        }

        $this->bumpVersion($disk);
        $this->audit($userId, 'move', $disk, array_column($planned, 'sourceRelative'), $destinationPath, 'success');

        return [
            'moved' => count($moved),
            'destination_path' => $destinationPath,
            'paths' => array_column($moved, 'targetRelative'),
        ];
    }

    public function delete(string $disk, array $paths, ?string $userId): array
    {
        $disk = $this->assertDisk($disk);
        $root = $this->root($disk);
        $sources = $this->selectedExistingPaths($root, $paths);
        if ($sources === []) {
            throw new InvalidArgumentException('Pilih minimal satu file/folder.');
        }

        $deleted = [];
        try {
            foreach ($sources as $source) {
                if ($source['relative'] === '' || basename($source['relative']) === '.gitignore') {
                    throw new InvalidArgumentException('Root storage dan .gitignore dilindungi dari penghapusan.');
                }
                $ok = $source['is_dir'] ? File::deleteDirectory($source['absolute']) : File::delete($source['absolute']);
                if (! $ok) {
                    throw new RuntimeException('Gagal menghapus '.$source['relative'].'.');
                }
                $deleted[] = $source['relative'];
            }
        } catch (\Throwable $e) {
            $this->audit($userId, 'delete', $disk, array_column($sources, 'relative'), null, 'failed', $e->getMessage());
            throw $e;
        }

        $this->bumpVersion($disk);
        $this->audit($userId, 'delete', $disk, $deleted, null, 'success');

        return ['deleted' => count($deleted), 'paths' => $deleted];
    }

    public function fileForDownload(string $disk, string $path): array
    {
        $disk = $this->assertDisk($disk);
        $path = $this->normalizePath($path);
        if ($path === '') {
            throw new InvalidArgumentException('File tidak valid.');
        }
        $absolute = $this->existingPath($this->root($disk), $path);
        if (! is_file($absolute)) {
            throw new InvalidArgumentException('Path bukan file.');
        }
        return ['absolute' => $absolute, 'name' => basename($absolute)];
    }

    public function disks(): array
    {
        return array_map(function (string $disk): array {
            $root = $this->root($disk);
            return [
                'code' => $disk,
                'name' => $disk === 'public' ? 'Public Storage' : 'Private Storage',
                'description' => $disk === 'public' ? 'storage/app/public' : 'storage/app/private',
                'available' => is_dir($root),
            ];
        }, self::ALLOWED_DISKS);
    }

    private function summary(string $disk, string $path, string $absolute): array
    {
        $version = Cache::get($this->versionKey($disk), '0');
        $cacheKey = 'console:file-management:summary:'.sha1($disk.'|'.$path.'|'.$version);
        return Cache::remember($cacheKey, now()->addSeconds(60), function () use ($absolute, $path): array {
            $started = microtime(true);
            $files = 0;
            $folders = 0;
            $bytes = 0;
            $scanned = 0;
            $partial = false;
            $largest = [];
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $item) {
                    if ($item->isLink()) {
                        continue;
                    }
                    $scanned++;
                    if ($scanned > self::SUMMARY_MAX_ENTRIES || (microtime(true) - $started) > self::SUMMARY_MAX_SECONDS) {
                        $partial = true;
                        break;
                    }
                    if ($item->isDir()) {
                        $folders++;
                        continue;
                    }
                    if (! $item->isFile()) {
                        continue;
                    }
                    $size = max(0, (int) $item->getSize());
                    $files++;
                    $bytes += $size;
                    $largest[] = ['name' => $item->getFilename(), 'size_bytes' => $size, 'absolute' => $item->getPathname()];
                    usort($largest, static fn (array $a, array $b): int => $b['size_bytes'] <=> $a['size_bytes']);
                    if (count($largest) > 10) {
                        array_pop($largest);
                    }
                }
            } catch (\UnexpectedValueException) {
                $partial = true;
            }

            $rootPrefix = rtrim($absolute, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            $largest = array_map(static function (array $row) use ($rootPrefix, $path): array {
                $relative = str_replace('\\', '/', str_replace($rootPrefix, '', $row['absolute']));
                $logical = $path === '' ? $relative : $path.'/'.$relative;
                return ['name' => $row['name'], 'path' => $logical, 'size_bytes' => $row['size_bytes']];
            }, $largest);

            return [
                'file_count' => $files,
                'folder_count' => $folders,
                'total_bytes' => $bytes,
                'scanned_entries' => $scanned,
                'partial' => $partial,
                'scan_limit' => self::SUMMARY_MAX_ENTRIES,
                'largest_files' => $largest,
            ];
        });
    }

    private function recentActivity(string $disk): array
    {
        if (! DB::getSchemaBuilder()->hasTable('console_file_management_audit_logs')) {
            return [];
        }
        return DB::table('console_file_management_audit_logs')
            ->where('disk', $disk)
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'user_id', 'action', 'source_paths', 'destination_path', 'status', 'message', 'created_at'])
            ->map(function ($row): array {
                return [
                    'id' => (string) $row->id,
                    'user_id' => $row->user_id ? (string) $row->user_id : null,
                    'action' => (string) $row->action,
                    'source_paths' => json_decode((string) $row->source_paths, true) ?: [],
                    'destination_path' => $row->destination_path,
                    'status' => (string) $row->status,
                    'message' => $row->message,
                    'created_at' => $row->created_at,
                ];
            })->all();
    }

    private function audit(?string $userId, string $action, string $disk, array $sourcePaths, ?string $destination, string $status, ?string $message = null): void
    {
        if (! DB::getSchemaBuilder()->hasTable('console_file_management_audit_logs')) {
            return;
        }
        DB::table('console_file_management_audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $userId ?: null,
            'action' => $action,
            'disk' => $disk,
            'source_paths' => json_encode(array_values($sourcePaths), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'destination_path' => $destination,
            'status' => $status,
            'message' => $message ? mb_substr($message, 0, 1000) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function selectedExistingPaths(string $root, array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            $value = $this->normalizePath(is_string($path) ? $path : '');
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }
        $paths = array_keys($normalized);
        usort($paths, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));

        $kept = [];
        foreach ($paths as $path) {
            $covered = false;
            foreach ($kept as $parent) {
                if ($path === $parent['relative'] || str_starts_with($path.'/', $parent['relative'].'/')) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            $absolute = $this->existingPath($root, $path);
            $kept[] = ['relative' => $path, 'absolute' => $absolute, 'is_dir' => is_dir($absolute)];
        }
        return $kept;
    }

    private function root(string $disk): string
    {
        $root = (string) config("filesystems.disks.{$disk}.root", '');
        if ($root === '') {
            throw new RuntimeException("Root disk {$disk} tidak tersedia.");
        }
        File::ensureDirectoryExists($root);
        $real = realpath($root);
        if ($real === false) {
            throw new RuntimeException("Root disk {$disk} tidak dapat diakses.");
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function existingDirectory(string $root, string $path): string
    {
        $absolute = $path === '' ? $root : $this->existingPath($root, $path);
        if (! is_dir($absolute)) {
            throw new InvalidArgumentException('Folder tidak ditemukan.');
        }
        return $absolute;
    }

    private function existingPath(string $root, string $path): string
    {
        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        if ($this->hasSymlinkSegment($root, $path)) {
            throw new InvalidArgumentException('Symbolic link tidak dapat dikelola dari Console.');
        }
        $real = realpath($candidate);
        if ($real === false || ! $this->withinRoot($root, $real)) {
            throw new InvalidArgumentException('Path tidak ditemukan atau berada di luar storage aplikasi.');
        }
        if (is_link($candidate) || is_link($real)) {
            throw new InvalidArgumentException('Symbolic link tidak dapat dikelola dari Console.');
        }
        return $real;
    }


    private function hasSymlinkSegment(string $root, string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $current = $root;
        foreach (explode('/', $path) as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                return true;
            }
        }
        return false;
    }

    private function withinRoot(string $root, string $absolute): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $absolute = str_replace('\\', '/', $absolute);
        return $absolute === $root || str_starts_with($absolute.'/', $root.'/');
    }

    private function assertDisk(string $disk): string
    {
        $disk = strtolower(trim($disk));
        if (! in_array($disk, self::ALLOWED_DISKS, true)) {
            throw new InvalidArgumentException('Disk storage tidak diizinkan.');
        }
        if ((string) config("filesystems.disks.{$disk}.driver") !== 'local') {
            throw new InvalidArgumentException('File Management hanya mengelola local application storage.');
        }
        return $disk;
    }

    private function normalizePath(?string $path): string
    {
        $path = str_replace('\\', '/', trim((string) $path));
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        if (str_contains($path, "\0") || preg_match('/^[A-Za-z]:\//', $path)) {
            throw new InvalidArgumentException('Path tidak valid.');
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Path traversal tidak diizinkan.');
            }
        }
        return implode('/', $segments);
    }

    private function joinPath(string $parent, string $name): string
    {
        return $parent === '' ? $name : $parent.'/'.$name;
    }

    private function breadcrumbs(string $path): array
    {
        $rows = [['name' => 'Root', 'path' => '']];
        if ($path === '') {
            return $rows;
        }
        $acc = [];
        foreach (explode('/', $path) as $segment) {
            $acc[] = $segment;
            $rows[] = ['name' => $segment, 'path' => implode('/', $acc)];
        }
        return $rows;
    }

    private function versionKey(string $disk): string
    {
        return 'console:file-management:version:'.$disk;
    }

    private function bumpVersion(string $disk): void
    {
        Cache::forever($this->versionKey($disk), (string) Str::uuid());
    }
}
