<?php

namespace App\Support\Updater;

/**
 * Decides what an update does with each file (SwapEngine then does it). The installed manifest tells shipped files
 * from files the owner changed: live SHA-256 = installed hash -> shipped, replaced freely; different -> changed:
 *
 *  - files hosts and panels edit (HAND_EDITED): the live file stays, the new one is saved next to it as
 *    <name>.avdbcms-X.Y.Z and listed;
 *  - lang/<locale>.json: merged (TranslationMerge: the owner's texts stay, new keys come in);
 *    lang/<locale>/<group>.php: kept, with the new file next to it;
 *  - any other file (code): replaced (the old copy goes into the update backup) and listed.
 *
 * A live file that already has the new hash is left alone ("unchanged": not moved, not backed up), and so is a changed
 * file of the first two kinds when the release did not change it (its copy would only repeat what was shipped before).
 * Files the installed version shipped and this release no longer has (its "removed" list, or simply not in its files)
 * are deleted only in code folders (REMOVABLE), each into the backup first; a file the installed version never shipped
 * is the owner's and stays. Old public/build/assets files stay one more update, so pages cached in browsers keep their
 * CSS and JS: they are returned as kept_assets (state.json keeps them) and deleted by the next update. A file the release
 * renames by case only is replaced in place where case does not count; where it does, its old name is deleted too
 * (only while exactly as shipped, and only when it certainly is another file than the new one).
 *
 * Order of the moves: new public/build/assets files and the copies next to kept files first, before the site goes
 * down (phase "pre"); then vendor/, the other files, the removals, and the entry points public/index.php, index.php
 * and artisan (phase "main"); the manifest itself last (phase "last").
 */
final class SwapPlanner
{
    /** Files hosts and panels often edit (F8 9): replaced only while they are as shipped. */
    public const HAND_EDITED = [
        '.htaccess', 'public/.htaccess', 'web.config', 'public/web.config', 'nginx.conf', 'nginx-aapanel-rewrite.conf',
        'php-cli.ini', 'public/robots.txt', 'favicon.ico', 'public/favicon.ico', 'manifest.json', 'public/manifest.json',
        '404.html', 'public/404.html',
    ];

    /** Folders whose files a release may delete (its manifest lists them under "removed"). */
    public const REMOVABLE = ['app/', 'config/', 'database/migrations/', 'resources/', 'routes/', 'vendor/', 'public/build/'];

    public const ASSETS = 'public/build/assets/';

    /** Moved last: the files every request starts with. */
    public const ENTRY_POINTS = ['public/index.php', 'index.php', 'artisan'];

    /**
     * @param  callable(string): ?string  $live  path -> SHA-256 of the live file, null when there is none
     * @param  callable(string): ?string  $readLive  path -> content of the live file
     * @param  callable(string): ?string  $readNew  path -> content of the staged (new) file
     * @param  list<string>  $keptAssets  built assets an earlier update kept for cached pages (state.json kept_assets)
     * @param  (callable(string, string): bool)|null  $sameFile  do two paths (differing in case only) lead to one file?
     *                                                             (sameFile() on the site; null: always assumed)
     * @return array{ops: list<array<string, mixed>>, merged: array<string, string>, report: array<string, mixed>, entries: list<array<string, mixed>>, kept_assets: list<string>}
     *               ops for SwapEngine; merged: path -> merged JSON to write as merged/<path>; entries: backup.json lines;
     *               kept_assets: the old built assets this update leaves for cached pages (the next one deletes them)
     */
    public static function plan(Manifest $new, Manifest $installed, string $newManifestHash, callable $live, callable $readLive, callable $readNew, array $keptAssets = [], ?callable $sameFile = null): array
    {
        $suffix = '.avdbcms-'.$new->version;
        $groups = ['pre' => [], 'vendor' => [], 'main' => [], 'delete' => [], 'entry' => [], 'last' => []];
        $merged = [];
        $entries = [];
        $report = ['replaced' => 0, 'added' => 0, 'removed' => 0, 'unchanged' => 0, 'kept' => [], 'merged' => [], 'overwritten' => []];

        $files = $new->files;
        ksort($files, SORT_STRING);
        // A release may rename a file by case only (SEOController.php -> SeoController.php). Where the file system
        // ignores case (Windows, macOS) the new name finds the old file: it was shipped under its old name.
        $installedByCase = array_change_key_case($installed->files, CASE_LOWER);
        $newNames = [];

        foreach (array_keys($new->files) as $path) {
            $newNames[strtolower((string) $path)] = (string) $path;
        }

        foreach ($files as $path => $hash) {
            $path = (string) $path;

            if (Manifest::preserved($path) || $path === Manifest::FILE) {
                continue;
            }

            $now = $live($path);

            if ($now === $hash) {
                $report['unchanged']++;

                continue;
            }

            $op = ['do' => 'put', 'path' => $path, 'src' => 'staging/'.$path, 'old' => $now, 'new' => $hash];
            $shipped = $now !== null && $now === ($installed->hash($path) ?? $installedByCase[strtolower($path)] ?? null);

            if ($now === null && in_array($path, self::HAND_EDITED, true) && $installed->hash($path) !== null) {
                // A file hosts edit that the owner deleted (robots.txt for the SEO settings' own, an .htaccess an nginx
                // site does not use): it stays deleted. When the release changed it, its new copy goes next to it.
                if ($installed->hash($path) === $hash) {
                    $report['unchanged']++;

                    continue;
                }

                $sibling = $path.$suffix;
                $siblingNow = $live($sibling);

                if ($siblingNow !== $hash) {
                    $groups['pre'][] = ['do' => 'put', 'path' => $sibling, 'src' => 'staging/'.$path, 'old' => $siblingNow, 'new' => $hash];
                    $entries[] = ['path' => $sibling, 'action' => 'sibling', 'old' => $siblingNow, 'new' => $hash];
                }

                $report['kept'][] = ['path' => $path, 'copy' => $sibling];

                continue;
            }

            if ($now === null) {
                $groups[self::group($path, true)][] = $op;
                $entries[] = ['path' => $path, 'action' => 'added', 'old' => null, 'new' => $hash];
                $report['added']++;

                continue;
            }

            if ($shipped) {
                $groups[self::group($path, false)][] = $op;
                $entries[] = ['path' => $path, 'action' => 'replaced', 'old' => $now, 'new' => $hash];
                $report['replaced']++;

                continue;
            }

            // Changed by the owner (or never shipped): what kind of file decides.
            $kept = in_array($path, self::HAND_EDITED, true) || TranslationMerge::isLanguageFile($path) || TranslationMerge::isLanguageArray($path);

            if ($kept && $installed->hash($path) === $hash) {
                // The release ships this file exactly as the installed version did: nothing new, the owner's file stays.
                $report['unchanged']++;

                continue;
            }

            if (TranslationMerge::isLanguageFile($path)) {
                $mine = TranslationMerge::decode((string) $readLive($path));
                $theirs = TranslationMerge::decode((string) $readNew($path));

                if ($mine !== null && $theirs !== null) {
                    $result = TranslationMerge::merge($mine, $theirs);

                    if ($result['added'] === 0) {
                        // Nothing new for this language: the owner's file stays exactly as it is.
                        $report['unchanged']++;

                        continue;
                    }

                    $text = TranslationMerge::encode($result['data'], TranslationMerge::lineEnding((string) $readNew($path)));
                    $merged[$path] = $text;
                    $mergedHash = hash('sha256', $text);
                    $groups['main'][] = ['do' => 'put', 'path' => $path, 'src' => 'merged/'.$path, 'old' => $now, 'new' => $mergedHash];
                    $entries[] = ['path' => $path, 'action' => 'merged', 'old' => $now, 'new' => $mergedHash];
                    $report['merged'][] = ['path' => $path, 'added' => $result['added'], 'kept' => $result['kept']];

                    continue;
                }
            }

            if ($kept) {
                $sibling = $path.$suffix;
                $siblingNow = $live($sibling);

                // A copy with the new content may be there already (an earlier try): it stays as it is.
                if ($siblingNow !== $hash) {
                    $groups['pre'][] = ['do' => 'put', 'path' => $sibling, 'src' => 'staging/'.$path, 'old' => $siblingNow, 'new' => $hash];
                    $entries[] = ['path' => $sibling, 'action' => 'sibling', 'old' => $siblingNow, 'new' => $hash];
                }

                $report['kept'][] = ['path' => $path, 'copy' => $sibling];

                continue;
            }

            $groups[self::group($path, false)][] = $op;
            $entries[] = ['path' => $path, 'action' => 'replaced', 'old' => $now, 'new' => $hash];
            $report['replaced']++;
            $report['overwritten'][] = $path;
        }

        // Gone in this release: what the installed version shipped and this one does not have (its "removed" list says
        // the same for an update from the release right before it). Code folders only, and only files the installed
        // version shipped (anything else there is the owner's). Old built assets wait one more update; the ones an
        // earlier update kept (state.json) and the ones the installed release itself dropped go now.
        $gone = [];
        $oldAssets = [];
        $renamed = [];

        foreach (array_unique([...$new->removed, ...array_keys($installed->files)]) as $path) {
            $path = (string) $path;

            if (isset($new->files[$path]) || $installed->hash($path) === null || ! self::removable($path)) {
                continue;
            }

            // A path the release still has under another case is a rename by case (see below). Built assets have
            // hashed names; an old one waits for the next update as always.
            if (isset($newNames[strtolower($path)])) {
                if (! str_starts_with($path, self::ASSETS)) {
                    $renamed[$path] = $newNames[strtolower($path)];
                }

                continue;
            }

            if (str_starts_with($path, self::ASSETS)) {
                $oldAssets[$path] = true;

                continue;
            }

            $gone[$path] = true;
        }

        foreach ([...$installed->removed, ...$keptAssets] as $path) {
            $path = (string) $path;

            if (str_starts_with($path, self::ASSETS) && ! isset($new->files[$path]) && ! isset($oldAssets[$path]) && Manifest::safePath($path)) {
                $gone[$path] = true;
            }
        }

        ksort($gone, SORT_STRING);
        ksort($oldAssets, SORT_STRING);

        foreach (array_keys($gone) as $path) {
            $path = (string) $path;
            $now = $live($path);

            if ($now === null) {
                continue;
            }

            $groups['delete'][] = ['do' => 'delete', 'path' => $path, 'src' => null, 'old' => $now, 'new' => null];
            $entries[] = ['path' => $path, 'action' => 'removed', 'old' => $now, 'new' => null];
            $report['removed']++;

            if ($installed->hash($path) !== null && $installed->hash($path) !== $now) {
                $report['overwritten'][] = $path;
            }
        }

        // The old name of a file renamed by case only. Where the file system ignores case (Windows, macOS) it IS the new
        // file (the put above replaced it in place): nothing more to do, and deleting it would delete the new file.
        // Where case counts (Linux), the old file would stay behind next to the new one: it goes (into the backup, like
        // any removed file) when it is certainly another file than the new one and still exactly as shipped. A copy the
        // owner changed stays, listed next to the new name.
        ksort($renamed, SORT_STRING);

        foreach ($renamed as $old => $newName) {
            $old = (string) $old;
            $now = $live($old);

            if ($now === null || ! self::anotherFile($old, $newName, $live, $sameFile)) {
                continue;
            }

            if ($now !== $installed->hash($old)) {
                $report['kept'][] = ['path' => $old, 'copy' => $newName];

                continue;
            }

            $groups['delete'][] = ['do' => 'delete', 'path' => $old, 'src' => null, 'old' => $now, 'new' => null];
            $entries[] = ['path' => $old, 'action' => 'removed', 'old' => $now, 'new' => null];
            $report['removed']++;
        }

        $manifestNow = $live(Manifest::FILE);

        if ($manifestNow !== $newManifestHash) {
            $groups['last'][] = ['do' => 'put', 'path' => Manifest::FILE, 'src' => 'staging/'.Manifest::FILE, 'old' => $manifestNow, 'new' => $newManifestHash];
            $entries[] = ['path' => Manifest::FILE, 'action' => $manifestNow === null ? 'added' : 'replaced', 'old' => $manifestNow, 'new' => $newManifestHash];
        }

        $ops = [];

        foreach (['pre', 'vendor', 'main', 'delete', 'entry', 'last'] as $group) {
            $list = $groups[$group];

            if ($group === 'entry') {
                // public/index.php, then index.php, then artisan.
                usort($list, fn (array $a, array $b): int => array_search($a['path'], self::ENTRY_POINTS, true) <=> array_search($b['path'], self::ENTRY_POINTS, true));
            }

            foreach ($list as $op) {
                $ops[] = ['i' => count($ops), 'phase' => in_array($group, ['pre', 'last'], true) ? $group : 'main'] + $op;
            }
        }

        $keep = array_values(array_filter(array_map('strval', array_keys($oldAssets)), fn (string $path): bool => $live($path) !== null));

        return ['ops' => $ops, 'merged' => $merged, 'report' => $report, 'entries' => $entries, 'kept_assets' => $keep];
    }

    /** Where a put goes: new built assets before the site goes down, vendor/ first, the entry points last. */
    private static function group(string $path, bool $added): string
    {
        if ($added && str_starts_with($path, self::ASSETS)) {
            return 'pre';
        }

        if (in_array($path, self::ENTRY_POINTS, true)) {
            return 'entry';
        }

        return str_starts_with($path, 'vendor/') ? 'vendor' : 'main';
    }

    /**
     * Do two paths lead to one file (one name differing from the other in case, on a file system that ignores case)?
     * Told by the files' device and inode numbers. Where either is no file, or the system gives no inode numbers (0),
     * the answer is yes: the old name of a rename by case is then left alone rather than risk deleting the new file.
     */
    public static function sameFile(string $a, string $b): bool
    {
        clearstatcache(true, $a);
        clearstatcache(true, $b);
        $one = @stat($a);
        $two = @stat($b);

        if (! is_array($one) || ! is_array($two) || (int) $one['ino'] === 0 || (int) $two['ino'] === 0) {
            return true;
        }

        return $one['dev'] === $two['dev'] && $one['ino'] === $two['ino'];
    }

    /**
     * Is the old name of a file renamed by case only (it has a file) certainly another file than the new name? Yes when
     * the new name finds no file (a file system that ignores case would find the old one under it), or when both find
     * one and $sameFile says they are two. Anything else (no way to tell): no.
     */
    private static function anotherFile(string $old, string $new, callable $live, ?callable $sameFile): bool
    {
        if ($live($new) === null) {
            return true;
        }

        return $sameFile !== null && $sameFile($old, $new) === false;
    }

    private static function removable(string $path): bool
    {
        if (Manifest::preserved($path)) {
            return false;
        }

        foreach (self::REMOVABLE as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
