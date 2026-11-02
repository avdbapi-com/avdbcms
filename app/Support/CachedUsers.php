<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use SplObjectStorage;
use Throwable;

/**
 * What a cache entry may keep of a users row. The default cache store is a file on the server, and lists of models
 * cached whole kept every users row inside them: the home hero's and the search results' video creators, the creator
 * spotlight and the side menu's lists held password hashes, remember tokens, e-mail addresses, phone numbers and their
 * codes, sign-in addresses and social ids. A BelongsToMany list also keeps, on each item's pivot, the row it was read
 * through: the side menu's lists of followed channels and saved playlists held the signed-in member's own row there.
 *
 * - publicOnly(): for every list shown to visitors or kept for one member (the home hero, the shorts shelf, the creator
 *   spotlight, the side menu, search results): only the public columns below, the counts the query added and the
 *   extra names the caller asks for; pivots lose the row they were read through.
 * - forAdminCache() / restoreAdminRows(): for the admin's own cached lists (the old admin's member list and dashboard
 *   show e-mail addresses to administrators). The cache keeps a copy whose users rows hold no column outside the public
 *   ones (the counts and other values the query computed stay); when the list is read, the rest of each row comes back
 *   from the database in one query (never the password hash, remember token or phone code). So no cache file holds an
 *   e-mail address, phone number, sign-in address, social or payment id (round 1 review RV-FC-06: the admin's lists
 *   kept all of them, only the secrets were taken out).
 */
final class CachedUsers
{
    /**
     * The users columns a cached public list may hold: what the public pages print about a channel and what printing
     * it reads without another query (its address, picture, verified tick, the "show subscribers" choice, the channel's
     * look, whether the account is active).
     */
    public const PUBLIC_COLUMNS = [
        'id', 'username', 'slug', 'profile_uid', 'avatar', 'banner', 'description', 'country', 'website',
        'show_subscribers', 'channel_customization', 'is_admin', 'email_verified_at', 'phone_verified_at', 'banned_at',
        'pinned_video_id', 'avdb_creator_key', 'avdb_creator_source', 'avdb_creator_name', 'created_at', 'updated_at',
        'deleted_at',
    ];

    /** Secrets: no cache entry holds them, and restoreAdminRows() does not read them back either (no page prints them). */
    public const SECRET_COLUMNS = ['password', 'remember_token', 'phone_confirmation_code'];

    /**
     * Account data no cache entry holds, also where the users table's columns cannot be read (forAdminCache() leaves out
     * every users column outside PUBLIC_COLUMNS; this list is its floor): contact details, sign-in data, social and
     * payment ids, two-step sign-in data and the member's own settings.
     */
    public const PRIVATE_COLUMNS = [
        'password', 'remember_token', 'phone_confirmation_code', 'email', 'temporary_email', 'phone', 'last_login_ip',
        'last_login_at', 'google_id', 'facebook_id', 'stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'language', 'admin_preferences',
        'is_view_only', 'history_paused_at',
    ];

    /**
     * $value (a model, a collection, a paginator or an array of them, at any depth: loaded relations included) with
     * every users row reduced to PUBLIC_COLUMNS, the counts the query added (*_count) and $extra, and every pivot
     * without the row it was read through. The same objects are changed and returned.
     *
     * @param  list<string>  $extra  other names the caller's view reads (e.g. has_new_video)
     */
    public static function publicOnly(mixed $value, array $extra = []): mixed
    {
        $keep = array_flip(array_merge(self::PUBLIC_COLUMNS, $extra));

        self::walk($value, static function (User $user) use ($keep): void {
            $attributes = [];

            foreach ($user->getAttributes() as $name => $attribute) {
                if (isset($keep[$name]) || str_ends_with((string) $name, '_count')) {
                    $attributes[$name] = $attribute;
                }
            }

            $user->setRawAttributes($attributes, true);
        }, new SplObjectStorage());

        return $value;
    }

    /**
     * The copy of an admin list the cache keeps: every users row inside it (at any depth) without the users table's
     * columns outside PUBLIC_COLUMNS (and never any of PRIVATE_COLUMNS); the counts and other values the query computed
     * stay, pivots lose the row they were read through. The caller's own objects are not changed (the page that computed
     * the list prints it whole). A value with no users row inside is returned as it is.
     */
    public static function forAdminCache(mixed $value): mixed
    {
        $found = false;
        self::walk($value, static function () use (&$found): void {
            $found = true;
        }, new SplObjectStorage(), 0, false);

        if (! $found) {
            return $value;
        }

        try {
            $copy = unserialize(serialize($value));
        } catch (Throwable) {
            // a value PHP cannot serialize: no cache store can write it either (SafeCache then serves it uncached)
            return $value;
        }

        $private = array_flip(self::privateColumns());

        self::walk($copy, static function (User $user) use ($private): void {
            $user->setRawAttributes(array_diff_key($user->getAttributes(), $private), true);
        }, new SplObjectStorage());

        return $copy;
    }

    /**
     * An admin list read from the cache, as the admin's pages print it: every users row inside it gets back the columns
     * forAdminCache() left out (never SECRET_COLUMNS), read in one query by primary key, deleted members included; a row
     * no longer in the table keeps what the cache had. The same objects are changed and returned.
     */
    public static function restoreAdminRows(mixed $value): mixed
    {
        $users = [];

        self::walk($value, static function (User $user) use (&$users): void {
            if ($user->getKey() !== null) {
                $users[] = $user;
            }
        }, new SplObjectStorage());

        if ($users === []) {
            return $value;
        }

        try {
            $ids = array_values(array_unique(array_map(static fn (User $user): mixed => $user->getKey(), $users)));
            $rows = User::query()->withoutGlobalScopes()->whereKey($ids)->toBase()->get()->keyBy('id');
        } catch (Throwable) {
            return $value;
        }

        $restore = array_flip(array_diff(self::privateColumns(), self::SECRET_COLUMNS));

        foreach ($users as $user) {
            $row = $rows->get($user->getKey());

            if ($row !== null) {
                $user->setRawAttributes(array_merge($user->getAttributes(), array_intersect_key((array) $row, $restore)), true);
            }
        }

        return $value;
    }

    /**
     * The users columns an admin list's cache entry leaves out: every column of the users table outside PUBLIC_COLUMNS,
     * and PRIVATE_COLUMNS whatever the table says (read once per process; not kept while the table cannot be read).
     *
     * @return list<string>
     */
    private static function privateColumns(): array
    {
        static $columns = null;

        if ($columns !== null) {
            return $columns;
        }

        try {
            $table = Schema::getColumnListing((new User())->getTable());
        } catch (Throwable) {
            $table = [];
        }

        $private = array_values(array_diff(array_unique(array_merge($table, self::PRIVATE_COLUMNS)), self::PUBLIC_COLUMNS));

        if ($table !== []) {
            $columns = $private;
        }

        return $private;
    }

    /**
     * Calls $onUser for every users row found in $value and drops every pivot's parent row ($dropPivotParents false:
     * only looks).
     *
     * @param  \Closure(User): void  $onUser
     */
    private static function walk(mixed $value, \Closure $onUser, SplObjectStorage $seen, int $depth = 0, bool $dropPivotParents = true): void
    {
        if ($depth > 12) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::walk($item, $onUser, $seen, $depth + 1, $dropPivotParents);
            }

            return;
        }

        if (! is_object($value) || $seen->contains($value)) {
            return;
        }

        $seen->attach($value);

        if ($value instanceof Model) {
            if ($value instanceof User) {
                $onUser($value);
            }

            // A pivot of a BelongsToMany list (Pivot, MorphPivot, a custom ->using() class) keeps the parent row it was
            // read through and the related model; neither is needed to read the pivot's own columns.
            if ($dropPivotParents && property_exists($value, 'pivotParent')) {
                $value->pivotParent = null;
            }

            if ($dropPivotParents && property_exists($value, 'pivotRelated')) {
                $value->pivotRelated = null;
            }

            foreach ($value->getRelations() as $relation) {
                self::walk($relation, $onUser, $seen, $depth + 1, $dropPivotParents);
            }

            return;
        }

        if ($value instanceof Collection) {
            foreach ($value->all() as $item) {
                self::walk($item, $onUser, $seen, $depth + 1, $dropPivotParents);
            }

            return;
        }

        if ($value instanceof Paginator || $value instanceof CursorPaginator) {
            if (method_exists($value, 'getCollection')) {
                self::walk($value->getCollection(), $onUser, $seen, $depth + 1, $dropPivotParents);
            } else {
                self::walk($value->items(), $onUser, $seen, $depth + 1, $dropPivotParents);
            }
        }
    }
}
