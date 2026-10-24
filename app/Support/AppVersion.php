<?php

namespace App\Support;

/**
 * The AVDBCMS version of this code and where its releases come from (Tools > Updates). One source of truth: a class
 * constant, not config/ or .env, because a cached bootstrap/cache/config.php or an old .env would keep reporting the
 * old version after the files changed. The repository and the GitHub addresses are constants too, so neither a stolen
 * admin session nor a database change can point the updater at another source. Release tags are "v" + CURRENT.
 */
final class AppVersion
{
    public const CURRENT = '1.2.2';

    public const PRODUCT = 'AVDBCMS';

    public const REPOSITORY = 'avdbapi-com/avdbcms';

    public const API_BASE = 'https://api.github.com';

    public const WEB_BASE = 'https://github.com';

    /**
     * Where a release download may come from: the release page address on github.com, then the asset stores GitHub
     * redirects it to (the redirect target is a signed address that expires within the hour; it is never stored).
     */
    public const DOWNLOAD_HOSTS = [
        'https://github.com/',
        'https://release-assets.githubusercontent.com/',
        'https://objects.githubusercontent.com/',
    ];

    /** The public changelog of the help site; each release has the anchor "vX-Y-Z". */
    public const CHANGELOG_URL = 'https://help.avdbapi.com/documentation/avdbcms-changelog/';

    /** The help page about this updater (manual way, what to do when an update stops). */
    public const HELP_URL = 'https://help.avdbapi.com/documentation/avdbcms-one-click-update/';

    /**
     * A plain "X.Y.Z" version (no "v", no pre-release suffix, no leading zeros). \z, not $: "$" also matches before a
     * final line break, and a version ends up in folder and file names.
     */
    public static function isVersion(?string $version): bool
    {
        return is_string($version) && preg_match('/^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\z/', $version) === 1;
    }

    /** "v1.2.3" or "V1.2.3" -> "1.2.3"; any other tag (pre-release suffix, "1.2", "latest", empty) -> null. */
    public static function fromTag(?string $tag): ?string
    {
        if (! is_string($tag) || preg_match('/^[vV](\d+\.\d+\.\d+)\z/', $tag, $match) !== 1) {
            return null;
        }

        return self::isVersion($match[1]) ? $match[1] : null;
    }

    /** True when $version is a plain version newer than $than (the installed one by default). */
    public static function isNewer(?string $version, string $than = self::CURRENT): bool
    {
        return self::isVersion($version) && version_compare((string) $version, $than, '>');
    }

    /** The release tag of a version: "1.2.3" -> "v1.2.3". */
    public static function tag(string $version): string
    {
        return 'v'.$version;
    }

    /** The address every download of a release starts from (GitHub redirects it to its asset store). */
    public static function downloadBase(string $version): string
    {
        return self::WEB_BASE.'/'.self::REPOSITORY.'/releases/download/'.self::tag($version).'/';
    }

    /** The "What's new" link of a version: its entry in the help site's changelog. */
    public static function changelogUrl(string $version): string
    {
        return self::CHANGELOG_URL.'#v'.str_replace('.', '-', $version);
    }

    /** The update package of a version: avdbcms-update-X.Y.Z.zip (the full package without the bundled bin/). */
    public static function updateAsset(string $version): string
    {
        return 'avdbcms-update-'.$version.'.zip';
    }
}
