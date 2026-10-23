<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Unpublished changes of the Appearance pages (interface I9). Every admin has one draft: the settings they changed,
 * kept in the cache for a day and never in the settings table. The admin sees it on the real site through a signed
 * link (?avd_draft=token) that only works for them, for 30 minutes, and only while the draft is the one it was made
 * for; everybody else with the same link gets the published design.
 *
 * Token: base64url("user|expires|hash") . "." . base64url(HMAC-SHA256(APP_KEY, "user|expires|hash")), where hash is
 * the SHA-256 of the draft's values.
 */
final class AppearanceDraft
{
    public const QUERY = 'avd_draft';

    /**
     * Query keys that preview a design for one request: such responses are private and never edge-cached. Besides
     * these, the name of every appearance setting is one (the layout, ThemeStructure and the page views read
     * ?theme_preset=, ?header_style=, ?sidebar_style=, ?typography_pairing=, ?<effect>_enabled= ... for one request),
     * see isPreviewRequest(). This list holds the draft link and the names that are not setting keys: the theme
     * mode, the daily design day and the page previews of the homepage (?layout=, ?hero=) and the watch page.
     */
    public const PREVIEW_KEYS = ['avd_draft', 'theme', 'theme_preset', 'theme_flavor', 'theme_mode', 'mode', 'daily_design', 'preview_day', 'layout', 'hero', 'watch_layout'];

    public const TOKEN_MINUTES = 30;

    public const STORE_HOURS = 24;

    private const CACHE_KEY = 'admin_appearance_draft:';

    /**
     * A signed preview token for this admin's draft values.
     */
    public static function sign(int $userId, array $draft, ?int $expires = null): string
    {
        $expires ??= now()->addMinutes(self::TOKEN_MINUTES)->getTimestamp();
        $payload = $userId.'|'.$expires.'|'.self::hash(self::valuesOf($draft));

        return self::encode($payload).'.'.self::encode(hash_hmac('sha256', $payload, self::key(), true));
    }

    /**
     * The draft values a token shows to this user, or null (bad signature, another user, expired, or the draft has
     * changed, been published or discarded since).
     *
     * @return array<string, ?string>|null
     */
    public static function verify(?string $token, User|int|null $user): ?array
    {
        $status = self::status($token, $user);

        return $status['state'] === 'ok' ? $status['values'] : null;
    }

    /**
     * What a token is worth for this user: 'ok' (values = the draft), 'stale' (signed for this user, but expired or
     * made for an older version of the draft) or 'invalid'.
     *
     * @return array{state: string, values: array<string, ?string>}
     */
    public static function status(?string $token, User|int|null $user): array
    {
        $invalid = ['state' => 'invalid', 'values' => []];
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        if (! is_string($token) || $token === '' || strlen($token) > 512 || ! $userId || self::key() === '') {
            return $invalid;
        }

        [$payload, $mac] = array_map(fn (string $part): ?string => self::decode($part), explode('.', $token, 2) + [1 => '']);

        if ($payload === null || $mac === null || ! hash_equals(hash_hmac('sha256', $payload, self::key(), true), $mac)) {
            return $invalid;
        }

        $parts = explode('|', $payload);

        if (count($parts) !== 3 || (int) $parts[0] !== (int) $userId) {
            return $invalid;
        }

        $values = self::valuesOf(self::get((int) $userId));

        if ((int) $parts[1] < now()->getTimestamp() || $values === [] || ! hash_equals($parts[2], self::hash($values))) {
            return ['state' => 'stale', 'values' => []];
        }

        return ['state' => 'ok', 'values' => $values];
    }

    /**
     * This admin's draft: ['values' => [key => value|null], 'action' => ?array, 'updated_at' => int]. A null value
     * means "back to the default" (for a colour: the colour style's).
     */
    public static function get(int $userId): array
    {
        try {
            $draft = Cache::get(self::CACHE_KEY.$userId);
        } catch (Throwable) {
            $draft = null;
        }

        return is_array($draft) && is_array($draft['values'] ?? null) ? $draft : ['values' => [], 'action' => null, 'updated_at' => 0];
    }

    /**
     * Keep the draft for STORE_HOURS (an empty draft is forgotten). False when the cache cannot store it.
     */
    public static function put(int $userId, array $draft): bool
    {
        if (self::valuesOf($draft) === []) {
            self::forget($userId);

            return true;
        }

        $draft['updated_at'] = now()->getTimestamp();

        try {
            return Cache::put(self::CACHE_KEY.$userId, $draft, now()->addHours(self::STORE_HOURS));
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    public static function forget(int $userId): void
    {
        try {
            Cache::forget(self::CACHE_KEY.$userId);
        } catch (Throwable) {
        }
    }

    /**
     * Change this admin's draft. $edits:
     *  - set:   [key => value] a new value (a value equal to the published one takes the key out of the draft);
     *  - unset: [keys] back to the published value;
     *  - reset: [keys] back to the default (appearance keys are then no longer stored; a colour comes from the colour
     *           style again);
     *  - look:  a Complete look id, or theme: [id, flavor, layouts] - replaces the look or theme picked before (the
     *           settings it brought that the admin did not change since);
     *  - cancel: true drops the picked look or theme.
     * Only keys of the Appearance pages are accepted (expert ones in Developer mode), each checked with the settings
     * save's rules. Returns the stored draft and the errors (key => message).
     *
     * @return array{draft: array, errors: array<string, string>, stored: bool}
     */
    public static function edit(int $userId, array $edits, bool $developer = false): array
    {
        $draft = self::get($userId);
        $values = $draft['values'];
        $action = is_array($draft['action'] ?? null) ? $draft['action'] : null;
        $editable = array_flip(array_diff(AppearancePages::editableKeys($developer), AppearancePages::SERVER_KEYS));
        $errors = [];
        $picked = null;

        if (is_string($edits['look'] ?? null) && $edits['look'] !== '') {
            $lookValues = AppearancePages::lookValues($edits['look']);
            $lookValues === null
                ? $errors['look'] = __('This look is not available.')
                : $picked = ['type' => 'look', 'id' => (string) MasterExperienceCatalog::resolveId($edits['look']), 'values' => $lookValues];
        } elseif (is_array($edits['theme'] ?? null)) {
            $theme = (string) ($edits['theme']['id'] ?? '');
            $flavor = is_string($edits['theme']['flavor'] ?? null) ? $edits['theme']['flavor'] : null;
            $layouts = filter_var($edits['theme']['layouts'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $themeValues = AppearancePages::themeValues($theme, $flavor, $layouts);
            $themeValues === null
                ? $errors['theme'] = __('This theme is not available.')
                : $picked = ['type' => 'theme', 'id' => (string) $themeValues['theme_preset'], 'flavor' => (string) $themeValues['theme_flavor'], 'layouts' => $layouts, 'values' => $themeValues];
        }

        if ($picked !== null || ! empty($edits['cancel'])) {
            foreach ($action['keys'] ?? [] as $key) {
                unset($values[$key]);
            }

            $action = null;
        }

        if ($picked !== null) {
            $values = array_replace($values, $picked['values']);
            $picked['keys'] = array_keys($picked['values']);
            unset($picked['values']);
            $action = $picked;
        }

        $touched = [];
        $schema = null;

        foreach ((array) ($edits['set'] ?? []) as $key => $value) {
            $key = (string) $key;

            if (! isset($editable[$key]) || (! is_scalar($value) && $value !== null)) {
                $errors[$key] = __('This setting cannot be changed here.');

                continue;
            }

            // Empty fields arrive as null (ConvertEmptyStringsToNull): an emptied text is an empty text.
            $value = AppearancePages::normalize($key, $value ?? '');
            $schema ??= app(\App\Http\Controllers\Admin\SettingsController::class)->saveSchema();
            $message = AppearancePages::invalid($key, $value, $schema);

            if ($message !== null) {
                $errors[$key] = $message;

                continue;
            }

            $values[$key] = $value;
            $touched[] = $key;
        }

        foreach ((array) ($edits['unset'] ?? []) as $key) {
            if (is_string($key) && isset($editable[$key])) {
                unset($values[$key]);
                $touched[] = $key;
            }
        }

        foreach ((array) ($edits['reset'] ?? []) as $key) {
            if (is_string($key) && isset($editable[$key])) {
                $values[$key] = self::groupOf($key) === 'appearance' ? null : AppearancePages::defaultOf($key);
                $touched[] = $key;
            }
        }

        // A setting the admin changed by hand no longer belongs to the picked look or theme.
        if ($action !== null && $touched !== []) {
            $action['keys'] = array_values(array_diff($action['keys'], $touched));
        }

        // The phone bottom bar's old twin follows it (as the settings save does).
        if (array_key_exists('mobile_bottom_nav_enabled', $values)) {
            $values['mobile_bottom_dock_enabled'] = $values['mobile_bottom_nav_enabled'];
        }

        $draft = ['values' => self::withoutNoops($values), 'action' => $action];

        return ['draft' => $draft, 'errors' => $errors, 'stored' => self::put($userId, $draft)];
    }

    /**
     * The draft values that would change something: a value equal to the published one, or a reset of a key that is
     * not stored, is left out.
     *
     * @param  array<string, ?string>  $values
     * @return array<string, ?string>
     */
    public static function withoutNoops(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $stored = Setting::getAllGrouped();
        $published = AppearancePages::published(array_keys($values));

        foreach ($values as $key => $value) {
            $isStored = array_key_exists($key, (array) ($stored[self::groupOf($key)] ?? []));

            if (($value === null && ! $isStored) || ($value !== null && $value === $published[$key])) {
                unset($values[$key]);
            }
        }

        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * Show draft values on this request only: appearance settings through the same code that applies the published
     * ones (AppearanceSettings::applyToConfig, so the page head, previews and daily designs see them), settings of
     * other groups as config('site.<key>') with the type the site reads them in. Keys the site does not read through
     * config are left out of the preview (publishing still saves them).
     *
     * @param  array<string, ?string>  $values
     */
    public static function apply(array $values): void
    {
        $appearance = [];

        foreach ($values as $key => $value) {
            $group = self::groupOf((string) $key);

            if ($group === 'appearance') {
                $appearance[$key] = $value;
            } elseif ($group !== null && $value !== null && Config::has('site.'.$key)) {
                $current = Config::get('site.'.$key);
                Config::set('site.'.$key, match (true) {
                    is_bool($current) => (string) $value === '1',
                    is_int($current) => (int) $value,
                    default => (string) $value,
                });
            }
        }

        AppearanceSettings::applyToConfig(AppearanceSettings::applyOverrides(Setting::getGroup('appearance'), $appearance));
    }

    /**
     * The settings group of a key the Appearance pages edit (config/admin_settings.php), or null.
     */
    public static function groupOf(string $key): ?string
    {
        $group = config("admin_settings.settings.{$key}.group");

        if (is_string($group)) {
            return $group;
        }

        return array_key_exists($key, AppearanceSettings::defaults()) ? 'appearance' : null;
    }

    /**
     * Whether the request previews a design for one visitor (?avd_draft=, ?theme=, ?theme_mode=, or the name of any
     * appearance setting).
     */
    public static function isPreviewRequest(Request $request): bool
    {
        if ($request->query->count() === 0) {
            return false;
        }

        $keys = self::previewKeys();

        foreach ($request->query->keys() as $key) {
            if (isset($keys[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * PREVIEW_KEYS and every appearance setting key, as a lookup table.
     *
     * @return array<string, true>
     */
    public static function previewKeys(): array
    {
        static $keys = null;

        return $keys ??= array_fill_keys(array_merge(self::PREVIEW_KEYS, array_keys(AppearanceSettings::defaults())), true);
    }

    /**
     * A preview response is for one visitor: private, never stored by a browser or a CDN, never indexed.
     */
    public static function markPrivate(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->remove('Expires');

        return $response;
    }

    /**
     * $url with the draft token (replacing any token it had).
     */
    public static function previewUrl(string $url, ?string $token): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        unset($query[self::QUERY]);

        if ($token !== null && $token !== '') {
            $query[self::QUERY] = $token;
        }

        $base = preg_replace('/[?#].*$/', '', $url);

        return $base.($query !== [] ? '?'.http_build_query($query) : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * @return array<string, ?string>
     */
    public static function valuesOf(array $draft): array
    {
        $values = array_key_exists('values', $draft) && is_array($draft['values']) ? $draft['values'] : $draft;
        ksort($values, SORT_STRING);

        return $values;
    }

    private static function hash(array $values): string
    {
        return hash('sha256', (string) json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function key(): string
    {
        $key = (string) config('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return $key;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
