{{-- History day groups (member design 6.11): the site's day ("Today", "Yesterday", "Thursday, Oct 22", made by
     App\Support\WatchHistory::dayLabel in the admin's date format), a quiet "Remove" that names the day (QA channel-12,
     channel-35), and the rows. The page prints the first page; "Show older" adds the next ones from
     /api/me/history?format=html (a day that goes on from the page before joins its group there). While a search is
     shown ($searching) a day lists only its matching rows, so the day's Remove (which takes the whole day) is not offered:
     each row keeps its own Remove. The page passes 'eagerRows' (its first rows load their pictures at once, R1-067;
     from 576px the 2nd and later of them with a high priority too, a preload: the pictures are one size, so the
     browser's largest picture can be any of them); the API's next pages do not.
     Round 3 (R1-069, the content budget with one video a day: each day group cost about 450 bytes): the day's Remove
     takes its date from its group (data-history-day) and its question from the dialog's template with the day's name
     ("Remove the history of Tuesday, Oct 27?", data-remove-day-question, made by the page script as the server makes it);
     only a question of its own ("Remove today's history?", "Remove yesterday's history?") is printed on the button,
     chosen by the day (never by the words: the same markup in every language). --}}
@php $historyRowIndex = 0; @endphp
@foreach($days as $day)
@php
    $dayId = 'mkDay' . str_replace('-', '', $day['date']);
    $dayQuestion = \App\Support\WatchHistory::hasOwnDayQuestion($day['date']) ? \App\Support\WatchHistory::removeDayQuestion($day['date']) : null;
@endphp
<section class="mk-day" data-history-day="{{ $day['date'] }}" aria-labelledby="{{ $dayId }}"><div class="mk-day__head"><h2 class="mk-day__title" id="{{ $dayId }}">{{ $day['label'] }}</h2>@unless($searching ?? false)<button type="button" class="mk-btn mk-btn--quiet mk-btn--sm" data-history-remove-day=""@if($dayQuestion !== null) data-day-question="{{ $dayQuestion }}"@endif aria-label="{{ __('Remove :day from history', ['day' => $day['label']]) }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>{{ __('Remove') }}</span></button>@endunless</div><ul class="mk-lrows">
@foreach($day['views'] as $view)
@include('library.history-row', ['view' => $view, 'eager' => $historyRowIndex < (int) ($eagerRows ?? 0), 'high' => $historyRowIndex === 0 && (int) ($eagerRows ?? 0) > 0])
@if($historyRowIndex > 0 && $historyRowIndex < (int) ($eagerRows ?? 0))@push('preload')<link rel="preload" as="image" href="{{ \App\Support\PageUrl::local($view->video->thumbnail_url) }}" fetchpriority="high" media="(min-width: 576px)">@endpush @endif
@php $historyRowIndex++; @endphp
@endforeach
</ul></section>
@endforeach
