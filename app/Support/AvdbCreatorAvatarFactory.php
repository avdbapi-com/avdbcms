<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AvdbCreatorAvatarFactory
{
    private const STYLE_VERSION = 'adult-brand-badge-v2';
    private const PACK_FOLDER = 'avdb-avatar-pack';

    public static function ensure(array $candidate): ?string
    {
        try {
            $key = trim((string) ($candidate['key'] ?? ''));
            if ($key === '') {
                return null;
            }

            $source = trim((string) ($candidate['source'] ?? 'creator')) ?: 'creator';
            $name = trim((string) ($candidate['name'] ?? 'AVDB Creator')) ?: 'AVDB Creator';

            if ($packAvatar = self::avatarFromPack($key, $source)) {
                return $packAvatar;
            }

            $filename = 'avdb-creator-'.substr(hash('sha256', self::STYLE_VERSION.'|'.$source.'|'.$key), 0, 24).'.svg';
            $path = User::AVATAR_FOLDER.'/'.$filename;

            if (! Storage::exists($path)) {
                Storage::makeDirectory(User::AVATAR_FOLDER);
                Storage::put($path, self::svg($key, $source, $name));
            }

            return $filename;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function isGenerated(?string $filename): bool
    {
        return is_string($filename)
            && (
                (str_starts_with($filename, 'avdb-creator-') && str_ends_with($filename, '.svg'))
                || str_starts_with($filename, 'avdb-pack-')
            );
    }

    /**
     * Avatars generated before 2026-10 carry endless SMIL animations (a shine pulse and a slowly
     * turning dashed ring). An animated SVG <img> is re-rasterized on every frame wherever it is
     * visible, which kept pages from ever going idle, so stored files are served static.
     */
    public static function withoutSmil(string $svg): string
    {
        if (! str_contains($svg, '<animate')) {
            return $svg;
        }

        // the shine circle keeps the middle of its old 0.58-0.9 pulse
        $svg = (string) preg_replace('#(<circle\b[^>]*fill="url\(\#shine\)")\s*>\s*<animate\b[^>]*/>\s*</circle>#', '$1 opacity="0.74"/>', $svg);

        return (string) preg_replace('#<animate(?:Transform|Motion)?\b[^>]*/>#', '', $svg);
    }

    public static function needsRepair(User $user): bool
    {
        $candidate = self::candidateFromUser($user);
        if ($candidate === null) {
            return false;
        }

        $avatar = trim((string) ($user->avatar ?? ''));
        if ($avatar === '' || $avatar === User::DEFAULT_AVATAR || str_contains($avatar, 'default_men')) {
            return true;
        }

        $path = User::AVATAR_FOLDER.'/'.$avatar;
        if (! Storage::exists($path)) {
            return true;
        }

        return self::isGenerated($avatar) && ! Storage::exists($path);
    }

    public static function ensureForUser(User $user): ?string
    {
        $candidate = self::candidateFromUser($user);
        if ($candidate === null) {
            return $user->avatar;
        }

        $avatar = trim((string) ($user->avatar ?? ''));
        if ($avatar !== '' && ! self::needsRepair($user)) {
            return $avatar;
        }

        $filename = self::ensure($candidate);
        if (! $filename) {
            return $avatar !== '' ? $avatar : null;
        }

        self::persistAvatar($user, $filename);

        return $filename;
    }

    public static function svgForUser(User $user): ?string
    {
        $candidate = self::candidateFromUser($user);
        if ($candidate === null) {
            return null;
        }

        $key = trim((string) ($candidate['key'] ?? ''));
        if ($key === '') {
            return null;
        }

        $source = trim((string) ($candidate['source'] ?? 'creator')) ?: 'creator';
        $name = trim((string) ($candidate['name'] ?? 'AVDB Creator')) ?: 'AVDB Creator';

        return self::svg($key, $source, $name);
    }

    private static function persistAvatar(User $user, string $filename): void
    {
        if (! $user->exists) {
            $user->forceFill(['avatar' => $filename]);

            return;
        }

        $updates = ['avatar' => $filename];
        $updatedAtColumn = null;
        $updatedAtValue = null;

        if ($user->usesTimestamps()) {
            $updatedAtColumn = $user->getUpdatedAtColumn();
            $updatedAtValue = now();
            $updates[$updatedAtColumn] = $updatedAtValue;
        }

        DB::table($user->getTable())
            ->where($user->getKeyName(), $user->getKey())
            ->update($updates);

        $user->setAttribute('avatar', $filename);
        $user->syncOriginalAttribute('avatar');

        if ($updatedAtColumn !== null) {
            $user->setAttribute($updatedAtColumn, $updatedAtValue);
            $user->syncOriginalAttribute($updatedAtColumn);
        }
    }

    private static function candidateFromUser(User $user): ?array
    {
        $key = trim((string) ($user->getAttribute('avdb_creator_key') ?? ''));
        $source = trim((string) ($user->getAttribute('avdb_creator_source') ?? ''));
        $name = trim((string) ($user->getAttribute('avdb_creator_name') ?? ''));

        if ($key === '' && $user->exists) {
            $fresh = User::query()
                ->withoutGlobalScopes()
                ->select(['id', 'username', 'avdb_creator_key', 'avdb_creator_source', 'avdb_creator_name', 'avatar', 'is_admin'])
                ->find($user->getKey());

            if ($fresh) {
                $key = trim((string) ($fresh->avdb_creator_key ?? ''));
                $source = trim((string) ($fresh->avdb_creator_source ?? ''));
                $name = trim((string) ($fresh->avdb_creator_name ?? ''));

                if (trim((string) ($user->avatar ?? '')) === '' && trim((string) ($fresh->avatar ?? '')) !== '') {
                    $user->forceFill(['avatar' => $fresh->avatar]);
                }
            }
        }

        if ($key === '') {
            $username = trim((string) ($user->username ?? ''));
            if ($username === '' && $user->exists) {
                $username = 'user-'.$user->getKey();
            }
            if ($username === '') {
                return null;
            }

            $isAdmin = (bool) ($user->is_admin ?? false);
            $key = 'user:' . strtolower($username);
            $source = $isAdmin ? 'admin' : 'user';
            $name = $username;
        }

        // Keep the resolved candidate on the hydrated model. Avatar URLs can
        // be rendered several times in one response; retaining this value
        // prevents a fresh user lookup for each render.
        $resolvedAttributes = [
            'avdb_creator_key' => $key,
            'avdb_creator_source' => $source !== '' ? $source : 'creator',
            'avdb_creator_name' => $name !== '' ? $name : (trim((string) $user->username) ?: 'AVDB Creator'),
        ];
        foreach ($resolvedAttributes as $attribute => $value) {
            $user->setAttribute($attribute, $value);
            // This is a request-local lookup cache, not a pending profile edit.
            // Mark it as hydrated so a later unrelated save cannot persist it.
            $user->syncOriginalAttribute($attribute);
        }

        return [
            'key' => $resolvedAttributes['avdb_creator_key'],
            'source' => $resolvedAttributes['avdb_creator_source'],
            'name' => $resolvedAttributes['avdb_creator_name'],
        ];
    }

    private static function avatarFromPack(string $key, string $source): ?string
    {
        try {
            $files = self::packFiles($source);
            if ($files === []) {
                return null;
            }

            sort($files, SORT_NATURAL | SORT_FLAG_CASE);

            $sourceFile = $files[self::number(self::STYLE_VERSION.'|pack|'.$source.'|'.$key, 0, count($files) - 1)];
            $extension = strtolower((string) pathinfo($sourceFile, PATHINFO_EXTENSION));
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true)) {
                return null;
            }

            $modified = Storage::lastModified($sourceFile);
            $filename = 'avdb-pack-'.substr(hash('sha256', self::STYLE_VERSION.'|'.$source.'|'.$key.'|'.$sourceFile.'|'.$modified), 0, 24).'.'.$extension;
            $target = User::AVATAR_FOLDER.'/'.$filename;

            if (! Storage::exists($target)) {
                Storage::makeDirectory(User::AVATAR_FOLDER);
                Storage::put($target, Storage::get($sourceFile));
            }

            return $filename;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function packFiles(string $source): array
    {
        $folders = match ($source) {
            'actor' => ['female', 'actor', 'person', 'all'],
            'director' => ['director', 'person', 'all'],
            'tag' => ['tag', 'female', 'all'],
            default => [$source, 'person', 'all'],
        };

        $files = [];
        foreach ($folders as $folder) {
            $path = self::PACK_FOLDER.'/'.$folder;
            if (! Storage::exists($path)) {
                continue;
            }

            foreach (Storage::allFiles($path) as $file) {
                if (preg_match('/\.(jpe?g|png|webp|svg)$/i', $file)) {
                    $files[] = $file;
                }
            }
        }

        return array_values(array_unique($files));
    }

    private static function svg(string $key, string $source, string $name): string
    {
        $seed = self::STYLE_VERSION.'|'.$source.'|'.$key.'|'.$name;
        $palette = self::palette($seed);
        $initials = htmlspecialchars(self::initials($name, $source), ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars(self::label($source), ENT_QUOTES, 'UTF-8');
        $mark = self::mark($source, $name);
        $title = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars($source, ENT_QUOTES, 'UTF-8');
        $ringDash = self::number($seed.'|dash', 24, 38);
        $rotation = self::number($seed.'|rotation', -14, 14);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" role="img" aria-label="{$title} avatar" data-avdb-avatar="{$type}" data-avdb-avatar-style="adult-brand-badge">
  <title>{$title}</title>
  <defs>
    <linearGradient id="bg" x1="10%" y1="6%" x2="92%" y2="94%">
      <stop offset="0%" stop-color="{$palette[0]}"/>
      <stop offset="48%" stop-color="{$palette[1]}"/>
      <stop offset="100%" stop-color="{$palette[2]}"/>
    </linearGradient>
    <linearGradient id="metal" x1="18%" y1="0%" x2="84%" y2="100%">
      <stop offset="0%" stop-color="#ffffff" stop-opacity="0.95"/>
      <stop offset="48%" stop-color="{$palette[3]}" stop-opacity="0.94"/>
      <stop offset="100%" stop-color="#1e1b4b" stop-opacity="0.45"/>
    </linearGradient>
    <radialGradient id="shine" cx="30%" cy="18%" r="74%">
      <stop offset="0%" stop-color="#ffffff" stop-opacity="0.55"/>
      <stop offset="46%" stop-color="#ffffff" stop-opacity="0.18"/>
      <stop offset="100%" stop-color="#ffffff" stop-opacity="0"/>
    </radialGradient>
    <filter id="shadow" x="-24%" y="-20%" width="148%" height="148%">
      <feDropShadow dx="0" dy="12" stdDeviation="12" flood-color="#030712" flood-opacity="0.30"/>
    </filter>
  </defs>
  <rect width="256" height="256" rx="128" fill="url(#bg)"/>
  <circle cx="128" cy="128" r="107" fill="url(#shine)" opacity="0.74"/>
  <circle cx="128" cy="128" r="126" fill="none" stroke="#ffffff" stroke-width="3" stroke-opacity="0.35"/>
  <g filter="url(#shadow)" transform="rotate({$rotation} 128 128)">
    <circle cx="128" cy="128" r="90" fill="#ffffff" opacity="0.15"/>
    <circle cx="128" cy="128" r="82" fill="#ffffff" opacity="0.08"/>
    <circle cx="128" cy="128" r="76" fill="none" stroke="url(#metal)" stroke-width="10"/>
    <circle cx="128" cy="128" r="62" fill="#000000" opacity="0.16"/>
    <circle cx="128" cy="128" r="92" fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round" stroke-dasharray="{$ringDash} 18" opacity="0.45"/>
  </g>
  <path d="M55 179c36 19 91 20 147-3" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" opacity="0.22"/>
  <text x="128" y="137" text-anchor="middle" dominant-baseline="middle" font-family="Inter, -apple-system, BlinkMacSystemFont, Arial, sans-serif" font-size="64" font-weight="900" letter-spacing="1" fill="#fff" style="filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5));">{$initials}</text>
  <text x="128" y="183" text-anchor="middle" font-family="Inter, -apple-system, BlinkMacSystemFont, Arial, sans-serif" font-size="18" font-weight="800" letter-spacing="2.2" fill="#fff" opacity="0.9" style="filter: drop-shadow(0 1px 3px rgba(0,0,0,0.5));">{$label}</text>
  <g transform="translate(185 50)">
    <circle cx="0" cy="0" r="24" fill="#fff" opacity="0.96"/>
    <circle cx="0" cy="0" r="18" fill="{$palette[4]}" opacity="0.95"/>
    {$mark}
  </g>
</svg>
SVG;
    }

    private static function initials(string $name, string $source): string
    {
        $ascii = Str::ascii($name);
        $words = preg_split('/[^A-Za-z0-9]+/', strtoupper($ascii), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter(
            $words,
            fn (string $word): bool => preg_match('/[A-Z]/', $word) && ! in_array($word, ['THE', 'AV', 'JAV'], true)
        ));

        if (count($words) >= 2) {
            return substr($words[0], 0, 1).substr($words[1], 0, 1);
        }

        if (count($words) === 1) {
            return substr($words[0], 0, min(2, max(1, strlen($words[0]))));
        }

        return match ($source) {
            'tag' => 'TG',
            'actor' => 'AV',
            'admin' => 'AD',
            'user' => 'US',
            default => 'AV',
        };
    }

    private static function label(string $source): string
    {
        return match ($source) {
            'actor' => 'STAR',
            'director' => 'AVDB',
            'tag' => 'TAG',
            'admin' => 'ADMIN',
            'user' => 'CREATOR',
            default => 'AVDB',
        };
    }

    private static function mark(string $source, string $name): string
    {
        if ($source === 'director') {
            return '<path d="M-9-8H6v12H-9zM6-4l10-6V8L6 2z" fill="#fff"/>';
        }

        if ($source === 'tag') {
            $shape = self::tagShape($name);
            return '<path d="'.$shape.'" fill="#fff"/>';
        }

        if ($source === 'admin') {
            return '<path d="M-10-6l4 3 6-7 6 7 4-3v14h-20z" fill="#fff"/>';
        }

        return '<path d="M0-13l4 8 9 1.3-6.5 6.3 1.5 8.9L0 7.3l-8 4.2 1.5-8.9-6.5-6.3L-4-5z" fill="#fff"/>';
    }

    private static function tagShape(string $name): string
    {
        $name = strtolower($name);

        if (str_contains($name, 'school')) {
            return 'M-15-2 0-12 15-2 0 8zM-8 2v8h16V2L0 7z';
        }

        if (str_contains($name, 'subtitle')) {
            return 'M-15-10h30v20h-30zM-10-2h10v3h-10zM3-2h8v3H3zM-10 4h21v3h-21z';
        }

        if (str_contains($name, 'hentai')) {
            return 'M0-15c8 0 14 6 14 14S8 14 0 14-14 8-14 0-8-14-14-14zm-7 13 5-5 4 4 5-5 4 4-13 13z';
        }

        return 'M-12-12H3l10 10-18 18-10-10L3-12zM0-6a3 3 0 1 0 0 6 3 3 0 0 0 0-6z';
    }

    private static function palette(string $seed): array
    {
        $palettes = [
            ['#ff2d6f', '#7c3aed', '#4c1d95', '#fbcfe8', '#ec4899'],
            ['#06b6d4', '#2563eb', '#1e3a8a', '#bae6fd', '#38bdf8'],
            ['#f97316', '#db2777', '#831843', '#fed7aa', '#fb923c'],
            ['#10b981', '#0f766e', '#115e59', '#bbf7d0', '#34d399'],
            ['#f59e0b', '#f43f5e', '#881337', '#fde68a', '#facc15'],
            ['#a855f7', '#be185d', '#701a75', '#e9d5ff', '#c084fc'],
            ['#f43f5e', '#b91c1c', '#7f1d1d', '#fecaca', '#fb7185'],
            ['#14b8a6', '#4f46e5', '#312e81', '#ccfbf1', '#2dd4bf'],
        ];

        return self::option($palettes, $seed.'|palette');
    }

    private static function option(array $options, string $seed): mixed
    {
        if ($options === []) {
            return null;
        }

        return $options[self::number($seed, 0, count($options) - 1)];
    }

    private static function number(string $seed, int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        $value = hexdec(substr(hash('sha256', $seed), 0, 8));

        return $min + ($value % (($max - $min) + 1));
    }
}
