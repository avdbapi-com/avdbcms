<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What a member wants under the bell (Account settings > Notifications, member design 6.14). One row per member who
 * changed a switch; without a row every switch is on (as before 1.2.3). Upload results (a video ready / failed /
 * suspended), invoices, exports and account e-mails are always on: they are not settings.
 *
 * The per-channel bell of a Subscribe button (subscriptions.notify: all / highlights / none) WINS over the global "New
 * videos from channels I follow": the switch is the default of the channels left on their default (NULL).
 *
 * Until "Finish update" has added the table, every switch counts as on and nothing is saved.
 *
 * @property int $user_id
 * @property bool $new_subscribers
 * @property bool $comments
 * @property bool $replies
 * @property bool $new_videos
 */
class NotificationSetting extends Model
{
    /** The switches a member can turn off, with their defaults. */
    public const SWITCHES = [
        'new_subscribers' => true,
        'comments' => true,
        'replies' => true,
        'new_videos' => true,
    ];

    /** The per-channel bell values (subscriptions.notify); NULL = the member's global switch. */
    public const BELL_ALL = 'all';

    public const BELL_HIGHLIGHTS = 'highlights';

    public const BELL_NONE = 'none';

    public const BELLS = [self::BELL_ALL, self::BELL_HIGHLIGHTS, self::BELL_NONE];

    private const CACHE_SECONDS = 3600;

    private const READY_KEY = 'notifications.settings.ready';

    private static ?bool $ready = null;

    private static ?bool $bellReady = null;

    protected $table = 'notification_settings';

    protected $fillable = ['user_id', 'new_subscribers', 'comments', 'replies', 'new_videos'];

    protected $casts = [
        'user_id' => 'integer',
        'new_subscribers' => 'boolean',
        'comments' => 'boolean',
        'replies' => 'boolean',
        'new_videos' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether the table exists ("Finish update" adds it). Remembered once seen. */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = (bool) Cache::get(self::READY_KEY, false) || Schema::hasTable('notification_settings');

                if (self::$ready) {
                    Cache::forever(self::READY_KEY, true);
                }
            } catch (Throwable) {
                return false;
            }
        }

        return self::$ready;
    }

    /** Whether subscriptions.notify exists (the per-channel bell). */
    public static function bellReady(): bool
    {
        if (self::$bellReady === null) {
            try {
                self::$bellReady = Schema::hasColumn('subscriptions', 'notify');
            } catch (Throwable) {
                return false;
            }
        }

        return self::$bellReady;
    }

    /** Forget what this process knows about the schema (tests that build the tables). */
    public static function flushSchemaState(): void
    {
        self::$ready = null;
        self::$bellReady = null;
        rescue(fn () => Cache::forget(self::READY_KEY), null, false);
    }

    /**
     * The member's switches (every switch on without a row).
     *
     * @return array{new_subscribers: bool, comments: bool, replies: bool, new_videos: bool}
     */
    public static function for(User|int $user): array
    {
        $id = (int) ($user instanceof User ? $user->getKey() : $user);

        if ($id <= 0 || ! self::ready()) {
            return self::SWITCHES;
        }

        try {
            return Cache::remember(self::cacheKey($id), self::CACHE_SECONDS, function () use ($id): array {
                $row = DB::table('notification_settings')->where('user_id', $id)->first();
                $settings = self::SWITCHES;

                if ($row !== null) {
                    foreach (array_keys(self::SWITCHES) as $switch) {
                        $settings[$switch] = (bool) ($row->{$switch} ?? true);
                    }
                }

                return $settings;
            });
        } catch (Throwable) {
            return self::SWITCHES;
        }
    }

    /** Whether the member wants this kind of notification ($switch: one of SWITCHES; anything else is always on). */
    public static function allows(User|int $user, string $switch): bool
    {
        if (! array_key_exists($switch, self::SWITCHES)) {
            return true;
        }

        return (bool) (self::for($user)[$switch] ?? true);
    }

    /**
     * Saves the member's switches (missing ones stay as they were).
     *
     * @param  array<string, mixed>  $values
     * @return array{new_subscribers: bool, comments: bool, replies: bool, new_videos: bool}
     */
    public static function store(User $user, array $values): array
    {
        $settings = self::for($user);

        foreach (array_keys(self::SWITCHES) as $switch) {
            if (array_key_exists($switch, $values)) {
                $settings[$switch] = filter_var($values[$switch], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (self::ready()) {
            self::query()->updateOrCreate(['user_id' => (int) $user->getKey()], $settings);
            Cache::forget(self::cacheKey((int) $user->getKey()));
        }

        return $settings;
    }

    /**
     * A deleted member's switches go with the account (NotificationTasks::forgetMember, called when an account is deleted
     * for good; the table's foreign key also takes the row when a user row is deleted without the model). The cached copy
     * goes too, so an id the database hands out again never inherits them.
     */
    public static function forget(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        rescue(fn () => Cache::forget(self::cacheKey($userId)), null, false);

        if (self::ready()) {
            rescue(fn () => DB::table('notification_settings')->where('user_id', $userId)->delete(), null, false);
        }
    }

    /** The bell of $member for $channel (all / highlights / none), or null when the member does not follow it. */
    public static function bellFor(User $member, User $channel): ?string
    {
        $query = DB::table('subscriptions')->where('subscriber_id', $member->getKey())->where('user_id', $channel->getKey());

        if (! $query->exists()) {
            return null;
        }

        if (! self::bellReady()) {
            return self::BELL_ALL;
        }

        $value = (clone $query)->value('notify');

        return in_array($value, self::BELLS, true) ? $value : self::BELL_ALL;
    }

    /**
     * Sets the bell of $member for $channel. False when the member does not follow the channel (nothing to set).
     */
    public static function setBell(User $member, User $channel, string $level): bool
    {
        if (! in_array($level, self::BELLS, true) || ! self::bellReady()) {
            return false;
        }

        return DB::table('subscriptions')
            ->where('subscriber_id', $member->getKey())
            ->where('user_id', $channel->getKey())
            ->update(['notify' => $level]) > 0
            || DB::table('subscriptions')->where('subscriber_id', $member->getKey())->where('user_id', $channel->getKey())->exists();
    }

    private static function cacheKey(int $id): string
    {
        return 'notifications.settings.'.$id;
    }
}
