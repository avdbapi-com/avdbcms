<?php

namespace App\Observers;

use App\Helpers\File;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use App\Support\AvdbCreatorAvatarFactory;

class UserObserver
{
    /**
     * @var array<string, bool>
     */
    private static array $cleanupColumns = [];

    /**
     * Handle the User "created" event.
     */
    public function created(User $user) : void
    {
        if (empty($user->avatar) || $user->avatar === User::DEFAULT_AVATAR || str_contains($user->avatar, 'default_men')) {
            AvdbCreatorAvatarFactory::ensureForUser($user);
        }
    }

    /**
     * Handle the User "updated" event.
     *
     * @param User $user
     * @return void
     */
    public function updated(User $user) : void
    {
        if ($user->hasStripeId()) {
            $user->syncStripeCustomerDetails();
        }
    }

    /**
     * Handle the User "deleting" event (runs on forceDelete).
     * Complete safety-net cascade — catches anything the controller/job missed.
     *
     * @param User $user
     * @return void
     */
    public function forceDeleting(User $user) : void
    {
        // Videos (with their own cascade via VideoObserver)
        $user->videos()->withTrashed()->each(fn($video) => $video->forceDelete());

        // Comments on other people's videos
        $user->comments()->withTrashed()->forceDelete();

        // Playlists
        $user->playlists()->withTrashed()->each(function ($playlist) {
            $playlist->videos()->detach();
            $playlist->forceDelete();
        });

        // Interactions, subscriptions, notifications, views, activity, reports
        $user->interactions()->delete();
        $user->subscriptions()->detach();
        $user->subscribers()->detach();
        $user->notifications()->delete();
        $user->views()->delete();
        $user->activity()->delete();
        $user->user_reports()->delete();
        $user->favorites_playlist()->detach();
        $user->tokens()->delete();

        // Premium subscription
        if ($user->premium_subscription) {
            $user->premium_subscription->delete();
        }

        // Direct DB cleanup for tables without model relations
        $this->deleteWhere('transactions', 'user_id', $user->id);
        $this->deleteWhere('searches', 'user_id', $user->id);
        $this->deleteWhere('sessions', 'user_id', $user->id);
        $this->deleteWhere('password_reset_tokens', 'email', $user->email);
        $this->deleteWhere('users_activity', 'user_id', $user->id);
        $this->deleteWhere('playback_progress_signals', 'user_id', $user->id);

        // File cleanup
        File::deleteIfExist($user->avatar, User::AVATAR_FOLDER);
        File::deleteIfExist($user->banner, User::BANNER_FOLDER);
    }

    private function deleteWhere(string $table, string $column, mixed $value): void
    {
        $key = $table.'.'.$column;
        self::$cleanupColumns[$key] ??= Schema::hasTable($table) && Schema::hasColumn($table, $column);

        if (self::$cleanupColumns[$key]) {
            DB::table($table)->where($column, $value)->delete();
        }
    }
}
