<?php

namespace App\Support\Upload18;

use App\Services\Upload18Service;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What the saved Upload18 key may do, as Upload18's GET /keyinfo says (API V2.1): a master key (every permission), a
 * restricted key with some of Upload, Read, Edit and Delete, or an upload token. Asked at most about once a day per key
 * (cached by the key's SHA-256, never the key itself); an answer that could not be had is asked again after 10 minutes.
 * One ask waits 4 seconds at most (Upload18Service::keyInfo()).
 *
 * Used for two things:
 *  - allowsBrowserFallback() (forService(): a current answer): when Upload18 cannot make an upload token, the browser may
 *    get the key itself only when it is a restricted key with no more than Upload + Read (Upload18's documented "option
 *    1"); never a master key or a key that can edit or delete;
 *  - problems() (forCard()): the warnings on Settings > Videos & uploads > Upload18 connection. The card never waits for
 *    Upload18 once a key was checked: it shows the last answer (kept a week) and asks again after the page was sent when
 *    that answer is older than a day. Only a key never checked before (the owner just saved it) is asked while the page
 *    is made, so the owner sees at once what the new key may do.
 */
final class KeyInfo
{
    public const CACHE_SECONDS = 86400;

    public const RETRY_SECONDS = 600;

    /** How long the card may show an answer while it is asked again in the background. */
    public const KEEP_SECONDS = 604800;

    public const OK = 'ok';

    /** Upload18 refused the key (wrong, revoked or regenerated). */
    public const INVALID = 'invalid';

    /** No answer, or one this version cannot read. */
    public const UNKNOWN = 'unknown';

    private const KNOWN_SCOPES = ['upload', 'read', 'edit', 'delete'];

    /**
     * @param  list<string>  $scopes
     */
    private function __construct(
        public readonly string $status,
        public readonly string $type,
        public readonly array $scopes,
    ) {}

    public static function unknown(): self
    {
        return new self(self::UNKNOWN, '', []);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID, '', []);
    }

    /**
     * Read one /keyinfo answer: {"status":"success","data":{"type":"restricted","scopes":["upload","read"],...}}. The
     * scopes may also come as a text ("upload,read") or as {"upload": true, ...}; "*" or "all" means every permission.
     */
    public static function fromAnswer(mixed $answer, int $httpStatus = 200): self
    {
        if ($httpStatus === 401) {
            return self::invalid();
        }

        if (! is_array($answer) || $httpStatus < 200 || $httpStatus >= 300) {
            return self::unknown();
        }

        if (strtolower((string) ($answer['status'] ?? '')) === 'error') {
            $code = strtolower((string) ($answer['error_code'] ?? ''));

            return in_array($code, ['invalid_api_key', 'missing_api_key', 'invalid_upload_token', 'upload_token_expired'], true)
                ? self::invalid()
                : self::unknown();
        }

        $data = is_array($answer['data'] ?? null) ? $answer['data'] : $answer;
        $rawType = strtolower(trim((string) ($data['type'] ?? $data['key_type'] ?? $data['kind'] ?? $data['token_type'] ?? '')));
        $scopes = self::scopes($data['scopes'] ?? $data['permissions'] ?? $data['scope'] ?? $data['perms'] ?? null);

        $type = match (true) {
            str_contains($rawType, 'master') || self::flag($data['is_master'] ?? $data['master'] ?? null) => 'master',
            str_contains($rawType, 'token') || $rawType === 'upload' => 'token',
            str_contains($rawType, 'restrict') => 'restricted',
            default => '',
        };

        if (in_array('*', $scopes, true) || in_array('all', $scopes, true)) {
            $type = $type === '' ? 'master' : $type;
            $scopes = array_values(array_unique([...array_diff($scopes, ['*', 'all']), ...self::KNOWN_SCOPES]));
        }

        if ($type === 'master' && $scopes === []) {
            $scopes = self::KNOWN_SCOPES;
        }

        if ($type === '') {
            return self::unknown();
        }

        sort($scopes);

        return new self(self::OK, $type, $scopes);
    }

    /** The saved key's current answer (asked at most a day ago, 10 minutes for no answer), else asked now. */
    public static function forService(Upload18Service $upload18, bool $refresh = false): self
    {
        if (! $upload18->hasKey()) {
            return self::unknown();
        }

        $cacheKey = self::cacheKey($upload18);
        $cached = $refresh ? null : self::cached($cacheKey);

        if ($cached !== null && ! self::stale($cached)) {
            return self::fromCache($cached);
        }

        return self::ask($upload18, $cacheKey);
    }

    /**
     * For the Upload18 card: the last answer at once, asked again after the page was sent when it is older than a day
     * (10 minutes for no answer); a key never checked before is asked now (4 seconds at most).
     */
    public static function forCard(Upload18Service $upload18): self
    {
        if (! $upload18->hasKey()) {
            return self::unknown();
        }

        $cacheKey = self::cacheKey($upload18);
        $cached = self::cached($cacheKey);

        if ($cached === null) {
            return self::ask($upload18, $cacheKey);
        }

        if (self::stale($cached)) {
            // After the response went out (Laravel's terminating callbacks): the admin never waits for Upload18 here.
            app()->terminating(static function () use ($upload18, $cacheKey): void {
                $again = self::cached($cacheKey);

                if ($again === null || self::stale($again)) {
                    self::ask($upload18, $cacheKey);
                }
            });
        }

        return self::fromCache($cached);
    }

    private static function cacheKey(Upload18Service $upload18): string
    {
        return 'upload18:keyinfo:v2:'.hash('sha256', $upload18->apiUrl()."\n".$upload18->apiKey());
    }

    /** @return array{status: string, type: string, scopes: list<string>, checked_at: int}|null */
    private static function cached(string $cacheKey): ?array
    {
        try {
            $cached = Cache::get($cacheKey);
        } catch (Throwable) {
            return null;
        }

        return is_array($cached) && isset($cached['status'], $cached['type'], $cached['scopes'], $cached['checked_at']) ? $cached : null;
    }

    /** @param  array{status: string, checked_at: int}  $cached */
    private static function stale(array $cached): bool
    {
        $age = now()->getTimestamp() - (int) $cached['checked_at'];

        return $age < 0 || $age >= ($cached['status'] === self::UNKNOWN ? self::RETRY_SECONDS : self::CACHE_SECONDS);
    }

    /** @param  array{status: string, type: string, scopes: array<mixed>}  $cached */
    private static function fromCache(array $cached): self
    {
        return new self((string) $cached['status'], (string) $cached['type'], array_values(array_map('strval', (array) $cached['scopes'])));
    }

    private static function ask(Upload18Service $upload18, string $cacheKey): self
    {
        try {
            ['status' => $http, 'json' => $json] = $upload18->keyInfo();
            $info = self::fromAnswer($json, $http);
        } catch (Throwable) {
            $info = self::unknown();
        }

        try {
            Cache::put($cacheKey, $info->toArray() + ['checked_at' => now()->getTimestamp()], self::KEEP_SECONDS);
        } catch (Throwable) {
            // Without a cache the next page asks again; nothing else depends on it.
        }

        return $info;
    }

    public function isMaster(): bool
    {
        return $this->status === self::OK && $this->type === 'master';
    }

    public function can(string $scope): bool
    {
        return $this->status === self::OK && in_array($scope, $this->scopes, true);
    }

    /**
     * A key the browser may get when Upload18 cannot make an upload token: a restricted key that can upload and do at
     * most Upload + Read. Unknown scopes count as more.
     */
    public function allowsBrowserFallback(): bool
    {
        return $this->status === self::OK
            && $this->type === 'restricted'
            && $this->can('upload')
            && array_diff($this->scopes, ['upload', 'read']) === [];
    }

    /**
     * What the Upload18 card warns about, most serious first: invalid, master, token, edit_delete, no_upload, no_read.
     * Empty for a restricted Upload + Read key and when Upload18 could not be asked.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        if ($this->status === self::INVALID) {
            return ['invalid'];
        }

        if ($this->status !== self::OK) {
            return [];
        }

        if ($this->type === 'master') {
            return ['master'];
        }

        if ($this->type === 'token') {
            return ['token'];
        }

        $problems = [];

        if (array_diff($this->scopes, ['upload', 'read']) !== []) {
            $problems[] = 'edit_delete';
        }

        if (! $this->can('upload')) {
            $problems[] = 'no_upload';
        }

        if (! $this->can('read')) {
            $problems[] = 'no_read';
        }

        return $problems;
    }

    /** True when Upload18 confirmed a restricted key with exactly what the site needs. */
    public function isRecommended(): bool
    {
        return $this->status === self::OK && $this->type === 'restricted' && $this->problems() === [];
    }

    /** @return array{status: string, type: string, scopes: list<string>} what the cache keeps (never the key) */
    public function toArray(): array
    {
        return ['status' => $this->status, 'type' => $this->type, 'scopes' => $this->scopes];
    }

    /** @return list<string> */
    private static function scopes(mixed $raw): array
    {
        $list = [];

        if (is_string($raw)) {
            $list = preg_split('/[\s,|;+]+/', $raw) ?: [];
        } elseif (is_array($raw)) {
            if (array_is_list($raw)) {
                $list = $raw;
            } else {
                foreach ($raw as $name => $on) {
                    if (self::flag($on)) {
                        $list[] = $name;
                    }
                }
            }
        }

        $scopes = [];

        foreach ($list as $scope) {
            if (! is_string($scope)) {
                continue;
            }

            $scope = strtolower(trim($scope));

            if ($scope !== '') {
                $scopes[] = $scope;
            }
        }

        return array_values(array_unique($scopes));
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || (is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true));
    }
}
