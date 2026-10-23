<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Stripe\Subscription as StripeSubscription;

/**
 * @mixin IdeHelperSubscription
 */
class Subscription extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $table = 'premiums';

    protected $casts = [
        'next_payment' => 'datetime',
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'card_expired_at' => 'date',
    ];

    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan() : BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn () => in_array($this->stripe_status, [
                StripeSubscription::STATUS_ACTIVE,
                StripeSubscription::STATUS_TRIALING,
            ], true) && (is_null($this->ends_at) || $this->ends_at->isFuture())
        );
    }

    protected function onTrial(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->trial_ends_at?->isFuture() === true
        );
    }

    protected function isTrialCanceled(): Attribute
    {
        return Attribute::make(
            get: fn () => !is_null($this->ends_at)
        );
    }

    protected function isCanceled(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->stripe_status === StripeSubscription::STATUS_CANCELED
        );
    }

    protected function isUnpaid(): Attribute
    {
        return Attribute::make(
            get: fn () => in_array($this->stripe_status, [
                StripeSubscription::STATUS_UNPAID,
                'past_due',
                'paused',
            ], true)
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => match($this->stripe_status) {
                StripeSubscription::STATUS_ACTIVE => __('Active'),
                StripeSubscription::STATUS_TRIALING => __('In trial period'),
                StripeSubscription::STATUS_CANCELED => __('Canceled'),
                StripeSubscription::STATUS_UNPAID => __('Unpaid'),
                'past_due' => __('Past due'),
                'incomplete' => __('Incomplete'),
                'incomplete_expired' => __('Incomplete expired'),
                'paused' => __('Paused'),
                default => __(Str::headline($this->stripe_status ?: 'unknown')),
            }
        );
    }

    /**
     * -------------------- SCOPES --------------------
     */

    /**
     * Scope a query to only include active subscriptions.
     *
     * @param  Builder $query
     * @return void
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('stripe_status', [
            StripeSubscription::STATUS_ACTIVE,
            StripeSubscription::STATUS_TRIALING,
        ])->where(function (Builder $query) {
            $query->whereNull('ends_at')
                ->orWhere('ends_at', '>', now());
        });
    }
}
