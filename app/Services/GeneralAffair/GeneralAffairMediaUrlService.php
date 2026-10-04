<?php

namespace App\Services\GeneralAffair;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class GeneralAffairMediaUrlService
{
    private const ALLOWED_DISKS = ['public'];
    private const ALLOWED_PREFIX = 'general-affair/';

    public function url(?string $disk, ?string $path): ?string
    {
        $disk = trim((string) ($disk ?: 'public'));
        $path = $this->normalizePath($path);

        if ($path === null || ! in_array($disk, self::ALLOWED_DISKS, true)) {
            return null;
        }

        $token = $this->encode($disk, $path);
        return $this->requestOrigin().'/api/v1/general-affair/media/'.rawurlencode($token);
    }

    /** @return array{disk:string,path:string} */
    public function decode(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('Invalid media token.');
        }

        [$payload, $signature] = $parts;
        $expected = $this->signature($payload);
        if (! hash_equals($expected, $signature)) {
            throw new InvalidArgumentException('Invalid media signature.');
        }

        $decoded = $this->base64UrlDecode($payload);
        $data = json_decode($decoded, true);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid media payload.');
        }

        $disk = trim((string) ($data['d'] ?? ''));
        $path = $this->normalizePath($data['p'] ?? null);
        if ($path === null || ! in_array($disk, self::ALLOWED_DISKS, true)) {
            throw new InvalidArgumentException('Media target is not allowed.');
        }

        return ['disk' => $disk, 'path' => $path];
    }

    private function encode(string $disk, string $path): string
    {
        $payload = $this->base64UrlEncode((string) json_encode([
            'd' => $disk,
            'p' => $path,
        ], JSON_UNESCAPED_SLASHES));

        return $payload.'.'.$this->signature($payload);
    }

    private function signature(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->signingKey(), true));
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key', '');
        if (Str::startsWith($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') return $decoded;
        }
        if ($key === '') throw new RuntimeException('APP_KEY wajib tersedia untuk GA media token.');
        return $key;
    }

    private function normalizePath(mixed $value): ?string
    {
        $path = str_replace('\\', '/', trim((string) $value));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, "\0")) return null;
        if (str_contains($path, '../') || str_starts_with($path, '..')) return null;
        if (! Str::startsWith($path, self::ALLOWED_PREFIX)) return null;
        return $path;
    }

    private function requestOrigin(): string
    {
        if (! app()->bound('request')) return rtrim((string) config('app.url'), '/');

        $request = request();
        $forwardedProto = trim((string) $request->headers->get('x-forwarded-proto', ''));
        $scheme = strtolower(trim(explode(',', $forwardedProto)[0] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) $scheme = $request->getScheme();

        // Host always comes from the actual API request Host header. We only use
        // X-Forwarded-Proto to survive common TLS termination without trusting an
        // arbitrary X-Forwarded-Host value for links rendered into the browser.
        $host = $request->getHttpHost();
        return $scheme.'://'.$host;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) throw new InvalidArgumentException('Invalid media payload encoding.');
        return $decoded;
    }
}
