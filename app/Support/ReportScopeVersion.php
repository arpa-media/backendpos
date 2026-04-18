<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class ReportScopeVersion
{
    private const KEY = 'report-scope-cache:version';

    public static function current(): string
    {
        $version = Cache::get(self::KEY);
        if (is_string($version) && trim($version) !== '') {
            return $version;
        }

        $version = 'scope-bootstrap-v1';
        Cache::forever(self::KEY, $version);

        return $version;
    }

    public static function bump(?string $reason = null): string
    {
        $seed = sprintf('scope-v%s-%s', now()->format('YmdHis'), str_replace('.', '', (string) microtime(true)));
        if (is_string($reason) && trim($reason) !== '') {
            $seed .= '-' . substr(sha1($reason), 0, 8);
        }

        Cache::forever(self::KEY, $seed);

        return $seed;
    }
}
