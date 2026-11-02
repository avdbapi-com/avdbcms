<?php

namespace App\Notifications\Support;

use App\Models\Setting;
use App\Support\ThemeTokens;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The colours of the member e-mails (member design 6.21): light colours on white (mail clients have no CSS variables
 * and most show mail on white), and ONE brand colour, the site theme's LIGHT-mode --avd-brand-fill with its label colour
 * --avd-on-brand, resolved on the server from the site's theme, colour style and the owner's own colours (the same
 * engine as the pages: ThemeTokens). Kept a day per theme and colours; a site whose theme cannot be resolved gets a
 * readable blue.
 */
final class MailTheme
{
    /** When the theme cannot be resolved: a blue with white text (8.6:1). */
    private const FALLBACK = ['brand' => '#1d5cfd', 'on_brand' => '#ffffff', 'link' => '#1d4ed8'];

    /**
     * @return array{brand: string, on_brand: string, link: string}
     */
    public static function colors(): array
    {
        try {
            $appearance = (array) Setting::getGroup('appearance');
        } catch (Throwable) {
            $appearance = [];
        }

        $themeId = (string) ($appearance['_theme_id'] ?? config('site.theme_preset') ?? $appearance['theme_preset'] ?? '');
        $flavorId = $appearance['_theme_flavor'] ?? config('site.theme_flavor') ?? $appearance['theme_flavor'] ?? null;
        $owner = array_intersect_key($appearance, ThemeTokens::OWNER_COLOURS);
        $autofix = (string) ($appearance['brand_contrast_autofix'] ?? '1');
        $key = 'mail.theme.colors.'.md5((string) json_encode([$themeId, $flavorId, $owner, $autofix]));

        try {
            return Cache::remember($key, now()->addDay(), fn (): array => self::resolve($themeId, is_string($flavorId) ? $flavorId : null, $owner, $autofix));
        } catch (Throwable) {
            return self::FALLBACK;
        }
    }

    /**
     * @param  array<string, mixed>  $owner
     * @return array{brand: string, on_brand: string, link: string}
     */
    private static function resolve(string $themeId, ?string $flavorId, array $owner, string $autofix): array
    {
        try {
            $selection = ThemeTokens::resolve($themeId, $flavorId);
            $theme = ThemeTokens::theme($selection['theme']);
            $flavor = $theme !== null ? ThemeTokens::flavor($theme, $selection['flavor']) : null;

            if ($theme === null || $flavor === null) {
                return self::FALLBACK;
            }

            $palette = [];

            foreach (ThemeTokens::customColours($owner, $theme, $flavor) as $role) {
                if ($role['mode'] === null || $role['mode'] === 'light') {
                    $palette[$role['role']] = $role['value'];
                }
            }

            $fit = ! in_array(strtolower(trim($autofix)), ['0', 'false', 'off', 'no'], true);
            $tokens = ThemeTokens::derive($theme, 'light', $flavor, $palette, $fit);
            $brand = self::hex($tokens['--avd-brand-fill'] ?? null);
            $onBrand = self::hex($tokens['--avd-on-brand'] ?? null);
            $link = self::hex($tokens['--avd-brand-ink'] ?? null);

            if ($brand === null || $onBrand === null) {
                return self::FALLBACK;
            }

            return ['brand' => $brand, 'on_brand' => $onBrand, 'link' => $link ?? self::FALLBACK['link']];
        } catch (Throwable) {
            return self::FALLBACK;
        }
    }

    /** A #rrggbb colour, or null (gradients, rgba() and other values are not used in mail). */
    private static function hex(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }
}
