<?php

namespace App\Support\Updater;

use App\Http\Controllers\Admin\AuditLogController;
use App\Support\AppVersion;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Is a newer AVDBCMS out? One GET of the latest release of AppVersion::REPOSITORY from the GitHub API: no token and
 * nothing about the site is sent (User-Agent "AVDBCMS-Updater/<version>"), certificates are always checked. The
 * answer is kept language-neutral for 12 hours (a failure for 1 hour); "Check now" asks again at most once a minute
 * per site, because the 60 requests per hour of the unauthenticated API are shared by every site on the server's
 * address. When the API says "too many requests", the tag comes from the redirect of github.com/<repo>/releases/latest
 * instead (that page has no such limit) and the .sha256 asset is the only checksum then; the last good answer stays.
 *
 * An answer: state (available | current | none | error), checked_at, release (version, tag, name, published_at,
 * body, html_url, zip {name, url, size, digest}, sha256 {name, url}), source (api | web), error {code, reset,
 * version, status}, last_good (the release of the last good answer, kept with an error).
 */
final class ReleaseCheck
{
    public const CACHE_KEY = 'updates.latest';

    public const GOOD_KEY = 'updates.last_good';

    public const THROTTLE_KEY = 'updates:check-now';

    /** The update package is about 65 MB; a release asset above this is refused. */
    public const MAX_ZIP_BYTES = 300 * 1024 * 1024;

    private const KEEP_SECONDS = 43200;

    private const FAILURE_SECONDS = 3600;

    private const GOOD_SECONDS = 604800;

    private const THROTTLE_SECONDS = 60;

    private const MAX_NOTES = 20000;

    /** The kept answer, without asking GitHub (Home, the old dashboard and the Updates page render from it). */
    public function cached(): ?array
    {
        try {
            $answer = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_array($answer) ? self::evaluate($answer) : null;
    }

    /** The kept answer, or a fresh one when none is kept. $timeout: 10 s on the Updates page, 3 s as a Home check. */
    public function get(int $timeout = 10): array
    {
        return $this->cached() ?? $this->store($this->fetch($timeout));
    }

    /**
     * "Check now": a fresh answer, at most once a minute per site (each real check gets the audit row update.check).
     *
     * @return array{answer: ?array, wait: int} wait > 0: too soon, nothing was asked (the kept answer is returned)
     */
    public function refresh(): array
    {
        if (RateLimiter::tooManyAttempts(self::THROTTLE_KEY, 1)) {
            return ['answer' => $this->cached(), 'wait' => max(1, RateLimiter::availableIn(self::THROTTLE_KEY))];
        }

        RateLimiter::hit(self::THROTTLE_KEY, self::THROTTLE_SECONDS);
        $answer = $this->store($this->fetch(10));
        // One row per question to GitHub ("available 1.2.1 (api)", "error rate_limited (api)", "current 1.2.0 (web)").
        $detail = $answer['state'] === 'error' ? (string) ($answer['error']['code'] ?? '') : (string) ($answer['release']['version'] ?? '');
        AuditLogController::log('update.check', trim($answer['state'].' '.$detail).' ('.($answer['source'] ?? 'api').')');

        return ['answer' => $answer, 'wait' => 0];
    }

    /** Drop the kept answer (after an update: the version that asked is not the running one any more). */
    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
            Cache::forget(self::GOOD_KEY);
        } catch (Throwable) {
        }
    }

    /** Ask GitHub now. Never throws: what went wrong is the answer's error. */
    public function fetch(int $timeout = 10): array
    {
        $answer = ['state' => 'error', 'checked_at' => time(), 'release' => null, 'source' => 'api', 'error' => null];

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => self::userAgent(),
            ])
                ->connectTimeout(min(5, $timeout))
                ->timeout($timeout)
                ->get(AppVersion::API_BASE.'/repos/'.AppVersion::REPOSITORY.'/releases/latest');
        } catch (Throwable $e) {
            return array_merge($answer, ['error' => ['code' => self::connectionError($e)]]);
        }

        if ($response->status() === 404) {
            return ['state' => 'none'] + $answer;
        }

        if (self::rateLimited($response)) {
            $reset = self::resetTime($response);
            $tag = $this->webTag($timeout);

            if ($tag !== null) {
                return self::evaluate(['state' => 'current', 'release' => self::fromTag($tag), 'source' => 'web'] + $answer);
            }

            return array_merge($answer, ['error' => ['code' => 'rate_limited', 'reset' => $reset]]);
        }

        if (! $response->successful()) {
            return array_merge($answer, ['error' => ['code' => 'http', 'status' => $response->status()]]);
        }

        $json = $response->json();

        if (! is_array($json)) {
            return array_merge($answer, ['error' => ['code' => 'invalid']]);
        }

        $found = self::release($json);

        if ($found['error'] !== null) {
            return array_merge($answer, ['error' => ['code' => $found['error'], 'version' => $found['version']]]);
        }

        return self::evaluate(['state' => 'current', 'release' => $found['release']] + $answer);
    }

    /**
     * The update a release answer of the API offers. Ignored (release and error null): a draft, a pre-release, a tag
     * that is not plain "vX.Y.Z". Refused (error code): no update zip or no .sha256 asset (missing_asset), an asset
     * not uploaded yet (not_uploaded), a download address outside this repository's releases (foreign_host), a zip
     * above 300 MB (too_big).
     *
     * @return array{release: ?array, error: ?string, version: ?string}
     */
    public static function release(array $json): array
    {
        $version = AppVersion::fromTag(is_string($json['tag_name'] ?? null) ? $json['tag_name'] : null);

        if (($json['draft'] ?? false) !== false || ($json['prerelease'] ?? false) !== false || $version === null) {
            return ['release' => null, 'error' => null, 'version' => $version];
        }

        $zipName = AppVersion::updateAsset($version);
        $assets = [];

        foreach ((array) ($json['assets'] ?? []) as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null)) {
                $assets[$asset['name']] = $asset;
            }
        }

        $refuse = fn (string $code): array => ['release' => null, 'error' => $code, 'version' => $version];

        foreach ([$zipName, $zipName.'.sha256'] as $name) {
            if (! isset($assets[$name])) {
                return $refuse('missing_asset');
            }

            if (($assets[$name]['state'] ?? null) !== 'uploaded') {
                return $refuse('not_uploaded');
            }

            if (! is_string($assets[$name]['browser_download_url'] ?? null) || ! str_starts_with($assets[$name]['browser_download_url'], AppVersion::downloadBase($version))) {
                return $refuse('foreign_host');
            }
        }

        // Every asset of the release, not only the two the updater takes, must come from this repository's releases.
        foreach ($assets as $asset) {
            if (! is_string($asset['browser_download_url'] ?? null) || ! str_starts_with($asset['browser_download_url'], AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/download/')) {
                return $refuse('foreign_host');
            }
        }

        $size = is_int($assets[$zipName]['size'] ?? null) ? $assets[$zipName]['size'] : null;

        if ($size !== null && $size > self::MAX_ZIP_BYTES) {
            return $refuse('too_big');
        }

        $release = self::fromTag(AppVersion::tag($version));
        $release['name'] = is_string($json['name'] ?? null) && trim($json['name']) !== '' ? mb_substr(trim($json['name']), 0, 200) : $release['name'];
        $release['published_at'] = is_string($json['published_at'] ?? null) ? $json['published_at'] : null;
        $release['body'] = is_string($json['body'] ?? null) ? mb_substr($json['body'], 0, self::MAX_NOTES) : '';
        $release['zip']['size'] = $size;
        $release['zip']['digest'] = Checksum::fromDigest($assets[$zipName]['digest'] ?? null);

        return ['release' => $release, 'error' => null, 'version' => $version];
    }

    /** The release of a tag alone (the github.com fallback): asset addresses built from the tag, no size, no digest. */
    public static function fromTag(string $tag): array
    {
        $version = (string) AppVersion::fromTag($tag);
        $zip = AppVersion::updateAsset($version);

        return [
            'version' => $version,
            'tag' => AppVersion::tag($version),
            'name' => AppVersion::PRODUCT.' '.$version,
            'published_at' => null,
            'body' => '',
            'html_url' => AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/tag/'.AppVersion::tag($version),
            'zip' => ['name' => $zip, 'url' => AppVersion::downloadBase($version).$zip, 'size' => null, 'digest' => null],
            'sha256' => ['name' => $zip.'.sha256', 'url' => AppVersion::downloadBase($version).$zip.'.sha256'],
        ];
    }

    /** The answer in the administrator's language: the sentence that says where things stand, or null when all is said. */
    public static function message(?array $answer): ?string
    {
        if ($answer === null) {
            return null;
        }

        $error = (array) ($answer['error'] ?? []);
        $version = (string) ($error['version'] ?? '');

        return match ($answer['state'] === 'error' ? ($error['code'] ?? 'invalid') : $answer['state']) {
            'available' => null,
            'current' => __('Your site is up to date.'),
            'none' => __('No release found yet.'),
            'rate_limited' => __('GitHub has had too many requests from this server. Try again after :time.', [
                'time' => isset($error['reset']) ? Carbon::createFromTimestamp((int) $error['reset'])->setTimezone(config('app.timezone'))->translatedFormat('H:i') : __('an hour'),
            ]),
            'tls' => __('This server cannot check secure connections to GitHub. Ask your host to update the CA certificates.'),
            'timeout' => __('GitHub did not answer in time. Try again in a few minutes.'),
            'connection' => __('This server could not reach GitHub. Ask your host whether PHP may open https://api.github.com.'),
            'http' => __('GitHub answered with an error (:status). Try again later.', ['status' => (int) ($error['status'] ?? 0)]),
            'missing_asset', 'not_uploaded' => __('AVDBCMS :version is out, but its update package is not uploaded yet. Try again later.', ['version' => $version]),
            'foreign_host' => __('AVDBCMS :version points to a download outside its GitHub page, so it is not offered.', ['version' => $version]),
            'too_big' => __('The update package of AVDBCMS :version is larger than 300 MB, so it is not offered.', ['version' => $version]),
            default => __('GitHub sent an answer the updater cannot read. Try again later.'),
        };
    }

    public static function userAgent(): string
    {
        return 'AVDBCMS-Updater/'.AppVersion::CURRENT;
    }

    /** A kept answer measured against the running version (after an update the same release is "current"). */
    private static function evaluate(array $answer): array
    {
        if (in_array($answer['state'] ?? null, ['available', 'current'], true)) {
            $answer['state'] = AppVersion::isNewer($answer['release']['version'] ?? null) ? 'available' : 'current';
        }

        return $answer;
    }

    private function store(array $answer): array
    {
        try {
            if ($answer['state'] === 'error') {
                $good = Cache::get(self::GOOD_KEY);
                $answer['last_good'] = is_array($good) ? ($good['release'] ?? null) : null;
                Cache::put(self::CACHE_KEY, $answer, self::FAILURE_SECONDS);
            } else {
                Cache::put(self::CACHE_KEY, $answer, self::KEEP_SECONDS);

                if ($answer['release'] !== null) {
                    Cache::put(self::GOOD_KEY, $answer, self::GOOD_SECONDS);
                }
            }
        } catch (Throwable) {
            // No cache store: the next page asks again.
        }

        return self::evaluate($answer);
    }

    /** The tag github.com/<repo>/releases/latest redirects to (".../releases/tag/vX.Y.Z"), or null. */
    private function webTag(int $timeout): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::userAgent()])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(min(5, $timeout))
                ->timeout($timeout)
                ->get(AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/latest');
        } catch (Throwable) {
            return null;
        }

        $location = (string) $response->header('Location');
        $prefix = AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/tag/';

        if (! in_array($response->status(), [301, 302, 303, 307, 308], true) || ! str_starts_with($location, $prefix)) {
            return null;
        }

        $tag = rawurldecode(substr($location, strlen($prefix)));

        return AppVersion::fromTag($tag) !== null ? $tag : null;
    }

    private static function rateLimited(Response $response): bool
    {
        return in_array($response->status(), [403, 429], true)
            && ($response->header('x-ratelimit-remaining') === '0' || $response->header('retry-after') !== '');
    }

    private static function resetTime(Response $response): int
    {
        $reset = (int) $response->header('x-ratelimit-reset');
        $retry = (int) $response->header('retry-after');

        return $reset > time() ? $reset : time() + max(60, $retry);
    }

    /** tls: the host cannot check GitHub's certificate; timeout; connection: anything else on the way. */
    private static function connectionError(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'curl error 60'), str_contains($message, 'curl error 77'), str_contains($message, 'ssl certificate'),
            str_contains($message, 'certificate verify'), str_contains($message, 'local issuer') => 'tls',
            str_contains($message, 'curl error 28'), str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'timeout',
            default => 'connection',
        };
    }
}
