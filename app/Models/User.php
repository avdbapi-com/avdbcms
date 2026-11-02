<?php

namespace App\Models;

use App\Helpers\Number;
use App\Models\Interfaces\Likeable;
use App\Models\Interfaces\Reportable;
use App\Models\Pivots\FavoritePlaylist;
use App\Models\Pivots\Subscription;
use App\Models\Subscription as PremiumSubscription;
use App\Models\Traits\Filterable;
use App\Models\Traits\HasReport;
use App\Models\Traits\MustVerifyPhone;
use App\Models\Traits\MustVerifyUpdatedEmail;
use App\Support\AvdbCreatorAvatarFactory;
use App\Support\ChannelCustomizationSettings;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;
use App\Casts\StoredPhoneNumberCast;
use Staudenmeir\EloquentHasManyDeep\HasManyDeep;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;
use Symfony\Component\Intl\Countries;

/**
 * @mixin IdeHelperUser
 */

class User extends Authenticatable implements MustVerifyEmail, Reportable, HasLocalePreference
{
    use HasFactory, Notifiable, HasRelationships, HasReport, Impersonate, Billable, Filterable, HasApiTokens, MustVerifyUpdatedEmail, MustVerifyPhone, CanResetPassword, SoftDeletes;

    // is_view_only (the view-only demo account, App\Http\Middleware\ViewOnlyDemo) is set by an administrator only.
    protected $guarded = ['id', 'is_admin', 'is_view_only'];

    protected $hidden = [
        'password',
        'remember_token',
        // "Pause history" (users.history_paused_at): the member's own setting
        'history_paused_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'show_subscribers' => 'boolean',
        'banned_at' => 'datetime',
        'last_login_at' => 'datetime',
        // a stored number the phone library cannot read counts as no number (R1R-08: never a 500)
        'phone' => StoredPhoneNumberCast::class,
        'phone_verified_at' => 'datetime',
        'channel_customization' => 'array',
        'is_view_only' => 'boolean',
    ];

    public const string AVATAR_FOLDER = 'avatars';
    public const string DEFAULT_AVATAR = '/images/default_men.png';
    public const string BANNER_FOLDER = 'banners';
    public const string DEFAULT_BANNER = '/images/default_banner.jpg';

    /**
     * The characters of a username (member design 6.9, auth-23): letters of any language, numbers, dots, dashes and
     * underscores, the same rule on sign-up and in Account settings. A member who has an older name with other
     * characters keeps it until they change it.
     */
    public const string USERNAME_PATTERN = '/^[\pL\pM\pN._-]+$/u';

    /** The characters of a channel address (@handle): Latin letters, numbers, dots, dashes and underscores. */
    public const string SLUG_PATTERN = '/^[a-zA-Z0-9._-]+$/';

    public function getRouteKey(): mixed
    {
        return $this->profileRouteKeyValue();
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $value = (string) $value;

        if (ctype_digit($value)) {
            return $this->whereKey($value)->first();
        }

        if (!config('site.profile_uid_urls_enabled', true)) {
            $slugMatch = $this->where('slug', $value)->first();

            if ($slugMatch) {
                return $slugMatch;
            }
        }

        if (preg_match('/^(.+)-([a-z0-9]{8})$/i', $value, $matches)) {
            $uidMatch = $this->where('profile_uid', strtolower($matches[2]))->first();

            if ($uidMatch) {
                return $uidMatch;
            }
        }

        // QA watch-23: the handle members see and share (/@lofi_room, the username) opens the channel too, and so does
        // a slug that only looks like "name-uid"; UserController@show then sends it to the canonical address (301).
        return $this->where('slug', $value)->first() ?? $this->where('username', $value)->first();
    }

    public function hasVerifiedEmail()
    {
        if (!config('site.email_verification_required', false)) {
            return true;
        }

        return parent::hasVerifiedEmail();
    }

    public function sendEmailVerificationNotification()
    {
        if (!config('site.email_verification_required', false)) {
            return;
        }

        parent::sendEmailVerificationNotification();
    }

    /**
     * -------------------- RELATIONS --------------------
     */

    public function videos() : HasMany {
        return $this->hasMany(Video::class);
    }

    public function views() : HasMany {
        return $this->hasMany(View::class);
    }

    public function comments () : HasMany {
        return $this->hasMany(Comment::class);
    }

    public function subscriptions() : BelongsToMany {
        return $this->belongsToMany(User::class, 'subscriptions', 'subscriber_id', 'user_id')
            ->using(Subscription::class)
            ->withPivot(['subscribe_at', 'read_at']);
    }

    public function subscribers() : BelongsToMany {
        return $this->belongsToMany(User::class, 'subscriptions', 'user_id', 'subscriber_id')
            ->using(Subscription::class)
            ->withPivot(['subscribe_at', 'read_at']);
    }

    public function subscriptions_videos () : HasManyThrough {
        return $this->hasManyThrough(Video::class, Subscription::class, 'subscriber_id', 'user_id', 'id', 'user_id');
    }

    public function videos_comments () : HasManyThrough {
        return $this->hasManyThrough(Comment::class, Video::class);
    }

    public function videos_views () : HasManyThrough {
        return $this->hasManyThrough(View::class, Video::class);
    }

    public function videos_interactions (): HasManyThrough {
        return $this->hasManyThrough(Interaction::class, Video::class, 'user_id', 'likeable_id')
            ->where('likeable_type', Video::class);
    }

    public function videos_likes (): HasManyThrough {
        return $this->hasManyThrough(Interaction::class, Video::class, 'user_id', 'likeable_id')
            ->where('interactions.status', true)
            ->where('likeable_type', Video::class);
    }

    public function videos_dislikes (): HasManyThrough {
        return $this->hasManyThrough(Interaction::class, Video::class, 'user_id', 'likeable_id')
            ->where('interactions.status', false)
            ->where('likeable_type', Video::class);
    }

    public function videos_comments_interactions () : HasManyDeep {

        return $this->hasManyDeep(Interaction::class,
            [Video::class, Comment::class],
            ['user_id', 'video_id', 'likeable_id']
        );
    }

    public function interactions () : HasMany {
        return $this->hasMany(Interaction::class);
    }

    public function likes () : HasMany {
        return $this->hasMany(Interaction::class)->where('status', true);
    }

    public function dislikes () : HasMany {
        return $this->hasMany(Interaction::class)->where('status', false);
    }

    public function pinned_video () : HasOne {
        return $this->hasOne(Video::class, 'id', 'pinned_video_id')
            ->active();
    }

    public function activity () : HasMany {
        return $this->hasMany(Activity::class);
    }

    public function user_reports () : HasMany {
        return $this->hasMany(Report::class, 'user_id');
    }

    public function video_reports () : HasManyThrough {
        return $this->hasManyThrough(Report::class, Video::class, 'user_id', 'reportable_id')
            ->where('reportable_type', Video::class);
    }

    public function playlists () : HasMany {
        return $this->hasMany(Playlist::class);
    }

    public function favorites_playlist () : BelongsToMany {
        return $this->belongsToMany(Playlist::class, 'favorites_playlist', 'user_id', 'playlist_id')
            ->using(FavoritePlaylist::class)
            ->withPivot(['added_at']);
    }

    public function premium_subscription () : HasOne {
        return $this->hasOne(PremiumSubscription::class);
    }

    public function billingManagementUrl(?string $returnUrl = null): string
    {
        $returnUrl ??= route('user.edit');
        $subscription = $this->relationLoaded('premium_subscription')
            ? $this->premium_subscription
            : $this->premium_subscription()->first();

        if (
            !$this->stripe_id
            || $subscription?->payment_provider === 'paypal'
            || !filled(config('cashier.key'))
            || !filled(config('cashier.secret'))
        ) {
            return $returnUrl;
        }

        try {
            return $this->billingPortalUrl($returnUrl);
        } catch (\Throwable) {
            return $returnUrl;
        }
    }

    public function channelCustomization(): array
    {
        if (! config('site.channel_customization_enabled', true)) {
            return ChannelCustomizationSettings::normalizeForStorage([]);
        }

        return ChannelCustomizationSettings::normalizeForStorage($this->channel_customization ?? []);
    }

    public function devices () : HasMany {
        return $this->hasMany(Device::class, 'tokenable_id');
    }

    /**
     * -------------------- ATTRIBUTES --------------------
     */

    public function avatarUrl() : Attribute {
        return Attribute::make(
            get: function () {
                $avatar = AvdbCreatorAvatarFactory::ensureForUser($this);
                // generated SVG avatars are now served without their endless SMIL loops: a new
                // version makes browsers drop the animated copy they cached (immutable, 7 days)
                $variant = AvdbCreatorAvatarFactory::isGenerated($avatar) && str_ends_with((string) $avatar, '.svg') ? 'static' : '';

                return route('user.avatar', [
                    'user' => $this,
                    'v' => $this->profileImageVersion($avatar, $variant),
                ]);
            }
        );
    }

    public function bannerUrl() : Attribute {
        return Attribute::make(
            get: fn () => route('user.banner', [
                'user' => $this,
                'v' => $this->profileImageVersion($this->banner),
            ])
        );
    }

    private function profileImageVersion(?string $filename, string $variant = ''): string
    {
        return $filename ? substr(sha1($filename.($variant !== '' ? '|'.$variant : '')), 0, 16) : 'default';
    }

    public function firstActiveVideo (): Attribute
    {
        return Attribute::make(
            get: fn () => $this->videos()->active()->latest('published_at')->first()
        );
    }

    public function othersActiveVideos (): Attribute
    {
        return Attribute::make(
            get: fn () => $this->videos()->whereNot('id', $this->first_active_video->id)->active()->latest('published_at')
        );
    }

    /**
     * The password is stored hashed: every value given to `password` is hashed, also one that looks like a hash (a
     * shipped 1.2.1 seeder passed Hash::make('password123'), and that account must stay without a usable password).
     *
     * Laravel's own rehash writes a FRESH HASH instead (EloquentUserProvider::rehashPasswordIfRequired(): the rehash
     * on sign-in after the hashing cost or driver changed, with hashing.rehash_on_login on by default, and
     * Auth::logoutOtherDevices()). It writes under getAuthPasswordName(), so that name is the separate
     * `rehashed_password`, whose mutator stores the hash as it is: hashed a second time, the member could no longer
     * sign in. Every read goes through getAuthPassword(), which returns the stored `password` column.
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => Hash::make($value),
        );
    }

    /** The hash Laravel's rehash hands over (see password()), stored as it is in the `password` column. */
    protected function rehashedPassword(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => ['password' => $value],
        );
    }

    /** The attribute Laravel's rehash writes a fresh hash to (see password()). */
    public function getAuthPasswordName(): string
    {
        return 'rehashed_password';
    }

    /** The stored password hash (read by sign-in, password checks and the session's password hash). */
    public function getAuthPassword(): ?string
    {
        $hash = $this->attributes['password'] ?? null;

        return is_string($hash) ? $hash : null;
    }

    protected function countryName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->country ? Countries::getName($this->country, app()->getLocale()) : null,
        );
    }

    protected function route(): Attribute
    {
        return Attribute::make(
            get: fn () => route('user.show', $this)
        );
    }

    protected function profileRouteKey(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->profileRouteKeyValue()
        );
    }

    public function profileRouteKeyValue(): string
    {
        $slug = (string) ($this->getAttribute('slug') ?: $this->getKey());

        return config('site.profile_uid_urls_enabled', true) && $this->profile_uid
            ? $slug . '-' . strtolower((string) $this->profile_uid)
            : $slug;
    }

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->hasVerifiedEmail() && !$this->is_banned
        );
    }

    protected function isBanned(): Attribute
    {
        return Attribute::make(
            get: fn () => !is_null($this->banned_at)
        );
    }

    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn () => 'user',
        );
    }

    protected function shortDescription(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::limit($this->description, '300', '...')
        );
    }

    protected function isAdmin(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => in_array($value, [true, 1, '1'], true)
        );
    }

    protected function isPremium(): Attribute
    {
        return Attribute::make(
            get: fn () => !is_null($this->premium_subscription) && $this->premium_subscription->is_active
        );
    }

    protected function hasCurrentSubscription(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->is_premium || ($this->premium_subscription && $this->premium_subscription->is_unpaid)
        );
    }

    /**
     * The upload limits that apply to this account. Administrators (the site's owners) upload with the premium
     * limits: the member plans are for members (1.2.2: the bundled free limits apply, 10 videos / 500 MB / 5 GB).
     */
    protected function plan(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->is_premium || $this->is_admin ? 'premium' : 'free'
        );
    }

    protected function uploadedVideos(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->validVideosForStorage()->count()
        );
    }

    protected function uploadedVideosSize(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->validVideosForStorage()->sum('size')
        );
    }

    private function validVideosForStorage(): Builder
    {
        $query = $this->videos()->getQuery()->valid();

        if ($this->getConnection()->getDriverName() === 'mysql') {
            $query->forceIndex('videos_user_deleted_status_size_idx');
        }

        return $query;
    }

    protected function json(): Attribute
    {
        return Attribute::make(
            get: fn () => collect([
                'id' => $this->id,
                'avatar' => $this->avatar_url,
                'is_premium' => $this->is_premium,
            ])->toJson()
        );
    }

    /**
     * -------------------- SCOPES --------------------
     */

    /**
     * Scope a query to only include active users.
     *
     * @param  Builder $query
     * @return void
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotNull(['email_verified_at'])->whereNull(['banned_at']);
    }

    /**
     * -------------------- METHODS --------------------
     */

    public function isSubscribeTo(User $user) : bool
    {
        return $this->subscriptions()->where('user_id', $user->id)->exists();
    }

    public function hasLiked(Likeable $likeable): bool
    {
        return $likeable->likes()
            ->whereRelation('user', 'id', $this->id)
            ->exists();
    }

    public function hasDisliked(Likeable $likeable): bool
    {
        return $likeable->dislikes()
            ->whereRelation('user', 'id', $this->id)
            ->exists();
    }

    public function report(Reportable $reportable): Report|null
    {
        return $reportable->reports()
            ->whereRelation('user', 'id', $this->id)
            ->first();
    }

    /**
     * @return bool
     */
    public function canImpersonate() : bool
    {
        return $this->is_admin;
    }

    /**
     * @return bool
     */
    public function canBeImpersonated(): bool
    {
        return !$this->is_admin;
    }

    /**
     * Get the customer name that should be synced to Stripe.
     */
    public function stripeName(): string
    {
        return $this->username;
    }

    /**
     * Get the customer name that should be synced to Stripe.
     */
    public function stripePhone(): ?string
    {
        return $this->hasVerifiedPhone() ? $this->phone : null;
    }

    /**
     * The language of the member's e-mails and notifications (member design 6.21): the
     * language they use on the site (users.language, set at sign-in and by the language switch) when the site offers
     * it, else the site's default language. Laravel sends every notification and mailable to this member in it.
     */
    public function preferredLocale(): string
    {
        $languages = (array) config('languages', []);
        $language = trim((string) ($this->getAttribute('language') ?? ''));

        if ($language !== '' && array_key_exists($language, $languages)) {
            return $language;
        }

        // the site's language: on the web the setting applyDbSettings() put in site.default_locale, in a console process
        // (the scheduler's and the queue's mails) the one site_default_locale() reads there, never the configured
        // app.locale (judge R1-051)
        $default = site_default_locale();

        return array_key_exists($default, $languages) || $languages === [] ? $default : (string) array_key_first($languages);
    }

    public static function generateSlug(string $username) : string {

        $slug = Str::ucfirst(Str::camel(Str::slug($username)));

        // Str::slug() generate empty string with non latin character
        if (empty($slug)) {
            return 'user-' .Number::unique();
        }

        if (User::query()->where('slug', $slug)->exists()){
            return $slug. '-' .Number::unique();
        }

        return $slug;
    }
}
