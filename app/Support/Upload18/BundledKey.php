<?php

namespace App\Support\Upload18;

use App\Models\Setting;
use App\Support\Updater\PlanStore;
use App\Support\Updater\UpdatePaths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PharData;
use Throwable;
use ZipArchive;

/**
 * The Upload18 key that new sites start with (the upload18_api_key row of database/install/example.sql).
 *
 * AVDBCMS 1.2.0, 1.2.1 and 1.2.2 shipped the account's master key there. Upload18 regenerated it on 25 October 2026, so
 * that key no longer works: sites that still have it cannot upload. From 1.2.3 the package ships a separate restricted
 * key ("AVDBCMS public download": Upload + Read only, and browsers only ever get short-lived upload tokens made with
 * it). replaceRetired() gives a site that still has exactly the retired key the shipped one, read from the package at
 * that moment, so no key is ever written in code: only the SHA-256 of the retired key is kept here. Any other value (an
 * owner's own key, an empty field) is never touched.
 *
 * Where the shipped key is read: the site's own database/install/example.sql (a new install, a manual update that
 * copied every file), else the update package of the one-click update being finished (the updater never writes
 * database/install/, but its zip, already checked against the release's SHA-256, is still in the work folder while
 * Finish runs the migrations).
 */
final class BundledKey
{
    /** SHA-256 of the master key that example.sql of AVDBCMS 1.2.0 to 1.2.2 carried (retired by Upload18). */
    public const RETIRED_SHA256 = [
        'aaa1a909e704524f86bb032188a998c37e0a320e7bb55d1bf54f2aa4b41a0864',
    ];

    public const SETTING = 'upload18_api_key';

    public const GROUP = 'uploads';

    public const AUDIT_ACTION = 'update.upload18_key';

    private const EXAMPLE_SQL = 'database/install/example.sql';

    /** Tests point these elsewhere; null = the site's own. */
    public static ?array $retiredHashes = null;

    public static ?string $exampleSqlPath = null;

    public static ?UpdatePaths $updatePaths = null;

    /** Tests: read the update package as a host without ZipArchive does (PharData), as the updater can. */
    public static bool $withoutZipArchive = false;

    public static function isRetired(?string $key): bool
    {
        $key = trim((string) $key);

        if ($key === '') {
            return false;
        }

        $hash = hash('sha256', $key);

        foreach (self::$retiredHashes ?? self::RETIRED_SHA256 as $retired) {
            if (is_string($retired) && hash_equals(strtolower($retired), $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The site's upload18_api_key that is still exactly the retired one gets the key the package ships. Returns what
     * happened: replaced, kept (another value), empty (no key saved), no_shipped_key (the package has no usable key).
     */
    public static function replaceRetired(): string
    {
        if (! Schema::hasTable('settings')) {
            return 'kept';
        }

        $row = DB::table('settings')->where('key', self::SETTING)->first(['id', 'value', 'group']);
        $stored = $row !== null ? (string) ($row->value ?? '') : null;

        if ($row !== null && trim($stored) === '') {
            return 'empty';
        }

        // No row at all: the site runs on UPLOAD18_API_KEY of .env (a database row, when there is one, wins).
        $current = $row !== null ? $stored : (string) config('services.upload18.api_key', '');

        if (! self::isRetired($current)) {
            return $current === '' ? 'empty' : 'kept';
        }

        $shipped = self::shippedKey();

        if ($shipped === null) {
            Log::warning('AVDBCMS: the site still has the Upload18 key of 1.2.0-1.2.2 (no longer valid) and the package carries no new one; uploads need a key in Settings > Videos & uploads.');

            return 'no_shipped_key';
        }

        if ($row !== null) {
            $updated = DB::table('settings')
                ->where('id', $row->id)
                ->where('value', $stored)
                ->update(['value' => $shipped, 'updated_at' => now()]);

            if ($updated < 1) {
                return 'kept';
            }
        } else {
            DB::table('settings')->insertOrIgnore([
                'key' => self::SETTING,
                'value' => $shipped,
                'group' => self::GROUP,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The site reads the settings from the cache on every request.
        Setting::clearCache([self::SETTING], [self::GROUP, (string) ($row->group ?? self::GROUP)]);

        try {
            if (Schema::hasTable('admin_audit_logs')) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::AUDIT_ACTION,
                    'details' => 'The Upload18 key that came with AVDBCMS 1.2.0 to 1.2.2 no longer works: the site now uses the new shared AVDBCMS key (Upload + Read only). For your own site, create your own key in Upload18 and paste it in Settings > Videos & uploads.',
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => now(),
                ]);
            }

            Log::info('AVDBCMS: the retired Upload18 key of 1.2.0-1.2.2 was replaced by the shipped AVDBCMS key.');
        } catch (Throwable) {
            // The record is a courtesy; the key is replaced.
        }

        return 'replaced';
    }

    /**
     * The upload18_api_key the package ships, or null when it ships none this method may use (no file, no row, empty, a
     * value that cannot be a key, or the retired key itself).
     */
    public static function shippedKey(): ?string
    {
        foreach (self::sources() as $source) {
            try {
                $key = $source();
            } catch (Throwable) {
                $key = null;
            }

            if ($key !== null && self::usable($key)) {
                return $key;
            }
        }

        return null;
    }

    /** The value of the upload18_api_key row in an example.sql stream, or null. Reads line by line (the file is large). */
    public static function keyFromSqlStream($stream): ?string
    {
        if (! is_resource($stream)) {
            return null;
        }

        while (($line = fgets($stream, 1024 * 1024)) !== false) {
            if (! str_contains($line, "'".self::SETTING."'")) {
                continue;
            }

            if (preg_match("/\(\s*\d+\s*,\s*'".self::SETTING."'\s*,\s*'((?:[^'\\\\]|\\\\.|'')*)'/", $line, $match) === 1) {
                return stripcslashes(str_replace("''", "'", $match[1]));
            }
        }

        return null;
    }

    /** @return list<callable(): ?string> */
    private static function sources(): array
    {
        return [
            function (): ?string {
                $path = self::$exampleSqlPath ?? base_path(self::EXAMPLE_SQL);

                if (! is_file($path)) {
                    return null;
                }

                $stream = @fopen($path, 'rb');

                try {
                    return self::keyFromSqlStream($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            },
            fn (): ?string => self::keyFromUpdatePackage(),
        ];
    }

    /** The key in database/install/example.sql of the update package whose Finish is running now. */
    private static function keyFromUpdatePackage(): ?string
    {
        $paths = self::$updatePaths ?? UpdatePaths::forApp();
        $plan = PlanStore::read($paths->plan());

        if (! is_array($plan) || ($plan['kind'] ?? 'update') !== 'update' || ! in_array($plan['status'] ?? '', ['swapped', 'migrating'], true)) {
            return null;
        }

        $job = (string) ($plan['job'] ?? '');
        $name = (string) ($plan['release']['zip']['name'] ?? '');

        if ($job === '' || $name === '' || str_contains($job, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            return null;
        }

        $zip = $paths->job($job).'/'.$name;

        if (! is_file($zip)) {
            return null;
        }

        if (class_exists(ZipArchive::class) && ! self::$withoutZipArchive) {
            $archive = new ZipArchive();

            if ($archive->open($zip, ZipArchive::RDONLY) !== true) {
                return null;
            }

            try {
                $stream = $archive->getStream(self::EXAMPLE_SQL);

                try {
                    return self::keyFromSqlStream($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            } finally {
                $archive->close();
            }
        }

        if (class_exists(PharData::class)) {
            $stream = @fopen('phar://'.str_replace('\\', '/', $zip).'/'.self::EXAMPLE_SQL, 'rb');

            try {
                return self::keyFromSqlStream($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return null;
    }

    /** A value that can be an Upload18 key (printable, no spaces or quotes) and is not the retired one. */
    private static function usable(string $key): bool
    {
        $key = trim($key);

        return $key !== ''
            && strlen($key) >= 16
            && strlen($key) <= 255
            && preg_match('/^[A-Za-z0-9._~+\/=-]+$/', $key) === 1
            && ! self::isRetired($key);
    }
}
