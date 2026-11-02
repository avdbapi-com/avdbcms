<?php

namespace App\Services;

use App\Support\DurationParser;
use App\Models\Setting;
use App\Support\Upload18\Upload18Item;
use App\Support\Upload18\Upload18Unavailable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The site's calls to the Upload18 API (V2.1). The key goes in the Authorization: Bearer header of every call, never in
 * an address or a form field, so it cannot end up in an access log, a proxy log or a browser. Browsers never get the
 * key: they get a short-lived upload token (mintUploadToken(), App\Support\Upload18\BrowserUploadKey).
 */
class Upload18Service
{
    private string $apiKey;
    private string $apiUrl;
    private string $defaultCid;
    private string $defaultMycid;
    private string $defaultFid;

    public function __construct()
    {
        $uploads = Setting::getGroup('uploads');

        $this->apiKey = trim((string) ($uploads['upload18_api_key'] ?? config('services.upload18.api_key', '')));
        $this->apiUrl = rtrim((string) ($uploads['upload18_api_url'] ?? config('services.upload18.api_url', 'https://upload18.org/api')), '/');
        $this->defaultCid = (string) ($uploads['upload18_default_cid'] ?? config('services.upload18.default_cid', '15'));
        $this->defaultMycid = (string) ($uploads['upload18_default_mycid'] ?? config('services.upload18.default_mycid', '0'));
        $this->defaultFid = (string) ($uploads['upload18_default_fid'] ?? config('services.upload18.default_fid', '18'));
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== '';
    }

    /** The saved key (server side only: the browser gets an upload token instead). */
    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function apiUrl(): string
    {
        return $this->apiUrl;
    }

    /**
     * Upload a video file to Upload18.
     * Supports queue/retry when server responds with "please wait".
     *
     * @param string $filePath Absolute path to the video file
     * @param string $title Video title
     * @param array $options Additional options (cid, mycid, fid)
     * @return array Response data with vid, embed hash, etc.
     * @throws \Exception
     */
    public function upload(string $filePath, string $title = '', array $options = []): array
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $cid = $options['cid'] ?? $this->defaultCid;
        $mycid = $options['mycid'] ?? $this->defaultMycid;
        $fid = $options['fid'] ?? $this->defaultFid;

        $maxRetries = 30;
        $waitSeconds = 20;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            Log::info("Upload18: Upload attempt {$attempt}/{$maxRetries} for {$filePath}");

            $handle = fopen($filePath, 'rb');

            if ($handle === false) {
                throw new \Exception("Could not open file for upload: {$filePath}");
            }

            try {
                $response = $this->client(600)
                    ->attach('video', $handle, basename($filePath))
                    ->post("{$this->apiUrl}/upload", [
                        'cid' => $cid,
                        'mycid' => $mycid,
                        'fid' => $fid,
                    ]);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            if (!$response->successful()) {
                Log::error("Upload18: HTTP error {$response->status()}", ['body' => mb_substr($response->body(), 0, 500)]);
                throw new \Exception("Upload18 API error: HTTP {$response->status()}");
            }

            $data = $response->json();

            if (!$data) {
                throw new \Exception("Upload18 API returned invalid JSON");
            }

            // Check for "please wait" queue response
            if (isset($data['status']) && $data['status'] === 'please wait') {
                Log::info("Upload18: Server busy, waiting {$waitSeconds}s before retry...");
                sleep($waitSeconds);
                continue;
            }

            // Check for error
            if (isset($data['status']) && $data['status'] === 'error') {
                throw new \Exception("Upload18 upload error: " . ($data['msg'] ?? 'Unknown'));
            }

            // Success
            Log::info("Upload18: Upload successful", $data);
            return $data;
        }

        throw new \Exception("Upload18: Max retries ({$maxRetries}) exceeded");
    }

    /**
     * Get video detail from Upload18.
     *
     * @param string $vid Upload18 video ID
     * @return array Video details
     */
    public function getVideoDetail(string $vid): array
    {
        $response = $this->client(30)
            ->get("{$this->apiUrl}/getvideodetail/" . rawurlencode($vid));

        if (!$response->successful()) {
            throw new \Exception("Upload18 API error: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Update video on Upload18.
     *
     * @param string $vid Upload18 video ID
     * @param array $data Update data (name, cid, mycid)
     * @return array Response
     */
    public function updateVideo(string $vid, array $data = []): array
    {
        unset($data['apikey']);

        $response = $this->client(30)
            ->post("{$this->apiUrl}/updatevideo/" . rawurlencode($vid), $data);

        if (!$response->successful()) {
            throw new \Exception("Upload18 API error: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Delete video from Upload18.
     *
     * @param string $vid Upload18 video ID
     * @return bool Success
     */
    public function deleteVideo(string $vid): bool
    {
        $response = $this->client(30)
            ->post("{$this->apiUrl}/deletevideo/" . rawurlencode($vid));

        return $response->successful();
    }

    /**
     * List all videos from Upload18 account.
     *
     * @return array Videos list
     */
    public function listVideos(int $page = 1, int $perPage = 100): array
    {
        $response = $this->client(30)
            ->get("{$this->apiUrl}/myvideo", [
                'per_page' => $perPage,
                'page' => $page,
            ]);

        if (!$response->successful()) {
            throw new \Exception("Upload18 API error: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    /**
     * One page of the account's videos, for work that must tell "Upload18 is down" from "not in the list": an answer
     * that is not a JSON list (an error page, an empty body as during Upload18's outage of 24 October) throws
     * Upload18Unavailable instead of counting as an empty list.
     *
     * @return array{data: list<array>, total_page: int}
     */
    public function listVideosStrict(int $page = 1, int $perPage = 50, int $timeout = 20): array
    {
        try {
            $response = $this->client($timeout)
                ->get("{$this->apiUrl}/myvideo", [
                    'per_page' => $perPage,
                    'page' => $page,
                ]);
        } catch (Throwable $e) {
            throw new Upload18Unavailable('Upload18 did not answer the video list ('.class_basename($e).').', 0, $e);
        }

        if (! $response->successful()) {
            throw new Upload18Unavailable('Upload18 answered the video list with HTTP '.$response->status().'.');
        }

        $json = $this->decode($response);

        if (! is_array($json) || ! array_key_exists('data', $json) || ! is_array($json['data'])) {
            throw new Upload18Unavailable('Upload18 answered the video list without a list.');
        }

        return ['data' => array_values(array_filter($json['data'], 'is_array')), 'total_page' => max(1, (int) ($json['total_page'] ?? 1))];
    }

    /**
     * The items of these dids among the account's newest videos (newest first, $maxPages pages of $perPage): one list
     * call per page, stopping once every did was seen. Strict (Upload18Unavailable when Upload18 does not answer).
     *
     * @param  list<string>  $dids
     * @return array<string, Upload18Item> did => item, only the dids found
     */
    public function findItems(array $dids, int $perPage = 50, int $maxPages = 3): array
    {
        $wanted = array_fill_keys(array_values(array_filter(array_map('strval', $dids), fn (string $did): bool => $did !== '')), true);
        $found = [];

        if ($wanted === []) {
            return [];
        }

        $perPage = max(1, min(100, $perPage));
        $maxPages = max(1, min(10, $maxPages));
        $page = 1;
        $totalPages = 1;

        do {
            $answer = $this->listVideosStrict($page, $perPage);
            $totalPages = max($totalPages, $answer['total_page']);

            foreach ($answer['data'] as $video) {
                $item = Upload18Item::fromArray($video);

                if ($item !== null && isset($wanted[$item->did]) && ! isset($found[$item->did])) {
                    $found[$item->did] = $item;
                }
            }

            $page++;
        } while (count($found) < count($wanted) && $page <= $totalPages && $page <= $maxPages);

        return $found;
    }

    /**
     * One video by its vid (GET /getvideodetail/{vid}), for an upload no longer among the newest listed ones. Null when
     * Upload18 does not know it or answers something that is not that video: the answer must name this did (its "id"),
     * because a vid alone is public (every watch page shows it) and could belong to any upload of the account.
     */
    public function itemByVid(string $vid, string $did): ?Upload18Item
    {
        if ($vid === '' || preg_match('/^[A-Za-z0-9_-]{1,160}$/', $vid) !== 1) {
            return null;
        }

        try {
            $response = $this->client(15)->get("{$this->apiUrl}/getvideodetail/".rawurlencode($vid));
        } catch (Throwable $e) {
            throw new Upload18Unavailable('Upload18 did not answer the video detail ('.class_basename($e).').', 0, $e);
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new Upload18Unavailable('Upload18 answered the video detail with HTTP '.$response->status().'.');
        }

        $json = $this->decode($response);
        $data = is_array($json) && is_array($json['data'] ?? null) ? $json['data'] : $json;

        if (! is_array($data)) {
            return null;
        }

        // The detail is of the vid it was asked for, and its id must be this upload's did: an answer without an id tells
        // nothing about which upload it is.
        if (($data['id'] ?? $data['did'] ?? null) === null) {
            return null;
        }

        $data['vid'] = isset($data['vid']) && $data['vid'] !== '' ? $data['vid'] : $vid;
        $item = Upload18Item::fromArray($data);

        return $item !== null && $item->did === $did && $item->vid === $vid ? $item : null;
    }

    /**
     * A short-lived upload token for one browser (POST /uploadtoken): the browser sends it in the apikey field instead
     * of the key. Locked to the site's category, folder and server when they are set (Upload18 refuses an upload that
     * asks for another one).
     *
     * @param  array{cid?: string|int|null, mycid?: string|int|null, fid?: string|int|null}  $locks
     * @return array{token: string, upload_url: ?string, expires_at: ?int}
     *
     * @throws Upload18Unavailable when no token could be had (with Upload18's HTTP status and error code, never the key)
     */
    public function mintUploadToken(array $locks = [], int $expiresIn = 21600): array
    {
        if (! $this->hasKey()) {
            throw new Upload18Unavailable('No Upload18 key is saved.');
        }

        $fields = ['expires_in' => max(60, min(86400, $expiresIn))];

        foreach (['cid', 'mycid', 'fid'] as $lock) {
            $value = trim((string) ($locks[$lock] ?? ''));

            // 0 means "none" for these numbers (mycid 0 = no folder): only real numbers lock the token.
            if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
                $fields[$lock] = $value;
            }
        }

        try {
            $response = $this->client(12)->asForm()->post("{$this->apiUrl}/uploadtoken", $fields);
        } catch (Throwable $e) {
            throw new Upload18Unavailable('Upload18 did not answer the upload token request ('.class_basename($e).').', 0, $e);
        }

        $json = $this->decode($response);
        $code = is_array($json) ? (string) ($json['error_code'] ?? '') : '';

        if (! $response->successful() || ! is_array($json) || strtolower((string) ($json['status'] ?? '')) !== 'success') {
            throw new Upload18Unavailable('Upload18 made no upload token (HTTP '.$response->status().($code !== '' ? ', '.$code : '').').');
        }

        $token = is_string($json['upload_token'] ?? null) ? trim($json['upload_token']) : '';

        if ($token === '' || strlen($token) > 4096 || preg_match('/^[A-Za-z0-9._~+\/=-]+$/', $token) !== 1) {
            throw new Upload18Unavailable('Upload18 answered the upload token request without a usable token.');
        }

        return [
            'token' => $token,
            'upload_url' => $this->uploadUrl($json['upload_url'] ?? null),
            'expires_at' => is_numeric($json['expires_at'] ?? null) ? (int) $json['expires_at'] : null,
        ];
    }

    /**
     * What the saved key may do (GET /keyinfo): the HTTP status and the decoded answer, read by
     * App\Support\Upload18\KeyInfo. At most 4 seconds: a page or an upload start that asks never waits longer.
     *
     * @return array{status: int, json: mixed}
     */
    public function keyInfo(): array
    {
        if (! $this->hasKey()) {
            return ['status' => 401, 'json' => null];
        }

        $response = $this->client(4)->get("{$this->apiUrl}/keyinfo");

        return ['status' => $response->status(), 'json' => $this->decode($response)];
    }

    /**
     * Find one Upload18 video by its numeric document ID (did).
     */
    public function findVideoByDid(string $did, int $perPage = 20, int $maxPages = 3): ?array
    {
        $perPage = max(1, min(100, $perPage));
        $maxPages = max(1, min(10, $maxPages));
        $page = 1;
        $totalPages = 1;

        do {
            $response = $this->listVideos($page, $perPage);
            $totalPages = max($totalPages, (int) ($response['total_page'] ?? 1));

            foreach (($response['data'] ?? []) as $video) {
                if ((string) ($video['id'] ?? '') === $did) {
                    return $video;
                }
            }

            $page++;
        } while ($page <= $totalPages && $page <= $maxPages);

        return null;
    }

    /**
     * Return normalized processing status for a did returned by Upload18 upload.
     *
     * @return array{found: bool, ready: bool, vid: string, zt: string, duration: mixed, duration_seconds: int|null}
     */
    public function statusForDid(string $did): array
    {
        return self::statusFromVideo($this->findVideoByDid($did, 50, 3));
    }

    /**
     * The status answer of one listed video (null = not listed), as the upload page reads it.
     *
     * @return array{found: bool, ready: bool, vid: string, zt: string, duration: mixed, duration_seconds: int|null}
     */
    public static function statusFromVideo(?array $video): array
    {
        if (!$video) {
            return [
                'found' => false,
                'ready' => false,
                'vid' => '',
                'zt' => '0',
                'duration' => null,
                'duration_seconds' => null,
            ];
        }

        $vid = (string) ($video['vid'] ?? '');
        $zt = (string) ($video['zt'] ?? '0');
        $duration = $video['duration'] ?? null;

        return [
            'found' => true,
            'ready' => $vid !== '' && $zt === '2',
            'vid' => $vid,
            'zt' => $zt,
            'duration' => $duration,
            'duration_seconds' => self::durationToSeconds($duration),
        ];
    }

    /**
     * Poll /myvideo to find the embed code (vid) for a recently uploaded video.
     * After upload, the API returns did but vid is empty. We poll until zt=2 (done)
     * and the vid becomes available.
     *
     * @param string $did The did (video ID) returned from upload
     * @param int $maxWaitSeconds Maximum seconds to wait for transcoding
     * @return array{vid: string, did: string, duration: string, size: string}|null
     */
    public function pollForEmbedCode(string $did, int $maxWaitSeconds = 600): ?array
    {
        $pollInterval = 15;
        $elapsed = 0;

        while ($elapsed < $maxWaitSeconds) {
            Log::info("Upload18: Polling for vid (did={$did}), elapsed={$elapsed}s");

            $response = $this->client(30)
                ->get("{$this->apiUrl}/myvideo", [
                    'per_page' => 20,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['data']) && is_array($data['data'])) {
                    foreach ($data['data'] as $video) {
                        if ((string)($video['id'] ?? '') === $did) {
                            $vid = $video['vid'] ?? '';
                            $zt = (string)($video['zt'] ?? '0');

                            if (!empty($vid) && $zt === '2') {
                                Log::info("Upload18: Got vid={$vid} for did={$did} (transcoding done)");
                                return $video;
                            }

                            Log::info("Upload18: Video found but not ready. zt={$zt}, vid={$vid}");
                            break;
                        }
                    }
                }
            }

            sleep($pollInterval);
            $elapsed += $pollInterval;
        }

        Log::warning("Upload18: Timed out waiting for vid (did={$did}) after {$maxWaitSeconds}s");
        return null;
    }

    /**
     * Build the embed URL from a video hash.
     */
    public static function embedUrl(string $embedCode): string
    {
        return "https://upload18.org/play/index/{$embedCode}";
    }

    public static function durationToSeconds(mixed $duration): ?int
    {
        return DurationParser::toSeconds($duration);
    }

    /** Every call: the key in the Authorization: Bearer header (Upload18 API V2.1), JSON answers. */
    private function client(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)
            ->connectTimeout(min(10, $timeout))
            ->acceptJson()
            ->withToken($this->apiKey);
    }

    private function decode(Response $response): mixed
    {
        $body = ltrim($response->body(), "\xEF\xBB\xBF \t\r\n");

        if ($body === '') {
            return null;
        }

        try {
            return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The upload address of a token answer, for the member's browser: an https address (the token travels with every
     * piece of the file), or an http one only when the site itself talks to Upload18 over http (a local test server).
     * Anything else: null, and the browser uses {api}/upload.
     */
    private function uploadUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || strlen($value) > 2048) {
            return null;
        }

        $value = trim($value);
        $parts = parse_url($value);
        $schemes = str_starts_with(strtolower($this->apiUrl), 'http://') ? ['https', 'http'] : ['https'];

        return is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), $schemes, true) && ($parts['host'] ?? '') !== ''
            ? $value
            : null;
    }
}
