<?php

namespace Tests\Feature\Updater\Support;

use App\Support\AppVersion;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * GitHub for the updater's feature tests (Http::fake), answering the way GitHub does (F8 4, 6, 7):
 *
 *  - api.github.com/repos/<repo>/releases/latest: the release JSON with both update assets (or $api, or a dropped
 *    connection with $apiFailure);
 *  - github.com/<repo>/releases/latest: 302 to releases/tag/<tag> when $webLocation is set (the no-API fallback),
 *    else 404;
 *  - github.com/<repo>/releases/download/<tag>/<asset>: 302 to a signed address on release-assets.githubusercontent.com
 *    (an expiry and a signature in its query, like GitHub's), which serves the asset with Range support (206).
 *
 * Switches make it misbehave the ways the updater has to cope with: a server that ignores Range, a Content-Range that
 * does not continue the file, a total that is not the release's, a dropped connection, an error status, a redirect
 * away from GitHub, a byte changed on the way, a wrong checksum or digest. Every request is listed in $requests with
 * its Range header and the client options it was sent with, so a test sees what was asked and what was not.
 */
final class ReleaseServer
{
    public const ASSET_HOST = 'https://release-assets.githubusercontent.com';

    /** The signature in the query of every signed asset address: it must never reach a log line. */
    public const SIGNATURE = 'c2lnbmVkLWJ5LWdpdGh1Yi1mb3ItdGVzdHM9PQ';

    /** @var list<array{method: string, url: string, range: string, headers: array<string, list<string>>, options: array<string, mixed>}> */
    public array $requests = [];

    /** The API's answer instead of the release: [status, body, headers]. */
    public ?array $api = null;

    /** The API call gets no answer: the client error message (e.g. "cURL error 28: Operation timed out ..."). */
    public ?string $apiFailure = null;

    /** Where github.com/<repo>/releases/latest redirects (null: 404, so no fallback). */
    public ?string $webLocation = null;

    /** The tag the API names (default: "v" + the version). */
    public string $tag;

    public bool $draft = false;

    public bool $prerelease = false;

    /** The API's digest of the update zip: null = the right one, '' = none, else this text. */
    public ?string $digest = null;

    /** The text of the .sha256 asset: null = the right "<hex>  <name>" line. */
    public ?string $sha256Text = null;

    /** The size the API reports for the update zip (null: the real one). */
    public ?int $advertisedSize = null;

    /** Answer every zip request with 200 and the whole file (a server that ignores Range). */
    public bool $ignoreRange = false;

    /** Content-Range starts this many bytes after what Range asked for (and the bytes come from there). */
    public int $gap = 0;

    /** The total a 206 names in Content-Range (null: the real size). */
    public ?int $rangeTotal = null;

    /** Zip requests on the asset store (counted from 1) that get no answer: the connection drops. */
    public array $dropRequests = [];

    /** The asset store answers the zip with this status (e.g. 500) instead of its bytes. */
    public ?int $assetStatus = null;

    /** Where the github.com address of the update zip redirects instead of the signed address. */
    public ?string $assetLocation = null;

    /** Change the byte at this offset on the way (the size stays the same). */
    public ?int $flipByte = null;

    public readonly string $bytes;

    private int $zipRequests = 0;

    public function __construct(public readonly string $version, string $zipFile)
    {
        $this->bytes = (string) file_get_contents($zipFile);
        $this->tag = AppVersion::tag($version);
    }

    public function install(): self
    {
        Http::fake(fn (Request $request, array $options) => $this->answer($request, $options));

        return $this;
    }

    public function zipName(): string
    {
        return AppVersion::updateAsset($this->version);
    }

    public function hash(): string
    {
        return hash('sha256', $this->bytes);
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }

    /** The github.com address of an asset of this release (where every download starts). */
    public function assetUrl(string $asset): string
    {
        return AppVersion::downloadBase($this->version).$asset;
    }

    /** The signed address the asset store serves an asset under (the redirect target of assetUrl()). */
    public function signedUrl(string $asset): string
    {
        return self::ASSET_HOST.'/github-production-release-asset/700000001/'.substr(hash('sha256', $asset), 0, 32)
            .'?sp=r&sv=2018-11-09&sr=b&spr=https&se=2026-10-23T14%3A39%3A00Z&rscd=attachment%3B+filename%3D'.rawurlencode($asset)
            .'&rsct=application%2Foctet-stream&sig='.self::SIGNATURE;
    }

    /** The API's latest release: the update zip and its .sha256, plus the full package and its .sha256. */
    public function releaseJson(): array
    {
        $zip = ['name' => $this->zipName(), 'size' => $this->advertisedSize ?? $this->size(), 'state' => 'uploaded',
            'content_type' => 'application/zip', 'browser_download_url' => $this->assetUrl($this->zipName())];
        $digest = $this->digest ?? 'sha256:'.$this->hash();

        if ($digest !== '') {
            $zip['digest'] = $digest;
        }

        return [
            'tag_name' => $this->tag,
            'name' => 'AVDBCMS '.$this->version,
            'draft' => $this->draft,
            'prerelease' => $this->prerelease,
            'immutable' => false,
            'created_at' => '2026-10-24T09:00:00Z',
            'published_at' => '2026-10-24T10:00:00Z',
            'body' => "Fixes and small improvements.\n",
            'assets' => [
                $zip,
                ['name' => $this->zipName().'.sha256', 'size' => 92, 'state' => 'uploaded', 'content_type' => 'text/plain',
                    'browser_download_url' => $this->assetUrl($this->zipName().'.sha256')],
                ['name' => 'avdbcms-'.$this->version.'.zip', 'size' => $this->size() + 4096, 'state' => 'uploaded', 'content_type' => 'application/zip',
                    'browser_download_url' => $this->assetUrl('avdbcms-'.$this->version.'.zip')],
                ['name' => 'avdbcms-'.$this->version.'.zip.sha256', 'size' => 85, 'state' => 'uploaded', 'content_type' => 'text/plain',
                    'browser_download_url' => $this->assetUrl('avdbcms-'.$this->version.'.zip.sha256')],
            ],
        ];
    }

    /**
     * The requests whose address starts with $prefix.
     *
     * @return list<array{method: string, url: string, range: string, headers: array<string, list<string>>, options: array<string, mixed>}>
     */
    public function requestsTo(string $prefix): array
    {
        return array_values(array_filter($this->requests, fn (array $request): bool => str_starts_with($request['url'], $prefix)));
    }

    /** @return list<string> the Range headers of the zip requests that reached the asset store */
    public function zipRanges(): array
    {
        return array_column($this->requestsTo($this->signedUrl($this->zipName())), 'range');
    }

    private function answer(Request $request, array $options)
    {
        $url = $request->url();
        $range = (string) ($request->header('Range')[0] ?? '');
        $this->requests[] = [
            'method' => $request->method(),
            'url' => $url,
            'range' => $range,
            'headers' => $request->headers(),
            'options' => array_intersect_key($options, array_flip(['timeout', 'connect_timeout', 'verify', 'allow_redirects'])),
        ];

        if ($url === AppVersion::API_BASE.'/repos/'.AppVersion::REPOSITORY.'/releases/latest') {
            if ($this->apiFailure !== null) {
                return Http::failedConnection($this->apiFailure)($request);
            }

            if ($this->api !== null) {
                return Http::response($this->api[1] ?? '', (int) $this->api[0], (array) ($this->api[2] ?? []));
            }

            return Http::response($this->releaseJson(), 200, ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '58', 'X-RateLimit-Resource' => 'core']);
        }

        if ($url === AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/latest') {
            return $this->webLocation !== null ? Http::response('', 302, ['Location' => $this->webLocation]) : Http::response('Not Found', 404);
        }

        foreach ([$this->zipName(), $this->zipName().'.sha256'] as $asset) {
            if ($url === $this->assetUrl($asset)) {
                $location = $asset === $this->zipName() && $this->assetLocation !== null ? $this->assetLocation : $this->signedUrl($asset);

                return Http::response('', 302, ['Location' => $location]);
            }

            if ($url === $this->signedUrl($asset)) {
                return $asset === $this->zipName()
                    ? $this->zip($request, $range)
                    : Http::response($this->sha256Text ?? $this->hash().'  '.$this->zipName()."\n", 200, ['Content-Type' => 'application/octet-stream']);
            }
        }

        return Http::response('Not Found', 404);
    }

    /** The asset store's answer for the update zip. */
    private function zip(Request $request, string $range)
    {
        $this->zipRequests++;

        if (in_array($this->zipRequests, $this->dropRequests, true)) {
            // Laravel's own wording of a dropped connection quotes the full (signed) address.
            return Http::failedConnection('cURL error 56: Recv failure: Connection was reset for '.$request->url())($request);
        }

        if ($this->assetStatus !== null) {
            return Http::response('<?xml version="1.0" encoding="utf-8"?><Error><Code>ServerBusy</Code></Error>', $this->assetStatus, ['Content-Type' => 'application/xml']);
        }

        $bytes = $this->bytes;

        if ($this->flipByte !== null) {
            $bytes[$this->flipByte] = chr(ord($bytes[$this->flipByte]) ^ 0xFF);
        }

        $total = strlen($bytes);

        if ($this->ignoreRange || preg_match('/^bytes=(\d+)-(\d*)$/', $range, $match) !== 1) {
            return Http::response($bytes, 200, ['Content-Type' => 'application/octet-stream']);
        }

        $start = (int) $match[1] + $this->gap;
        $end = $match[2] === '' ? $total - 1 : min((int) $match[2] + $this->gap, $total - 1);

        if ($start >= $total) {
            return Http::response('', 416, ['Content-Range' => 'bytes */'.$total]);
        }

        return Http::response(substr($bytes, $start, $end - $start + 1), 206, [
            'Content-Type' => 'application/octet-stream',
            'Content-Range' => 'bytes '.$start.'-'.$end.'/'.($this->rangeTotal ?? $total),
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
