<?php

namespace App\Services;

use App\Support\DurationParser;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $this->apiKey = (string) ($uploads['upload18_api_key'] ?? config('services.upload18.api_key', ''));
        $this->apiUrl = rtrim((string) ($uploads['upload18_api_url'] ?? config('services.upload18.api_url', 'https://upload18.org/api')), '/');
        $this->defaultCid = (string) ($uploads['upload18_default_cid'] ?? config('services.upload18.default_cid', '15'));
        $this->defaultMycid = (string) ($uploads['upload18_default_mycid'] ?? config('services.upload18.default_mycid', '0'));
        $this->defaultFid = (string) ($uploads['upload18_default_fid'] ?? config('services.upload18.default_fid', '18'));
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
                $response = Http::timeout(600)
                    ->attach('video', $handle, basename($filePath))
                    ->post("{$this->apiUrl}/upload", [
                        'apikey' => $this->apiKey,
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
                Log::error("Upload18: HTTP error {$response->status()}", ['body' => $response->body()]);
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
        $response = Http::timeout(30)
            ->get("{$this->apiUrl}/getvideodetail/{$vid}", [
                'apikey' => $this->apiKey,
            ]);

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
        $response = Http::timeout(30)
            ->post("{$this->apiUrl}/updatevideo/{$vid}", array_merge([
                'apikey' => $this->apiKey,
            ], $data));

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
        $response = Http::timeout(30)
            ->post("{$this->apiUrl}/deletevideo/{$vid}", [
                'apikey' => $this->apiKey,
            ]);

        return $response->successful();
    }

    /**
     * List all videos from Upload18 account.
     *
     * @return array Videos list
     */
    public function listVideos(int $page = 1, int $perPage = 100): array
    {
        $response = Http::timeout(30)
            ->get("{$this->apiUrl}/myvideo", [
                'apikey' => $this->apiKey,
                'per_page' => $perPage,
                'page' => $page,
            ]);

        if (!$response->successful()) {
            throw new \Exception("Upload18 API error: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
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
        $video = $this->findVideoByDid($did, 50, 3);

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

            $response = Http::timeout(30)
                ->get("{$this->apiUrl}/myvideo", [
                    'apikey' => $this->apiKey,
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
}
