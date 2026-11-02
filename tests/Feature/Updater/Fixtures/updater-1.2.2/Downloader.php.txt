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
 *
 * Every answer goes to disk as it arrives, never into memory: a proxy that ignores Range sends the whole zip (about
 * 70 MB) in one answer, more than a 128 MB memory_limit may hold next to Laravel (see fetch()).
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
        $answer = $this->fetch($this->client(), $url, $part, $start, $slice, $expected, (string) ($release['version'] ?? ''));

        if ($answer['status'] === 416) {
            return ['done' => $start, 'total' => $start, 'complete' => true, 'slice' => $slice];
        }

        $seconds = max(0.001, microtime(true) - $started);
        $next = (int) min(self::MAX_SLICE, max(self::MIN_SLICE, $answer['bytes'] / $seconds * self::SLICE_SECONDS));

        return ['done' => $answer['done'], 'total' => $answer['total'], 'complete' => $answer['done'] === $answer['total'], 'slice' => $next];
    }

    /**
     * One request for the zip from byte $start on (Range: bytes=$start-<$start + $slice - 1>), its answer streamed to disk
     * as it arrives and never held in memory. The bytes land in <part>.slice: a 206 that continues the file is then
     * appended to $part, a 200 (the server or a proxy ignored the range: the whole file, from its first byte) replaces
     * $part. A redirect's body, an error page or an answer that does not fit never reaches $part, and the landing file
     * goes in every case. The landing file is a file name, not an open handle: the HTTP client opens it afresh (empty)
     * for each answer of the request, so only the last answer's body is in it after a redirect. Where the transfer
     * reports an answer's headers first (curl: on_headers), one that cannot fit stops before its body comes; the same
     * checks run again on the answer as it ended.
     *
     * @return array{status: int, bytes: int, done: int, total: int}
     */
    private function fetch(PendingRequest $client, string $url, string $part, int $start, int $slice, ?int $expected, string $version): array
    {
        $folder = dirname($part);

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new UpdateException(__('Could not write the download. The disk may be full. Nothing on your site was changed.'), 'download', true, 'cannot create '.basename($folder));
        }

        // The .part file must take the bytes (checked before anything is fetched; a .part that was not there stays away).
        $existed = file_exists($part);
        $probe = @fopen($part, 'ab');

        if ($probe === false) {
            throw new UpdateException(__('Could not write the download. The disk may be full. Nothing on your site was changed.'), 'download', true, 'cannot open the .part file');
        }

        fclose($probe);

        if (! $existed) {
            @unlink($part);
        }

        $landing = $part.'.slice';
        @unlink($landing);

        /** @var UpdateException|null $refused why on_headers stopped the answer */
        $refused = null;
        $response = null;

        try {
            try {
                $response = $client
                    ->withHeaders(['Range' => 'bytes='.$start.'-'.($start + $slice - 1)])
                    ->timeout(self::TIMEOUT)
                    ->withOptions([
                        'sink' => $landing,
                        'on_headers' => function (ResponseInterface $answer) use ($start, $expected, $version, &$refused): void {
                            $refused = $this->refusal($answer->getStatusCode(), $answer->getHeaderLine('Content-Range'), $answer->getHeaderLine('Content-Length'), $start, $expected, $version);

                            if ($refused !== null) {
                                throw new RuntimeException('stopped: '.$refused->detail);
                            }
                        },
                    ])
                    ->get($url);
            } catch (Throwable $e) {
                // Too slow for this slice (or cut off): the caller asks for less next time (smaller()). The error (and
                // the answers it holds, with the landing file open) is not kept.
                $failure = $refused ?? $this->connectionFailed($e);
                unset($e);

                throw $failure;
            }

            // The answer's body is the landing file (open for writing): closed before the file is read or moved.
            $response->toPsrResponse()->getBody()->close();
            $status = $response->status();

            if ($status === 416) {
                // Nothing to send from where the file ends. The server names its length ("bytes */<total>"): when the
                // file is exactly that long it is complete (its SHA-256 is checked next), else it is not this file.
                $length = preg_match('#^bytes \*/(\d+)$#', trim((string) $response->header('Content-Range')), $match) === 1 ? (int) $match[1] : null;

                if ($start > 0 && $start === ($expected ?? $length)) {
                    $this->checkTotal($start, $expected, $version);

                    return ['status' => 416, 'bytes' => 0, 'done' => $start, 'total' => $start];
                }

                @unlink($part);

                throw new UpdateException(__('The download does not match the release. Try again later.'), 'download', true, 'range not satisfiable');
            }

            if ($status !== 200 && $status !== 206) {
                throw $this->httpFailed($response);
            }

            if (($why = $this->refusal($status, (string) $response->header('Content-Range'), (string) $response->header('Content-Length'), $start, $expected, $version)) !== null) {
                throw $why;
            }

            clearstatcache(true, $landing);
            $bytes = is_file($landing) ? (int) filesize($landing) : 0;

            if ($status === 206) {
                preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', trim((string) $response->header('Content-Range')), $range);

                if ($bytes !== (int) $range[2] - (int) $range[1] + 1) {
                    throw new UpdateException(__('The download does not match the release. Try again later.'), 'download', true, 'Content-Range does not continue the file');
                }

                $this->keep($landing, $part, $start > 0, $bytes);

                return ['status' => 206, 'bytes' => $bytes, 'done' => $start + $bytes, 'total' => (int) $range[3]];
            }

            // 200: the server ignored the range and sent the whole file, from its first byte.
            $this->checkTotal($bytes, $expected, $version);
            $this->keep($landing, $part, false, $bytes);

            return ['status' => 200, 'bytes' => $bytes, 'done' => $bytes, 'total' => $bytes];
        } finally {
            if ($response !== null) {
                $response->toPsrResponse()->getBody()->close();
            }

            $response = null;

            if (is_file($landing)) {
                @unlink($landing);
            }
        }
    }

    /**
     * Why an answer cannot go into the download (null: it can), from its status line and headers: a 206 must continue
     * the file (Content-Range starts at $start) and name the release's size; a 200 that names its length must be that
     * size. Checked as soon as the headers arrive and again when the answer ended.
     */
    private function refusal(int $status, string $contentRange, string $contentLength, int $start, ?int $expected, string $version): ?UpdateException
    {
        if ($status === 206) {
            if (preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', trim($contentRange), $range) !== 1 || (int) $range[1] !== $start || (int) $range[2] < (int) $range[1]) {
                return new UpdateException(__('The download does not match the release. Try again later.'), 'download', true, 'Content-Range does not continue the file');
            }

            return $this->totalProblem((int) $range[3], $expected, $version);
        }

        $length = trim($contentLength);

        return $status === 200 && $length !== '' && ctype_digit($length) ? $this->totalProblem((int) $length, $expected, $version) : null;
    }

    /**
     * The landing file of a slice that fits into the download: appended to $part (the bytes after the ones it has, copied
     * from disk to disk), or, for the first slice and for a whole file, $part is replaced by it in one rename.
     */
    private function keep(string $landing, string $part, bool $append, int $bytes): void
    {
        if ($append) {
            $in = @fopen($landing, 'rb');
            $out = $in !== false ? @fopen($part, 'ab') : false;
            $copied = false;
            $flushed = false;

            if ($out !== false) {
                $copied = @stream_copy_to_stream($in, $out);
                $flushed = @fflush($out);
                fclose($out);
            }

            if ($in !== false) {
                fclose($in);
            }

            if ($copied !== $bytes || ! $flushed) {
                throw new UpdateException(__('Could not write the download. The disk may be full. Nothing on your site was changed.'), 'download', true, 'short write');
            }

            return;
        }

        if (! @rename($landing, $part)) {
            throw new UpdateException(__('Could not write the download. The disk may be full. Nothing on your site was changed.'), 'download', true, 'rename of the .slice file');
        }
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
        if (($why = $this->totalProblem($total, $expected, $version)) !== null) {
            throw $why;
        }
    }

    private function totalProblem(int $total, ?int $expected, string $version): ?UpdateException
    {
        if ($total > ReleaseCheck::MAX_ZIP_BYTES) {
            return new UpdateException(__('The update package of AVDBCMS :version is larger than 300 MB, so it is not offered.', ['version' => $version]), 'download', false, 'total above 300 MB');
        }

        if ($expected !== null && $total !== $expected) {
            return new UpdateException(__('The download does not match the release. Try again later.'), 'download', true, 'size '.$total.' instead of '.$expected);
        }

        return null;
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
