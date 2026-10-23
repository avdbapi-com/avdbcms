<?php

namespace Tests\Feature\Updater\Support;

use App\Support\AppVersion;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * GitHub for updater tests (Http::fake): the API's latest release of AppVersion::REPOSITORY and the two update assets
 * of that release on github.com, the zip with Range support (206 slices, as GitHub's asset store answers). Every
 * other address answers 404. $requests lists what was asked (method, address, Range).
 */
final class FakeGitHub
{
    /** @var list<array{method: string, url: string, range: string}> */
    public array $requests = [];

    /** Answer the zip with 200 and the whole file, whatever Range asked (a server that ignores ranges). */
    public bool $ignoreRange = false;

    /** The .sha256 asset's text (null: the right "<hex>  <name>" line). */
    public ?string $sha256Text = null;

    /** The API's digest of the zip (null: the right one; '' : none). */
    public ?string $digest = null;

    public readonly string $bytes;

    public function __construct(public readonly string $version, string $zipFile)
    {
        $this->bytes = (string) file_get_contents($zipFile);
    }

    public function zipName(): string
    {
        return AppVersion::updateAsset($this->version);
    }

    public function hash(): string
    {
        return hash('sha256', $this->bytes);
    }

    /** The API answer for releases/latest. */
    public function releaseJson(): array
    {
        $base = AppVersion::downloadBase($this->version);
        $digest = $this->digest ?? 'sha256:'.$this->hash();
        $zip = ['name' => $this->zipName(), 'size' => strlen($this->bytes), 'state' => 'uploaded', 'content_type' => 'application/zip',
            'browser_download_url' => $base.$this->zipName()] + ($digest !== '' ? ['digest' => $digest] : []);

        return [
            'tag_name' => AppVersion::tag($this->version),
            'name' => 'AVDBCMS '.$this->version,
            'draft' => false,
            'prerelease' => false,
            'immutable' => false,
            'created_at' => '2026-10-24T09:00:00Z',
            'published_at' => '2026-10-24T10:00:00Z',
            'body' => "Fixes.\n<script>alert(1)</script>",
            'assets' => [
                $zip,
                ['name' => $this->zipName().'.sha256', 'size' => 92, 'state' => 'uploaded', 'browser_download_url' => $base.$this->zipName().'.sha256'],
                ['name' => 'avdbcms-'.$this->version.'.zip', 'size' => strlen($this->bytes) + 1000, 'state' => 'uploaded', 'browser_download_url' => $base.'avdbcms-'.$this->version.'.zip'],
                ['name' => 'avdbcms-'.$this->version.'.zip.sha256', 'size' => 85, 'state' => 'uploaded', 'browser_download_url' => $base.'avdbcms-'.$this->version.'.zip.sha256'],
            ],
        ];
    }

    public function install(): self
    {
        Http::fake(fn (Request $request) => $this->answer($request));

        return $this;
    }

    private function answer(Request $request)
    {
        $url = $request->url();
        $range = (string) ($request->header('Range')[0] ?? '');
        $this->requests[] = ['method' => $request->method(), 'url' => $url, 'range' => $range];
        $base = AppVersion::downloadBase($this->version);

        if ($url === AppVersion::API_BASE.'/repos/'.AppVersion::REPOSITORY.'/releases/latest') {
            return Http::response($this->releaseJson(), 200, ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '59']);
        }

        if ($url === $base.$this->zipName().'.sha256') {
            return Http::response($this->sha256Text ?? $this->hash().'  '.$this->zipName()."\n", 200);
        }

        if ($url !== $base.$this->zipName()) {
            return Http::response('Not Found', 404);
        }

        $total = strlen($this->bytes);

        if ($this->ignoreRange || preg_match('/^bytes=(\d+)-(\d*)$/', $range, $match) !== 1) {
            return Http::response($this->bytes, 200, ['Content-Type' => 'application/octet-stream']);
        }

        $start = (int) $match[1];
        $end = $match[2] === '' ? $total - 1 : min((int) $match[2], $total - 1);

        if ($start >= $total) {
            return Http::response('', 416, ['Content-Range' => 'bytes */'.$total]);
        }

        return Http::response(substr($this->bytes, $start, $end - $start + 1), 206, [
            'Content-Type' => 'application/octet-stream',
            'Content-Range' => 'bytes '.$start.'-'.$end.'/'.$total,
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
