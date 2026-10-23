<?php

namespace App\Http\Controllers\Api\Private\Traits;

use App\Models\Video;
use Illuminate\Support\Facades\Http;

trait Upload18ServerStreamTrait
{
    private function absoluteStreamUrl(string $url, string $baseUrl): string
    {
        $url = html_entity_decode(trim(str_replace('\/', '/', $url)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? 'upload18.org';

        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$url}";
        }

        $path = $base['path'] ?? '/';
        $lastSlash = strrpos($path, '/');
        $directory = $lastSlash === false ? '' : rtrim(substr($path, 0, $lastSlash), '/');

        return "{$scheme}://{$host}{$directory}/{$url}";
    }

    private function isHlsStreamUrl(string $url): bool
    {
        $url = strtolower($url);

        return str_contains($url, '.m3u8')
            || str_contains($url, '/play/token_hash')
            || str_contains($url, 'm3u8');
    }

    protected function resolveUpload18Stream(Video $video): array
    {
        $embedUrl = $this->upload18PlayerUrl($video);

        $response = Http::timeout(15)
            ->withHeaders($this->upload18RequestHeaders($video, true))
            ->get($embedUrl);

        if (!$response->successful()) {
            return ['error' => 'Failed to fetch embed page.', 'status' => 502];
        }

        $body = $response->body();
        $streamUrl = null;

        // Try direct JSON extraction of PLAYER_CONFIG first
        if (preg_match('/PLAYER_CONFIG\s*=\s*(\{.*?\});/s', $body, $configMatch)) {
            $config = json_decode($configMatch[1], true);
            if (is_array($config)) {
                $candidate = $config['m3u8'] ?? ($config['file'] ?? ($config['source'] ?? null));
                if ($candidate && is_string($candidate)) {
                    $streamUrl = $candidate;
                }
            }
        }

        if (!$streamUrl) {
            $html = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html = str_replace('\/', '/', $html);

            $patterns = [
                '/"m3u8"\s*[:=]\s*["\']([^"\']+)["\']/i',
                '/m3u8\s*[:=]\s*["\']([^"\']+)["\']/i',
                '/"file"\s*[:=]\s*["\']([^"\']+(?:\.m3u8|\.mp4)[^"\']*)["\']/i',
                '/file\s*[:=]\s*["\']([^"\']+(?:\.m3u8|\.mp4)[^"\']*)["\']/i',
                '/"source"\s*[:=]\s*["\']([^"\']+(?:\.m3u8|\.mp4)[^"\']*)["\']/i',
                '/source\s*[:=]\s*["\']([^"\']+(?:\.m3u8|\.mp4)[^"\']*)["\']/i',
                '/((?:https?:)?\/\/[^\s"\']+\.m3u8[^\s"\']*)/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $matches)) {
                    $streamUrl = $matches[1];
                    break;
                }
            }
        }

        if (!$streamUrl) {
            return ['error' => 'Could not extract stream URL.', 'status' => 422];
        }

        $streamUrl = $this->absoluteStreamUrl($streamUrl, $embedUrl);

        return [
            'stream_url' => $streamUrl,
            'embed_code' => $video->embed_code,
            'source_type' => $this->isHlsStreamUrl($streamUrl) ? 'hls' : 'video',
        ];
    }

    protected function fetchUpload18StreamBody(Video $video, string $url, int $timeout = 30): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$this->isAllowedUpload18StreamHost($host)) {
            return null;
        }

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($this->upload18RequestHeaders($video))
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    protected function upload18RequestHeaders(Video $video, bool $embedPage = false): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Referer' => $embedPage ? 'https://upload18.org/' : $this->upload18PlayerUrl($video),
            'Origin' => 'https://upload18.org',
            'Accept' => '*/*',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
        ];
    }

    protected function upload18FfmpegHeaders(Video $video): string
    {
        return "Referer: {$this->upload18PlayerUrl($video)}\r\nOrigin: https://upload18.org\r\nSec-Fetch-Dest: empty\r\nSec-Fetch-Mode: cors\r\n";
    }

    private function isAllowedUpload18StreamHost(?string $host): bool
    {
        if (!$host) {
            return false;
        }

        $allowed = ['upload18.org', 'upload18.net', 'upload18.com', 'upload18.cc', 'helvid.com', '321watch.workers.dev'];
        foreach ($allowed as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private function upload18PlayerUrl(Video $video): string
    {
        if ($video->embed_code && (str_starts_with($video->embed_code, 'http://') || str_starts_with($video->embed_code, 'https://'))) {
            return $video->embed_code;
        }

        return "https://upload18.org/play/index/{$video->embed_code}";
    }
}
