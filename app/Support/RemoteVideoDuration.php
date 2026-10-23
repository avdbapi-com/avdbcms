<?php

namespace App\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Pool as GuzzlePool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

final class RemoteVideoDuration
{
    private const DURATION_FIELDS = ['time', 'duration', 'vod_duration', 'runtime', 'length'];

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

    private static array $cache = [];

    public static function fromAvdbItem(array $item, bool $allowRemote = true): ?int
    {
        $seconds = self::fromDurationFields($item);
        if ($seconds !== null || ! $allowRemote) {
            return $seconds;
        }

        $playableLink = self::playableLinkFromAvdbItem($item);

        return $playableLink ? self::fromPlayableLink($playableLink) : null;
    }

    public static function fromDurationFields(array $item): ?int
    {
        foreach (self::DURATION_FIELDS as $field) {
            $seconds = DurationParser::toSeconds($item[$field] ?? null);
            if ($seconds !== null) {
                return $seconds;
            }
        }

        return null;
    }

    public static function playableLinkFromAvdbItem(array $item): ?string
    {
        $episodes = $item['episodes'] ?? null;
        if (! is_array($episodes)) {
            return null;
        }

        $serverData = $episodes['server_data'] ?? null;
        if (is_string($serverData) && $serverData !== '') {
            return self::normalizePlayableLink($serverData);
        }

        if (! is_array($serverData)) {
            return null;
        }

        foreach ($serverData as $data) {
            if (! is_array($data)) {
                continue;
            }

            if (! empty($data['link_embed'])) {
                return self::normalizePlayableLink($data['link_embed']);
            }

            if (! empty($data['link_m3u8'])) {
                return self::normalizePlayableLink($data['link_m3u8']);
            }
        }

        return null;
    }

    public static function fromPlayableLink(string $playableLink): ?int
    {
        $playableLink = self::normalizePlayableLink($playableLink);
        if ($playableLink === null) {
            return null;
        }

        if (array_key_exists($playableLink, self::$cache)) {
            return self::$cache[$playableLink];
        }

        $duration = self::resolvePlayableLinkDuration($playableLink);
        if ($duration !== null) {
            self::$cache[$playableLink] = $duration;
        }

        return $duration;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, int|null>
     */
    public static function fromAvdbItems(array $items, bool $allowRemote = true, int $concurrency = 25, int $timeout = 8): array
    {
        $results = [];
        $remoteLinks = [];

        foreach ($items as $key => $item) {
            $avdbId = (int) ($item['id'] ?? $key);
            if ($avdbId <= 0) {
                continue;
            }

            $seconds = self::fromDurationFields($item);
            if ($seconds !== null || ! $allowRemote) {
                $results[$avdbId] = $seconds;

                continue;
            }

            $playableLink = self::playableLinkFromAvdbItem($item);
            if (! $playableLink) {
                $results[$avdbId] = null;

                continue;
            }

            $playableLink = self::normalizePlayableLink($playableLink);
            if (! $playableLink) {
                $results[$avdbId] = null;

                continue;
            }

            if (array_key_exists($playableLink, self::$cache)) {
                $results[$avdbId] = self::$cache[$playableLink];

                continue;
            }

            $remoteLinks[$avdbId] = $playableLink;
        }

        if ($remoteLinks !== []) {
            $results += self::fromPlayableLinks($remoteLinks, $concurrency, $timeout);
        }

        return $results;
    }

    /**
     * @param  array<int, string>  $linksByAvdbId
     * @return array<int, int|null>
     */
    public static function fromPlayableLinks(array $linksByAvdbId, int $concurrency = 25, int $timeout = 8): array
    {
        $concurrency = max(1, min(100, $concurrency));
        $timeout = max(2, min(30, $timeout));
        if (app()->environment('testing')) {
            $results = [];
            foreach ($linksByAvdbId as $avdbId => $playableLink) {
                $results[(int) $avdbId] = self::fromPlayableLink($playableLink);
            }

            return $results;
        }

        $client = new Client([
            'verify' => false,
            'timeout' => $timeout,
            'connect_timeout' => min(5, $timeout),
            'http_errors' => false,
        ]);
        $results = [];
        $cookieJars = [];
        $embedUrls = [];
        $playableLinks = [];

        $requests = function () use ($linksByAvdbId, $client, $timeout, &$cookieJars, &$embedUrls, &$playableLinks) {
            foreach ($linksByAvdbId as $avdbId => $playableLink) {
                $playableLink = self::normalizePlayableLink($playableLink);
                if (! $playableLink) {
                    continue;
                }

                $embedUrl = self::playerUrl($playableLink);
                $cookieJars[$avdbId] = new CookieJar;
                $embedUrls[$avdbId] = $embedUrl;
                $playableLinks[$avdbId] = $playableLink;

                yield $avdbId => fn () => $client->sendAsync(
                    new Request('GET', $embedUrl, self::headers($embedUrl, true)),
                    [
                        'cookies' => $cookieJars[$avdbId],
                        'verify' => false,
                        'timeout' => $timeout,
                        'connect_timeout' => min(5, $timeout),
                        'http_errors' => false,
                    ]
                );
            }
        };

        $pool = new GuzzlePool($client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function (ResponseInterface $response, int $avdbId) use (&$results, &$cookieJars, &$embedUrls, &$playableLinks, $timeout): void {
                $duration = null;
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $html = self::normalizeHtml((string) $response->getBody());
                    foreach (self::streamCandidates($html, $embedUrls[$avdbId] ?? '') as $streamUrl) {
                        $duration = self::durationFromPlaylistUrl(
                            $streamUrl,
                            $embedUrls[$avdbId] ?? self::refererFor($streamUrl),
                            $cookieJars[$avdbId] ?? new CookieJar,
                            0,
                            $timeout
                        );
                        if ($duration !== null) {
                            break;
                        }
                    }
                }

                $playableLink = $playableLinks[$avdbId] ?? null;
                if ($playableLink && $duration !== null) {
                    self::$cache[$playableLink] = $duration;
                }
                $results[$avdbId] = $duration;
            },
            'rejected' => function (mixed $reason, int $avdbId) use (&$results, &$playableLinks): void {
                $playableLink = $playableLinks[$avdbId] ?? null;
                if ($playableLink) {
                    unset(self::$cache[$playableLink]);
                }
                $results[$avdbId] = null;
            },
        ]);

        $pool->promise()->wait();

        foreach ($linksByAvdbId as $avdbId => $playableLink) {
            if (! array_key_exists($avdbId, $results)) {
                $results[(int) $avdbId] = null;
            }
        }

        return $results;
    }

    private static function resolvePlayableLinkDuration(string $playableLink): ?int
    {
        if (self::isHlsUrl($playableLink)) {
            return self::durationFromPlaylistUrl($playableLink, self::refererFor($playableLink), new CookieJar);
        }

        $embedUrl = self::playerUrl($playableLink);
        $cookieJar = new CookieJar;

        try {
            $response = Http::timeout(15)
                ->withOptions(['verify' => false, 'cookies' => $cookieJar])
                ->withHeaders(self::headers($embedUrl, true))
                ->get($embedUrl);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $html = self::normalizeHtml($response->body());
        foreach (self::streamCandidates($html, $embedUrl) as $streamUrl) {
            $duration = self::durationFromPlaylistUrl($streamUrl, $embedUrl, $cookieJar);
            if ($duration !== null) {
                return $duration;
            }
        }

        return null;
    }

    private static function durationFromPlaylistUrl(string $url, string $referer, CookieJar $cookieJar, int $depth = 0, int $timeout = 20): ?int
    {
        try {
            $response = Http::timeout(max(2, min(30, $timeout)))
                ->withOptions(['verify' => false, 'cookies' => $cookieJar])
                ->withHeaders(self::headers($referer))
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if (! str_contains($body, '#EXTM3U')) {
            return null;
        }

        $seconds = self::durationFromPlaylistBody($body);
        if ($seconds !== null) {
            return $seconds;
        }

        if ($depth >= 2) {
            return null;
        }

        foreach (self::variantPlaylistCandidates($body, $url) as $variantUrl) {
            $seconds = self::durationFromPlaylistUrl($variantUrl, $referer, $cookieJar, $depth + 1, $timeout);
            if ($seconds !== null) {
                return $seconds;
            }
        }

        return null;
    }

    private static function durationFromPlaylistBody(string $body): ?int
    {
        if (! preg_match_all('/#EXTINF:([0-9]+(?:\.[0-9]+)?)/i', $body, $matches)) {
            return null;
        }

        $seconds = 0.0;
        foreach ($matches[1] as $duration) {
            $seconds += (float) $duration;
        }

        $rounded = (int) round($seconds);

        return $rounded > 0 ? $rounded : null;
    }

    private static function variantPlaylistCandidates(string $body, string $playlistUrl): array
    {
        if (! str_contains($body, '#EXT-X-STREAM-INF')) {
            return [];
        }

        $candidates = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $candidates[] = self::absoluteUrl($line, $playlistUrl);
        }

        return array_values(array_unique($candidates));
    }

    private static function streamCandidates(string $html, string $embedUrl): array
    {
        $patterns = [
            '/PLAYER_CONFIG\s*=\s*\{.*?m3u8\s*:\s*(["\'])(.*?)\1/is' => 2,
            '/m3u8\s*[:=]\s*["\']([^"\']+)["\']/i' => 1,
            '/file\s*:\s*["\']([^"\']+(?:\.m3u8|\.mp4|token_hash)[^"\']*)["\']/i' => 1,
            '/"file"\s*:\s*"([^"]+(?:\.m3u8|\.mp4|token_hash)[^"]*)"/i' => 1,
            '/source\s*:\s*["\']([^"\']+(?:\.m3u8|\.mp4|token_hash)[^"\']*)["\']/i' => 1,
            '/src\s*:\s*["\']([^"\']+(?:\.m3u8|\.mp4|token_hash)[^"\']*)["\']/i' => 1,
            '/sources\s*:\s*\[\s*\{\s*file\s*:\s*["\']([^"\']+)["\']/i' => 1,
            '/((?:https?:)?\/\/[^\s"\']+(?:\.m3u8|token_hash)[^\s"\']*)/i' => 1,
        ];

        $candidates = [];
        foreach ($patterns as $pattern => $group) {
            if (! preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $url = trim((string) ($match[$group] ?? ''));
                if ($url !== '') {
                    $candidates[] = self::absoluteUrl($url, $embedUrl);
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    private static function playerUrl(string $playableLink): string
    {
        if (str_starts_with($playableLink, 'http://') || str_starts_with($playableLink, 'https://')) {
            return $playableLink;
        }

        return "https://upload18.org/play/index/{$playableLink}";
    }

    private static function refererFor(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'upload18.org';

        return "{$scheme}://{$host}/";
    }

    private static function absoluteUrl(string $url, string $baseUrl): string
    {
        $url = html_entity_decode(trim(str_replace('\/', '/', $url)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
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

    private static function isHlsUrl(string $url): bool
    {
        $url = strtolower($url);

        return str_contains($url, '.m3u8')
            || str_contains($url, '/play/token_hash')
            || str_contains($url, 'm3u8');
    }

    private static function headers(string $referer, bool $embedPage = false): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Referer' => $embedPage ? 'https://upload18.org/' : $referer,
            'Origin' => 'https://upload18.org',
            'Accept' => '*/*',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
        ];
    }

    private static function normalizePlayableLink(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $value, $match)) {
            $value = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        }

        return trim($value) !== '' ? trim($value) : null;
    }

    private static function normalizeHtml(string $html): string
    {
        return html_entity_decode(str_replace('\/', '/', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
