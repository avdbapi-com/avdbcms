<?php

namespace App\Support\Studio;

use App\Support\AppearanceSettings;
use Throwable;

/**
 * The studio frame's switches (member design 6.1, 5.5, 5.9), read EXACTLY as components/layout.blade.php
 * reads the same settings (its $configBool($key, $appearanceBool($key))): the one-request preview (?key=), else the
 * setting, else the active design's default. So what a studio page prints and what the layout marks never disagree.
 *
 * tabBarEnabled(): whether member back-office pages print the studio phone tab bar (.mk-tabbar, layouts/user.blade.php):
 * the admin's "Mobile Bottom Nav Bar" (mobile_bottom_nav_enabled). The layout's marker body.has-studio-tabbar
 * uses the same condition for type="user" pages.
 * enhancedSelects(): the admin's "Searchable drop-down lists" (enhanced_selects_enabled), the layout's
 * html[data-avd-enhanced-selects]: the Playlists multi-select is a TomSelect while it is on, a check-box list when off.
 */
final class StudioChrome
{
    public static function tabBarEnabled(): bool
    {
        return self::layoutBool('mobile_bottom_nav_enabled');
    }

    public static function enhancedSelects(): bool
    {
        return self::layoutBool('enhanced_selects_enabled');
    }

    public static function layoutBool(string $key): bool
    {
        $query = request()->query($key);

        if (is_scalar($query)) {
            return self::truthy($query);
        }

        $fallback = true;

        try {
            $appearance = method_exists(AppearanceSettings::class, 'current') ? AppearanceSettings::current() : [];
            $fallback = AppearanceSettings::bool($appearance, $key);
        } catch (Throwable) {
            $fallback = true;
        }

        $value = config('site.'.$key, $fallback);

        return is_bool($value) ? $value : self::truthy($value);
    }

    private static function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
