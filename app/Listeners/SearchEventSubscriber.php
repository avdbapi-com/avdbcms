<?php

namespace App\Listeners;

use App\Events\SearchPerformed;
use App\Models\Search;
use App\Services\SearchHistoryRetentionService;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Auth;

class SearchEventSubscriber
{
    public function __construct(private readonly SearchHistoryRetentionService $searchHistoryRetention)
    {
    }

    /**
     * Handle user have new activity event.
     */
    public function saveSearch(SearchPerformed $event): void {
        if (!Auth::user()?->is_admin) {
            Search::query()->create([
                'user_id' => Auth::user()?->id,
                'query' => $event->query,
                'ip' => request()->ip(),
                'lang' => request()->getPreferredLanguage(),
                'results' => $event->results
            ]);

            $this->searchHistoryRetention->maybeCleanup();
        }
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            SearchPerformed::class => 'saveSearch'
        ];
    }
}
