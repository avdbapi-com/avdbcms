<?php

namespace App\Filters;

use App\Filters\Drivers\MySQLFilter;
use App\Filters\Traits\DateFilter;
use Illuminate\Database\Eloquent\Builder;

class UserFilters extends MySQLFilter
{
    use DateFilter;

    public function search(string $search): Builder
    {
        $match = '%'.$search.'%';

        return $this->builder->where(fn($query) => $query
            ->where('username', 'LIKE', $match)
            ->orWhere('email', 'LIKE', $match)
            ->orWhere('slug', 'LIKE', $match)
            ->orWhere('description', 'LIKE', $match)
        );
    }

    public function status (string $value): Builder
    {
        return $this->builder
            ->when($value === 'banned', fn($query) => $query->whereNotNull('banned_at'))
            ->when($value === 'unverified', fn($query) => $query->whereNull('email_verified_at'))
            ->when($value === 'premium', fn($query) => $query->whereHas('premium_subscription', function (Builder $query) {
                $query->scopes('active');
            }));
    }

    /**
     * ?reason=blocked-words: the importer-made creator channels the refresh of 1.2.3 blocked because their names or
     * descriptions use words that are always blocked (App\Support\BlockedCreatorsPass; Home's "Needs you" links to
     * them). Any other value changes nothing.
     */
    public function reason(string $reason): Builder
    {
        return $reason === \App\Support\BlockedCreatorsPass::FILTER
            ? \App\Support\BlockedCreatorsPass::constrain($this->builder)
            : $this->builder;
    }
}
