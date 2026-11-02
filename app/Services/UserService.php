<?php

namespace App\Services;

use App\Enums\PlaylistStatus;
use App\Models\User;
use App\Playlists\PlaylistManager;
use App\Support\AvdbCreatorAvatarFactory;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class UserService {

    public function register(array $data, bool $login = true): User
    {
        if (!config('site.email_verification_required', false)) {
            $data['email_verified_at'] = now();
        }

        // The member's language is the page's language at sign-up (web, API and social sign-up all come here), so the
        // first e-mail (the verification link) already goes out in it, not in the column's default 'en'
        // (member design 6.21). A language the site does not offer falls back to the site's default.
        if (empty($data['language'])) {
            $data['language'] = self::siteLanguage(app()->getLocale());
        }

        $user = User::create($data);

        AvdbCreatorAvatarFactory::ensureForUser($user);

        $this->createDefaultPlaylists($user);

        event(new Registered($user));

        if ($login) {
            Auth::login($user);
        }

        return $user;
    }

    /** $locale when the site offers it, else the site's default language (else English). */
    public static function siteLanguage(?string $locale): string
    {
        $languages = (array) config('languages', []);
        $locale = (string) $locale;

        if ($locale !== '' && isset($languages[$locale])) {
            return $locale;
        }

        $default = (string) config('site.default_locale', config('app.locale', 'en'));

        return isset($languages[$default]) || $languages === [] ? $default : 'en';
    }

    private function createDefaultPlaylists(User $user): void
    {
        foreach (PlaylistManager::$types as $playlist) {
            $user->playlists()->create([
                'uuid' => Str::uuid()->toString(),
                'title' => $playlist::getName(),
                'status' => PlaylistStatus::PRIVATE,
                'is_deletable' => false
            ]);
        }
    }
}
