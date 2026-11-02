<?php

namespace App\Support;

use App\Models\Language;
use App\Models\Setting;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Where AppServiceProvider::applySettings() reads the admin's settings from: the stored settings by group, one group,
 * and the languages the admin turned on (null without a languages table).
 *
 *  - web(): what every web request has always read, through the caches it fills (Setting::getAllGrouped(),
 *    Setting::getGroup(), Language::getAllCached(), "schema.has_languages_table"): the same reads, the same queries;
 *  - console(): a console process that does the site's work (App\Support\ConsoleSiteSettings) only READS those caches
 *    and else asks the tables, without writing a cache: a cron job may run as another system user (root) and a cache
 *    file it wrote could not be replaced or forgotten by the web afterwards, and a process that starts while the
 *    database is being built must not leave an empty answer cached (the reasons of site_default_locale()). Null when
 *    there is nothing the admin saved: no settings table yet (a fresh install, a database being built) or an empty one.
 */
final class SiteSettingsSource
{
    /**
     * @param  Closure(): array<string, array<string, mixed>>  $all
     * @param  Closure(string): array<string, mixed>  $group
     * @param  Closure(): ?Collection  $languages
     */
    private function __construct(
        private readonly Closure $all,
        private readonly Closure $group,
        private readonly Closure $languages,
    ) {
    }

    public static function web(): self
    {
        return new self(
            static fn (): array => Setting::getAllGrouped(),
            static fn (string $name): array => Setting::getGroup($name),
            // Schema::hasTable is cached to avoid a DB introspection query on every request.
            static fn (): ?Collection => Cache::rememberForever('schema.has_languages_table', static fn (): bool => Schema::hasTable('languages'))
                ? Language::getAllCached()
                : null,
        );
    }

    public static function console(): ?self
    {
        $all = self::readAll();

        if ($all === null || $all === []) {
            return null;
        }

        $languages = self::readLanguages();

        return new self(
            static fn (): array => $all,
            static fn (string $name): array => is_array($all[$name] ?? null) ? $all[$name] : [],
            static fn (): ?Collection => $languages,
        );
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return ($this->all)();
    }

    /** @return array<string, mixed> */
    public function group(string $name): array
    {
        return ($this->group)($name);
    }

    /** The languages the admin turned on, in their order; null without a languages table. */
    public function languages(): ?Collection
    {
        return ($this->languages)();
    }

    /** What the settings and the languages are now: a long-running process applies them again when this changes. */
    public function fingerprint(): string
    {
        $languages = $this->languages();

        return md5(serialize([
            $this->all(),
            $languages?->map(static fn ($language): array => [
                (string) data_get($language, 'code'),
                (string) data_get($language, 'label'),
                (string) data_get($language, 'emoji'),
                (string) data_get($language, 'direction'),
                (bool) data_get($language, 'is_default'),
            ])->values()->all(),
        ]));
    }

    /**
     * The settings by group: the web's cache, else the table (null when there is no table). Never writes the cache.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private static function readAll(): ?array
    {
        try {
            $cached = Cache::get('settings.__all_grouped');

            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        } catch (Throwable) {
            // an unreadable cache: the table answers
        }

        try {
            return Setting::query()
                ->get(['key', 'value', 'group'])
                ->groupBy('group')
                ->map(static fn ($items) => $items->pluck('value', 'key')->toArray())
                ->toArray();
        } catch (Throwable) {
            return null;
        }
    }

    /** The active languages: the web's cache, else the table; null without a table. Never writes the cache. */
    private static function readLanguages(): ?Collection
    {
        try {
            $cached = Cache::get('languages_all_collection');

            if ($cached instanceof Collection) {
                return $cached;
            }

            $hasTable = Cache::get('schema.has_languages_table');

            if ($hasTable === false || ($hasTable === null && ! Schema::hasTable('languages'))) {
                return null;
            }

            return Language::query()->active()->ordered()->get(['code', 'label', 'emoji', 'direction', 'is_default']);
        } catch (Throwable) {
            return null;
        }
    }
}
