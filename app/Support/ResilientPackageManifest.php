<?php

namespace App\Support;

use Illuminate\Foundation\PackageManifest;
use Throwable;

/**
 * Package manifest that skips discovered providers whose classes cannot be loaded.
 *
 * Uploaded copies and release archives regularly carry a vendor/ tree whose
 * composer/installed.json still lists development-only packages (ide-helper,
 * debugbar, collision) while the autoloader was dumped with --no-dev. Laravel's
 * package discovery trusts installed.json, so the first request after the
 * installer runs `optimize:clear` would register a provider class that does not
 * exist and every page would fail with "Class ... not found". Filtering those
 * entries out keeps the no-config install working without touching vendor/.
 */
class ResilientPackageManifest extends PackageManifest
{
    /**
     * Get the current package manifest with unloadable providers and aliases removed.
     *
     * @return array
     */
    protected function getManifest()
    {
        $manifest = parent::getManifest();

        if (! is_array($manifest)) {
            return $manifest;
        }

        foreach ($manifest as $package => $configuration) {
            if (! is_array($configuration)) {
                continue;
            }

            if (isset($configuration['providers']) && is_array($configuration['providers'])) {
                $manifest[$package]['providers'] = array_values(array_filter(
                    $configuration['providers'],
                    static fn ($provider) => self::loadable($provider)
                ));
            }

            if (isset($configuration['aliases']) && is_array($configuration['aliases'])) {
                $manifest[$package]['aliases'] = array_filter(
                    $configuration['aliases'],
                    static fn ($class) => self::loadable($class)
                );
            }
        }

        // Cache the cleaned copy so the class checks run once per request.
        return $this->manifest = $manifest;
    }

    /**
     * Determine whether a class can actually be autoloaded on this host.
     */
    private static function loadable(mixed $class): bool
    {
        if (! is_string($class) || $class === '') {
            return false;
        }

        try {
            return class_exists($class);
        } catch (Throwable) {
            // A class whose parent or interface is missing throws while autoloading.
            return false;
        }
    }
}
