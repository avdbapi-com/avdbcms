<?php

namespace App\Support;

/**
 * Short addresses for links inside the member pages (member design 4.3, the page-content budget): a link to this site
 * is printed as its path ("/video/...") instead of the full "https://example.com/video/...", which every card and row
 * of the channel, Library, History and playlist pages repeats. Addresses of other sites (a CDN thumbnail, an external
 * website) and anything that is not a plain http(s) address stay exactly as they are; so does every address a visitor
 * copies or shares (those must stay absolute and never pass through here).
 */
final class PageUrl
{
    /**
     * The address as a site-relative path when it points at the site that answers this request (same host and port),
     * else unchanged. Query string and fragment are kept; a site installed in a folder or behind /index.php keeps its
     * prefix, because the whole path is kept.
     */
    public static function local(?string $url): string
    {
        $url = (string) $url;
        if ($url === '' || (str_starts_with($url, '/') && !str_starts_with($url, '//'))) {
            return $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return $url;
        }

        $request = request();
        if ($request === null || strcasecmp((string) $parts['host'], $request->getHost()) !== 0) {
            return $url;
        }

        $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));
        if ($port !== (int) $request->getPort()) {
            return $url;
        }

        $path = (string) ($parts['path'] ?? '');
        $path = $path === '' ? '/' : $path;

        return $path
            . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '');
    }
}
