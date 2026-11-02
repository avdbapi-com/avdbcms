<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The site's robots.txt (round 4 fix pass, R3M-02; already in 1.2.2).
 *
 * Every web server serves a file public/robots.txt at /robots.txt before the site's own route (SeoController::robots()),
 * and the shipped nginx file of a site whose web root is public/ (docs/server-configs/nginx-public-root.conf,
 * "location = /robots.txt") serves only that file: without it /robots.txt is a 404. So the file is what crawlers read, and
 * the site writes it itself with the SEO settings' text (text(): the one function that makes it, the route answers the
 * same): when a save changes Custom robots.txt, Sitemap or "Hide the whole site from Google" (SETTINGS;
 * Admin\SettingsController: an edit of another SEO setting leaves the file alone), when an administrator opens
 * Settings > Google & sharing (Admin\SettingsPageController: a site updated from 1.2.2 has the shipped file until then)
 * and at the end of an install (Installer::jobFiles()). The shipped file is the safe default without a Sitemap line (its
 * address is the site's own, unknown before the install). The one-click update keeps a file the site wrote: it differs
 * from the shipped one, and public/robots.txt is a file hosts edit (Updater\SwapPlanner::HAND_EDITED: kept, the new copy
 * saved next to it).
 *
 * A robots.txt the owner wrote by hand (any text the site never writes, isSites()) is never lost: opening the page or
 * installing leaves it as it is; a save that changes one of SETTINGS (the owner asks for other rules) first keeps it next
 * to itself as robots.txt.owner-<date>-<time>, then writes the file, and the administrator reads where it is and how to
 * keep its lines (Custom robots.txt).
 *
 * The site remembers what it wrote (RECORD in its storage: the sha256 of every text it put into each file, recorded
 * before the file is renamed into place, one writer at a time): a file that holds a text the site wrote is its own,
 * whatever the settings were then. So after a save whose write failed (the file still holds an earlier Custom
 * robots.txt), opening the page or the next save replaces it, without keeping it as the owner's (R4SEO-V2-01). Only a
 * text the site never wrote nor shipped is the owner's.
 *
 * Written atomically (a hidden temporary file in the same folder, then renamed over the file): a crawler never reads half
 * a file. Only inside the site's own folder: public/robots.txt and, where there is one, the copy the project folder's
 * index.php made of it next to itself (servers whose web root is the project folder serve that copy). A site in a folder
 * of its domain never writes the domain's robots.txt (crawlers read only that one; the installer and the guide say what to
 * add there). A file that cannot be written never stops a settings save: the caller tells the administrator what to do.
 */
final class RobotsTxt
{
    /**
     * The container key of the folders written: ['public' => the public folder, 'project' => the project folder, and
     * optionally 'storage' => the folder of RECORD]. Unbound: public_path(), base_path() and storage_path('app'); bound
     * without 'storage': <project>/storage/app (the site's own layout). Tests\TestCase points it at a scratch folder: no
     * test writes the copy's own file, nor its record.
     */
    public const FOLDERS = 'robots_txt.folders';

    /**
     * The record of the texts the site wrote, in its storage (recordFolder()): {"written": {"public/robots.txt":
     * [sha256, ...], "robots.txt": [...]}}, the newest last; its lock file is LOCK next to it.
     */
    public const RECORD = 'robots-txt.json';

    private const LOCK = 'robots-txt.lock';

    /** How many texts the record keeps per file, the newest (the file holds the last one the site wrote). */
    private const KEEP = 50;

    /**
     * The SEO settings (group "seo") the file is made of, with their defaults: a save that changes one of them writes it
     * again (changes()); a save of the other SEO settings does not.
     */
    public const SETTINGS = ['robots_txt' => '', 'robots_noindex' => '0', 'sitemap_enabled' => '1'];

    /** The name a robots.txt the owner wrote by hand is kept under, next to it, before the site replaces it (+ date-time). */
    public const OWNERS_COPY = '.owner-';

    /** The text while "Hide the whole site from Google" is on. */
    private const HIDDEN = "User-agent: *\nDisallow: /\n";

    /** The rules of the default text (no custom robots.txt, the site not hidden). */
    private const RULES = [
        'Disallow: /admin/',
        'Disallow: /api/',
        'Disallow: /login',
        'Disallow: /register',
        'Disallow: /update-locale',
        'Disallow: /search',
        'Disallow: /*/search',
        'Disallow: /*/update-locale',
        'Crawl-delay: 2',
    ];

    /**
     * The text of the site's robots.txt, from the running settings: Custom robots.txt (Settings > Google & sharing) when
     * it has one; else everything closed while "Hide the whole site from Google" is on, or the default rules, and the
     * sitemap's whole address while the sitemap is served (it answers 404 while the site is hidden or the sitemap is off).
     *
     * @param  string|null  $siteRoot  the site's address (scheme, host, folder) the sitemap address starts with; null: the
     *                                 running site's (site_root())
     */
    public static function text(?string $siteRoot = null): string
    {
        $custom = trim((string) config('site.robots_txt_custom', ''));

        if ($custom !== '') {
            return $custom;
        }

        if ((bool) config('site.robots_noindex')) {
            return self::HIDDEN;
        }

        return self::rules().(config('site.sitemap_enabled', true) ? 'Sitemap: '.self::sitemapUrl($siteRoot)."\n" : '');
    }

    /** The default rules without a Sitemap line: the shipped file of 1.2.3 (and the text while the sitemap is off). */
    private static function rules(): string
    {
        return "User-agent: *\nAllow: /\n".implode("\n", self::RULES)."\n";
    }

    /**
     * Whether a save changes one of SETTINGS (what the file is made of): the "seo" group's stored values before it and
     * the values it writes (only the keys it writes; a missing value is the setting's default).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $written
     */
    public static function changes(array $before, array $written): bool
    {
        foreach (self::SETTINGS as $key => $default) {
            if (array_key_exists($key, $written) && (string) ($before[$key] ?? $default) !== (string) ($written[$key] ?? $default)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sitemap's whole address in the site's own form, whatever form this request came in by: /index.php/sitemap.xml
     * where the site's links carry /index.php (a server without rewrite rules: App\Support\CleanUrls), else /sitemap.xml.
     */
    public static function sitemapUrl(?string $siteRoot = null): string
    {
        $root = rtrim((string) preg_replace('#/index\.php/?$#i', '', rtrim($siteRoot ?? site_root(), '/')), '/');
        [$indexPhp] = CleanUrls::decide(false);

        return $root.($indexPhp ? '/index.php' : '').'/sitemap.xml';
    }

    /**
     * Writes the file with the SEO settings as they are stored now (a save has just changed them, or an install has just
     * written them: the running request read them before), with text(). The text of the settings this request started
     * with also counts as the site's own (the file a save replaces holds it, a Custom robots.txt among them).
     *
     * @param  bool  $keepOwners  true: a robots.txt the owner wrote by hand stays as it is (opening the settings page, the
     *                            end of an install); false: it is kept next to itself first, then replaced (a save that
     *                            changed one of SETTINGS)
     * @return array{failed: list<string>, saved: array<string, string>, left: list<string>} see write()
     */
    public static function refresh(?string $siteRoot = null, bool $keepOwners = false): array
    {
        try {
            $previous = [self::text($siteRoot)];
        } catch (Throwable $e) {
            report($e);
            $previous = [];
        }

        try {
            // as AppServiceProvider::applySettings() reads them
            $seo = Setting::getGroup('seo');
            config([
                'site.robots_txt_custom' => (string) ($seo['robots_txt'] ?? ''),
                'site.robots_noindex' => ($seo['robots_noindex'] ?? '0') === '1',
                'site.sitemap_enabled' => ($seo['sitemap_enabled'] ?? '1') === '1',
            ]);
        } catch (Throwable $e) {
            // no settings table: the running settings (the defaults)
            report($e);
        }

        return self::write($siteRoot, $keepOwners, $previous);
    }

    /**
     * Writes text() into the site's robots.txt files (targets()); a file that already holds it is left as it is, and so
     * is one the owner wrote by hand while $keepOwners (else it is kept next to itself first: OWNERS_COPY). A text the
     * site wrote there before (RECORD) is its own, as are $previous and the texts isSites() knows.
     *
     * @param  list<string>  $previous  other texts the site wrote there (the settings' text before a save)
     * @return array{failed: list<string>, saved: array<string, string>, left: list<string>} paths of the site: failed = not
     *               written ("public/robots.txt", "robots.txt"); saved = the owner's file => where it is kept now
     *               ("public/robots.txt.owner-20261102-120000"); left = the owner's files left as they are
     */
    public static function write(?string $siteRoot = null, bool $keepOwners = false, array $previous = []): array
    {
        $result = ['failed' => [], 'saved' => [], 'left' => []];

        try {
            $text = self::text($siteRoot);
        } catch (Throwable $e) {
            report($e);
            $result['failed'][] = 'public/robots.txt';

            return $result;
        }

        // One writer at a time: the record is read, added to and written back while the files are written (a lost entry
        // would make a text the site wrote look like the owner's).
        $lock = self::lock();

        try {
            $record = self::record();

            foreach (self::targets() as $name => $path) {
                $outcome = self::put($name, $path, $text, [...$previous, $text], $keepOwners, $record);

                if ($outcome === false) {
                    $result['failed'][] = $name;
                } elseif ($outcome === 'left') {
                    $result['left'][] = $name;
                } elseif ($outcome !== true) {
                    $result['saved'][$name] = (str_contains($name, '/') ? dirname($name).'/' : '').$outcome;
                }
            }
        } finally {
            self::unlock($lock);
        }

        if ($result['failed'] !== []) {
            Log::warning('robots.txt could not be written: '.implode(', ', $result['failed']).' (the web server may not write there).');
        }

        return $result;
    }

    /**
     * Whether $content is a robots.txt the site itself wrote or shipped (so it may be replaced): the default rules with
     * or without the sitemap's whole address, the hidden site's text, the file 1.2.0 to 1.2.2 shipped (a relative
     * Sitemap address), or one of $own (the settings' text before and after this write). Anything else the owner wrote,
     * unless RECORD says the site wrote it into that file (write()).
     *
     * @param  list<string>  $own
     */
    public static function isSites(string $content, array $own = []): bool
    {
        $rules = self::rules();

        return in_array($content, $own, true)
            || in_array($content, [$rules, self::HIDDEN, $rules."Sitemap: /sitemap.xml\n"], true)
            || preg_match('#\A'.preg_quote($rules, '#').'Sitemap: https?://\S+\n\z#', $content) === 1;
    }

    /**
     * The files written: public/robots.txt, and robots.txt of the project folder when the project folder's index.php made
     * that copy (it copies public/robots.txt there once, when it is missing).
     *
     * @return array<string, string> the path of the site ("public/robots.txt") => the file
     */
    public static function targets(): array
    {
        $folders = app()->bound(self::FOLDERS) ? (array) app(self::FOLDERS) : [];
        $public = rtrim((string) ($folders['public'] ?? public_path()), '/\\');
        $project = rtrim((string) ($folders['project'] ?? base_path()), '/\\');
        $targets = ['public/robots.txt' => $public.DIRECTORY_SEPARATOR.'robots.txt'];
        $copy = $project.DIRECTORY_SEPARATOR.'robots.txt';

        if (is_file($copy) && self::key($copy) !== self::key($targets['public/robots.txt'])) {
            $targets['robots.txt'] = $copy;
        }

        return $targets;
    }

    /**
     * The administrator's warning when files could not be written: the settings are saved, what crawlers still read and
     * what to do.
     *
     * @param  list<string>  $failed  what write() answered
     */
    public static function warning(array $failed): string
    {
        return __('The site could not write :file, so search engines still read the old robots.txt. Let the web server write that file and its folder (hosting file manager: the site\'s user as owner, 644 for the file, 755 for the folder), then open Settings > Google & sharing again.', [
            'file' => implode(', ', $failed),
        ]);
    }

    /**
     * The administrator's notice when a robots.txt the owner wrote by hand was kept next to itself and replaced.
     *
     * @param  array<string, string>  $saved  what write() answered
     */
    public static function savedNotice(array $saved): string
    {
        return __('Your own robots.txt is kept as :file, and search engines now read the one made from these settings. To keep your own lines, copy them into Custom robots.txt (shown in Developer mode) and save.', [
            'file' => implode(', ', array_values($saved)),
        ]);
    }

    /**
     * The administrator's messages for a result of refresh() / write(): the warning, the notice; none when all is well.
     *
     * @param  array{failed: list<string>, saved: array<string, string>, left: list<string>}  $result
     * @return list<string>
     */
    public static function messages(array $result): array
    {
        return [
            ...($result['failed'] !== [] ? [self::warning($result['failed'])] : []),
            ...($result['saved'] !== [] ? [self::savedNotice($result['saved'])] : []),
        ];
    }

    /**
     * Writes $text into $path through a hidden temporary file of the same folder (never served: a dotfile), then a rename;
     * $text goes into the record ($record, RECORD) before the rename, so a file that holds it is always one the site
     * wrote. A file the owner wrote by hand (not isSites(), not in the record) is left ($keepOwners: 'left') or first
     * copied next to itself (OWNERS_COPY); the copy goes again when the file cannot be replaced.
     *
     * @param  string  $name  the path of the site ("public/robots.txt"): the record's key
     * @param  list<string>  $own
     * @param  array<string, list<string>>  $record  what record() read (added to)
     * @return bool|string true: the file holds $text; false: not written; 'left'; else the name of the owner's copy
     */
    private static function put(string $name, string $path, string $text, array $own, bool $keepOwners, array &$record): bool|string
    {
        $folder = dirname($path);

        if (! is_dir($folder)) {
            return false;
        }

        $current = is_file($path) ? @file_get_contents($path) : null;

        if ($current === $text) {
            // the settings' text, whoever put it there: the site's own from now on (the file is not touched)
            self::remember($record, $name, $text);

            return true;
        }

        if ($current === false) {
            // there and unreadable: whose it is cannot be told
            return false;
        }

        $copy = null;

        if ($current !== null && ! self::isSites($current, $own) && ! in_array(hash('sha256', $current), $record[$name] ?? [], true)) {
            if ($keepOwners) {
                return 'left';
            }

            $copy = $path.self::OWNERS_COPY.date('Ymd-His');
            $copy = file_exists($copy) ? $copy.'-'.bin2hex(random_bytes(3)) : $copy;

            if (@file_put_contents($copy, $current) !== strlen($current)) {
                @unlink($copy);

                return false;
            }

            @chmod($copy, 0644);
        }

        $temporary = $folder.DIRECTORY_SEPARATOR.'.robots.txt.'.bin2hex(random_bytes(6)).'.tmp';
        $written = false;

        try {
            if (@file_put_contents($temporary, $text) !== strlen($text)) {
                return false;
            }

            // readable by the web server whatever the PHP process's umask (a public file)
            @chmod($temporary, 0644);
            // remembered before the file is in place (a rename that fails leaves one entry more, never one less)
            self::remember($record, $name, $text);
            $written = @rename($temporary, $path);

            return $written ? ($copy !== null ? basename($copy) : true) : false;
        } catch (Throwable) {
            return false;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }

            if (! $written && $copy !== null && is_file($copy)) {
                // the owner's file is still there as it was
                @unlink($copy);
            }
        }
    }

    /**
     * The folder of RECORD and LOCK: the site's storage/app; with FOLDERS bound (a test, a tool) its 'storage' folder,
     * else <project>/storage/app: never the running site's own storage then.
     */
    private static function recordFolder(): string
    {
        $folders = app()->bound(self::FOLDERS) ? (array) app(self::FOLDERS) : [];

        if (isset($folders['storage'])) {
            return rtrim((string) $folders['storage'], '/\\');
        }

        return isset($folders['project'])
            ? rtrim((string) $folders['project'], '/\\').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'
            : storage_path('app');
    }

    /**
     * The record: per file, the sha256 of the texts the site wrote there (the newest last). Empty when there is none
     * yet or it cannot be read: then only isSites() tells the site's texts, as before the record.
     *
     * @return array<string, list<string>>
     */
    private static function record(): array
    {
        $file = self::recordFolder().DIRECTORY_SEPARATOR.self::RECORD;
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $record = [];

        foreach ((is_array($data) && is_array($data['written'] ?? null)) ? $data['written'] : [] as $name => $hashes) {
            if (is_string($name) && is_array($hashes)) {
                $record[$name] = array_values(array_filter($hashes, fn ($hash): bool => is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/', $hash) === 1));
            }
        }

        return $record;
    }

    /**
     * Puts $text into the record of the file $name (the newest last, the KEEP newest kept) and writes the record through
     * a temporary file of its folder and a rename (never half a file). Nothing is written when it is the newest already.
     * A record that cannot be written leaves a line in the log; the text then counts as the owner's later (kept, never
     * lost), as before the record.
     *
     * @param  array<string, list<string>>  $record
     */
    private static function remember(array &$record, string $name, string $text): void
    {
        $hash = hash('sha256', $text);
        $hashes = $record[$name] ?? [];

        if ($hashes !== [] && $hashes[count($hashes) - 1] === $hash) {
            return;
        }

        $record[$name] = array_slice([...array_values(array_diff($hashes, [$hash])), $hash], -self::KEEP);
        $folder = self::recordFolder();
        $file = $folder.DIRECTORY_SEPARATOR.self::RECORD;
        $json = (string) json_encode(['written' => $record], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if ((is_dir($folder) || @mkdir($folder, 0755, true)) && @file_put_contents($temporary, $json) === strlen($json) && @rename($temporary, $file)) {
            return;
        }

        if (is_file($temporary)) {
            @unlink($temporary);
        }

        Log::warning('The record of the texts the site wrote into robots.txt could not be written: '.$file.' (the web server may not write there).');
    }

    /**
     * The lock of the record (LOCK next to it), waiting up to 3 seconds for another writer. Null without it (a disk that
     * cannot lock files, a folder the site may not write): the files are written all the same.
     *
     * @return resource|null
     */
    private static function lock()
    {
        $folder = self::recordFolder();

        if (! is_dir($folder)) {
            @mkdir($folder, 0755, true);
        }

        $handle = @fopen($folder.DIRECTORY_SEPARATOR.self::LOCK, 'c');

        if ($handle === false) {
            return null;
        }

        $deadline = microtime(true) + 3;

        while (! @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if (! $wouldBlock || microtime(true) >= $deadline) {
                fclose($handle);

                return null;
            }

            usleep(50000);
        }

        return $handle;
    }

    /** @param  resource|null  $handle */
    private static function unlock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** One spelling of a path, to tell whether two paths name one file (the project folder served as public/). */
    private static function key(string $path): string
    {
        $real = realpath($path);

        return str_replace('\\', '/', $real !== false ? $real : $path);
    }
}
