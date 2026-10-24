<?php

namespace App\Support\Updater;

use App\Support\AppVersion;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Throwable;

/**
 * Downloads an update package in short slices (one per request of the Updates page): "Range: bytes=<size of the
 * .part>-..." from the release's github.com address, which GitHub redirects to its asset store. The redirect target is
 * a signed address that expires within the hour, so every slice starts from the github.com address again and the
 * signed one is never stored or logged. Only HTTPS and GitHub's hosts (AppVersion::DOWNLOAD_HOSTS) are followed.
 *
 * A slice counts only when the answer is 206 and its Content-Range starts where the file ends; a 200 (the server
 * ignored the range) starts the file again from 0; a total that differs from the release's size stops. The slice size
 * follows the measured speed (about 15 seconds of data, 1-16 MB), so no request comes near a 30-second PHP limit or
 * Cloudflare's 125 seconds.
 */
final class Downloader
{
    public const FIRST_SLICE = 4 * 1024 * 1024;

    /** The smallest slice: 256 KB in TIMEOUT seconds still gets through at about 10 KB/s. */
    public const MIN_SLICE = 256 * 1024;

    public const MAX_SLICE = 16 * 1024 * 1024;

    private const SLICE_SECONDS = 15;

    private const TIMEOUT = 25;

    /** The SHA-256 in the release's .sha256 asset (a short sha256sum line). */
    public function checksum(array $release): string
    {
        $asset = (array) ($release['sha256'] ?? []);
        $url = (string) ($asset['url'] ?? '');
        $expected = self::assetUrl($release, '.sha256');

        // Only this release's own address (AppVersion's repository, its tag, the fixed name): never one from stored state.
        if ($expected === null || ! hash_equals($expected, $url)) {
            throw new UpdateException(__('The release has no valid checksum file, so it cannot be checked. Try again later.'), 'download', true, 'checksum address is not the release asset');
        }

        try {
            $response = $this->client()->timeout(20)->get($url);
        } catch (Throwable $e) {
            throw $this->connectionFailed($e);
        }

        if (! $response->successful()) {
            throw $this->httpFailed($response);
        }

        $hash = Checksum::parse($response->body(), AppVersion::updateAsset((string) $release['version']));

        if ($hash === null) {
            throw new UpdateException(__('The release has no valid checksum file, so it cannot be checked. Try again later.'), 'download', true, 'invalid .sha256 asset');
        }

        return $hash;
    }

    /**
     * The next slice of the zip, appended to $part.
     *
     * @return array{done: int, total: int, complete: bool, slice: int}
     */
    public function slice(array $release, string $part, int $slice, ?int $total): array
    {
        $url = (string) ($release['zip']['url'] ?? '');
        $expected = is_int($release['zip']['size'] ?? null) ? $release['zip']['size'] : $total;
        $address = self::assetUrl($release);

        // The zip of this release only (a stored answer or plan that names another repository is not followed).
        if ($address === null || ! hash_equals($address, $url)) {
            throw new UpdateException(__('The download does not match the release. Try again later.'), 'download', false, 'download address is not the release asset');
        }

        clearstatcache(true, $part);
        $start = is_file($part) ? (int) filesize($part) : 0;

        if ($expected !== null && $start === $expected) {
            return ['done' => $start, 'total' => $expected, 'complete' => true, 'slice' => $slice];
        }

        if ($expected !== null && $start > $expected) {
            // Longer than the release: not this file. The next try starts again from the first byte.
            @unlink($part);

            throw new UpdateException(__('The download does not match the release. Try again later.'), 'download', true, 'the .part file is longer than the release');
        }

        $slice = max(self::MIN_SLICE, min(self::MAX_SLICE, $slice));
        $started = microtime(true);

        try {
            $response = $this->client()
                ->withHeaders(['Range' => 'bytes='.$start.'-'.($start + $slice - 1)])
                ->timeout(self::TIMEOUT)
                ->get($url);
        } catch (Throwable $e) {
            // Too slow for this slice (or cut off): the caller asks for less next time (smaller()).
            throw $this->connectionFailed($e);
        }

        $body = $response->body();
        $status = $response->status();

        if ($status === 206) {
            if (preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', trim($response->header('Content-Range')), $range) !== 1
                || (int) $range[1] !== $start || (int) $range[2] < (int) $range[1] || strlen($body) !== (int) $range[2] - (int) $range[1] + 1) {
                throw new UpdateException(__('The download does not match the release. Try again later.'), 'download', true, 'Content-Range does not continue the file');
            }

            $answerTotal = (int) $range[3];
            $this->checkTotal($answerTotal, $expected, (string) ($release['version'] ?? ''));
            $this->write($part, $body, $start === 0 ? 'wb' : 'ab');
            $done = $start + strlen($body);
        } elseif ($status === 200) {
            // The server ignored the range: the whole file came, from its first byte.
            $answerTotal = strlen($body);
            $this->checkTotal($answerTotal, $expected, (string) ($release['version'] ?? ''));
            $this->write($part, $body, 'wb');
            $done = $answerTotal;
        } elseif ($status === 416) {
            // Nothing to send from where the file ends. The server names its length ("bytes */<total>"): when the file
            // is exactly that long it is complete (its SHA-256 is checked next), else it is not this file: start again.
            $length = preg_match('#^bytes \*/(\d+)$#', trim((string) $response->header('Content-Range')), $match) === 1 ? (int) $match[1] : null;

            if ($start > 0 && $start === ($expected ?? $length)) {
                $this->checkTotal($start, $expected, (string) ($release['version'] ?? ''));

                return ['done' => $start, 'total' => $start, 'complete' => true, 'slice' => $slice];
            }

            @unlink($part);

            throw new UpdateException(__('The download does not match the release. Try again later.'), 'download', true, 'range not satisfiable');
        } else {
            throw $this->httpFailed($response);
        }

        $seconds = max(0.001, microtime(true) - $started);
        $next = (int) min(self::MAX_SLICE, max(self::MIN_SLICE, strlen($body) / $seconds * self::SLICE_SECONDS));

        return ['done' => $done, 'total' => $answerTotal, 'complete' => $done === $answerTotal, 'slice' => $next];
    }

    /**
     * The slice to ask for after one that did not arrive (a timeout on a slow link, a dropped connection): a quarter,
     * at least MIN_SLICE. A slow link would otherwise ask for the same too-big slice forever; once slices come through,
     * their size follows the measured speed again.
     */
    public static function smaller(int $slice): int
    {
        return max(self::MIN_SLICE, intdiv(max(self::MIN_SLICE, $slice), 4));
    }

    /**
     * The one address an asset of a release may be downloaded from: built from its version (AppVersion's repository,
     * the tag, the fixed asset name), or null when the release names no plain version.
     */
    public static function assetUrl(array $release, string $suffix = ''): ?string
    {
        $version = $release['version'] ?? null;

        return is_string($version) && AppVersion::isVersion($version) ? AppVersion::downloadBase($version).AppVersion::updateAsset($version).$suffix : null;
    }

    /** Only HTTPS addresses on GitHub's release hosts (the redirects of a download). */
    public static function allowed(string $url): bool
    {
        foreach (AppVersion::DOWNLOAD_HOSTS as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => ReleaseCheck::userAgent(), 'Accept' => 'application/octet-stream'])
            ->connectTimeout(10)
            ->withOptions([
                'decode_content' => false,
                'allow_redirects' => [
                    'max' => 5,
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['https'],
                    'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                        if (! self::allowed((string) $uri)) {
                            throw new RuntimeException('Redirected outside GitHub: '.$uri->getHost());
                        }
                    },
                ],
                // A slice that gets under 1 KB/s for 30 seconds stops (the next one asks for less).
                'curl' => defined('CURLOPT_LOW_SPEED_LIMIT') ? [CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 30] : [],
            ]);
    }

    private function checkTotal(int $total, ?int $expected, string $version): void
    {
        if ($total > ReleaseCheck::MAX_ZIP_BYTES) {
            throw new UpdateException(__('The update package of AVDBCMS :version is larger than 300 MB, so it is not offered.', ['version' => $version]), 'download', false, 'total above 300 MB');
        }

        if ($expected !== null && $total !== $expected) {
            throw new UpdateException(__('The download does not match the release. Try again later.'), 'download', true, 'size '.$total.' instead of '.$expected);
        }
    }

    private function write(string $part, string $body, string $mode): void
    {
        $folder = dirname($part);

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new UpdateException(__('Could not write the download. The disk may be full. Nothing on your site was changed.'), 'download', true, 'cannot create '.basename($folder));
        }

        $handle = @fopen($part, $mode);

        if ($handle === false) {
            throw new UpdateException(__('Could not write the download. The disk may be full. Nothing on your site was changed.'), 'download', true, 'cannot open the .part file');
        }

        try {
            $written = $body === '' ? 0 : @fwrite($handle, $body);

            if ($written !== strlen($body) || ! @fflush($handle)) {
                throw new UpdateException(__('Could not write the download. The disk may be full. Nothing on your site was changed.'), 'download', true, 'short write');
            }
        } finally {
            fclose($handle);
        }
    }

    private function connectionFailed(Throwable $e): UpdateException
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'ssl certificate') || str_contains($message, 'curl error 60') || str_contains($message, 'local issuer')) {
            return new UpdateException(__('This server cannot check secure connections to GitHub. Ask your host to update the CA certificates.'), 'download', false, 'tls');
        }

        return new UpdateException(__('The download from GitHub was interrupted. Try again: it continues where it stopped.'), 'download', true,
            str_contains($message, 'outside github') ? 'redirect outside GitHub' : 'connection: '.mb_substr(self::mask($e->getMessage()), 0, 160));
    }

    /**
     * Every address in a client error becomes "<address>" (a signed download address must never reach the step log).
     * The address ends where the text goes on: punctuation right after it that closes a bracket or the sentence (the
     * ")" of "(see https://...)") stays in the text.
     */
    public static function mask(string $text): string
    {
        return (string) preg_replace('~https?://\S+?(?=[)\]}>.,;:!?\'"]*(?:\s|\z))~i', '<address>', $text);
    }

    private function httpFailed(Response $response): UpdateException
    {
        return new UpdateException(__('GitHub answered with an error (:status). Try again later.', ['status' => $response->status()]), 'download', true, 'HTTP '.$response->status());
    }
}
