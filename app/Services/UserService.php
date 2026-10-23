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

        $user = User::create($data);

        AvdbCreatorAvatarFactory::ensureForUser($user);

        $this->createDefaultPlaylists($user);

        event(new Registered($user));

        if ($login) {
            Auth::login($user);
        }

        return $user;
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
