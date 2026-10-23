<?php

namespace App\Console\Commands;

use App\Support\ThemeTokens;
use Illuminate\Console\Command;

/**
 * Writes resources/css/themes/<id>.css and resources/css/themes/<id>/<flavor>.css (one per colour style other
 * than the signature) for every v2 theme (App\Support\ThemeTokens::files). The files are committed and built by
 * Vite (vite.config.ts, one entry per file) into hashed /build/assets files that App\Support\ThemeCss links.
 * Run it after changing the theme catalogue, the colour engine or a hero decoration (resources/css/themes/hero-decos),
 * then npm run build.
 */
class BuildThemeTokens extends Command
{
    protected $signature = 'avd:themes:build
        {--theme=* : Only these theme ids}
        {--check : Compare with the files on disk instead of writing them (exit 1 when one is missing or stale)}
        {--path= : Output directory (default resources/css/themes)}';

    protected $description = 'Generate the per-theme token CSS files from the theme catalogue and the colour engine';

    public function handle(): int
    {
        $themes = ThemeTokens::themes();

        if ($themes === []) {
            $this->error('The theme catalogue is empty: no theme to build.');

            return self::FAILURE;
        }

        $only = array_filter((array) $this->option('theme'));
        $unknown = array_diff($only, array_keys($themes));

        if ($unknown !== []) {
            $this->error('Unknown theme id(s): '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $dir = rtrim((string) ($this->option('path') ?: resource_path('css/themes')), '/\\');
        $check = (bool) $this->option('check');

        if (! $check && ! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create {$dir}.");

            return self::FAILURE;
        }

        // Hero decorations (resources/css/themes/hero-decos/<deco>.css) ship inside their themes' token files: a
        // rule that is not keyed on its decoration would style every theme's hero.
        $decoProblems = [];

        foreach (glob(resource_path('css/themes/'.ThemeTokens::HERO_DECO_DIR.'/*.css')) ?: [] as $decoFile) {
            foreach (ThemeTokens::heroDecoProblems(basename($decoFile, '.css'), (string) file_get_contents($decoFile)) as $problem) {
                $decoProblems[] = basename($decoFile).': '.$problem;
            }
        }

        if ($decoProblems !== []) {
            $this->error('Hero decoration CSS that cannot ship: '.implode('; ', $decoProblems));

            return self::FAILURE;
        }

        $rows = [];
        $stale = [];
        $expected = [];
        $gzip = fn (string $css): int => strlen((string) gzencode((string) preg_replace(['#/\*.*?\*/#s', '/\s*([{};:,])\s*/', '/\s+/'], ['', '$1', ' '], $css), 9));

        foreach ($themes as $id => $theme) {
            if ($only !== [] && ! in_array($id, $only, true)) {
                continue;
            }

            $files = ThemeTokens::files($theme);
            $states = [];

            foreach ($files as $path => $css) {
                $expected[] = $path;
                $file = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
                $current = is_file($file) ? (string) file_get_contents($file) : null;
                $state = $current === null ? 'missing' : ($current === $css ? 'up to date' : 'stale');

                if ($check) {
                    if ($state !== 'up to date') {
                        $stale[] = $path;
                    }
                } elseif ($state !== 'up to date') {
                    if (! is_dir(dirname($file))) {
                        @mkdir(dirname($file), 0775, true);
                    }

                    file_put_contents($file, $css);
                    $state = $current === null ? 'written' : 'updated';
                }

                $states[$state] = ($states[$state] ?? 0) + 1;
            }

            $themeFile = $files[$id.'.css'];
            $styleFiles = array_diff_key($files, [$id.'.css' => true]);
            $rows[] = [
                $id,
                count($files),
                number_format(strlen(implode('', $files)) / 1024, 1),
                number_format($gzip($themeFile) / 1024, 1),
                number_format(max([0, ...array_map($gzip, $styleFiles)]) / 1024, 1),
                implode(', ', array_map(fn ($state, $count) => $count.' '.$state, array_keys($states), $states)),
            ];
        }

        $this->table(['Theme', 'Files', 'KB', 'KB gzip theme file', 'KB gzip largest colour style', 'State'], $rows);

        if ($only === []) {
            $onDisk = array_filter(array_map(
                fn (string $file): string => str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($dir) + 1)),
                [...(glob($dir.DIRECTORY_SEPARATOR.'*.css') ?: []), ...(glob($dir.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*.css') ?: [])]
            ), fn (string $path): bool => ! str_starts_with($path, ThemeTokens::HERO_DECO_DIR.'/'));
            $orphans = array_diff($onDisk, $expected);

            if ($orphans !== []) {
                $this->warn('Not in the catalogue (delete them or add the themes / colour styles): '.implode(', ', $orphans));
            }
        }

        if ($check && $stale !== []) {
            $this->error('Missing or out of date: '.implode(', ', $stale).'. Run php artisan avd:themes:build, then npm run build.');

            return self::FAILURE;
        }

        $this->info($check ? 'Theme token files are up to date.' : 'Theme token files written to '.$dir.'. Run npm run build to ship them.');

        return self::SUCCESS;
    }
}
