{{-- The playlist editor, new and existing (member design 6.7). Details, then two panes from 992px (tabs
     below): "In this playlist" (ordered rows; the drag handle with the Manual order; the first 8, then "Show N more") and
     "Add videos" (search; a video already in the list says "Added", nothing is added twice). The whole list of the
     playlist is in the page (studio-4). A save sends what the member CHANGED, never a snapshot:
     videos_order (the list as it ends, in its order, also the videos further down that were not shown yet) and
     videos_loaded (the list the page loaded), one comma-separated field each, so a long playlist is never cut by PHP's
     max_input_vars; UpdatePlaylistAction merges them with what the playlist holds now (a video saved to it from a watch
     page meanwhile stays). The search field is not part of the playlist: typing in it never marks the page changed
     (data-mk-pristine). A save the server refused comes back with the member's list and its "Unsaved changes".
     <playlist-videos> (resources/js/components/Playlists/Playlist.tsx) mounts over the same markup the server prints
     here. 1.2.3 round 1 (R1-022): the page prints the rows it shows first (EDITOR_FIRST) and the whole list once, as
     compact rows in a JSON script (#mk-pl-items, read by the component: `source`); it printed every row (hidden after
     the first 8) and the whole list again as an HTML-escaped attribute (a 5,000-video playlist was 4.7 MB).
     Variables: $playlist (null on the create page), $status, $sorts, $items, $loaded (the ids the page first
     loaded). --}}
@php
    use App\Enums\PlaylistSort;
    use App\Enums\PlaylistStatus;
    use App\Support\Studio\StudioFormat;

    $editing = $playlist !== null;
    $titleMax = (int) config('validation.playlist.title.max', 100);
    $descriptionMax = (int) config('validation.playlist.description.max', 5000);
    $titleValue = (string) old('title', $playlist?->getRawOriginal('title') ?? '');
    $descriptionValue = (string) old('description', $playlist?->description ?? '');
    $statusValue = (string) old('status', $playlist?->status?->value ?? PlaylistStatus::PUBLIC->value);
    $sortValue = (string) old('sort', $playlist?->sort?->value ?? PlaylistSort::MANUAL->value);
    $count = count($items);
    $first = \App\Http\Controllers\User\PlaylistController::EDITOR_FIRST;
    $pane = request()->query('pane') === 'add' ? 'add' : 'list';
    $sortLabels = [
        PlaylistSort::MANUAL->value => __('Manual order'),
        PlaylistSort::DATE_ADDED_NEWEST->value => __('Date added, newest first'),
        PlaylistSort::DATE_ADDED_OLDEST->value => __('Date added, oldest first'),
        PlaylistSort::POPULAR->value => __('Most viewed'),
        PlaylistSort::DATE_PUBLISHED_NEWEST->value => __('Date published, newest first'),
        PlaylistSort::DATE_PUBLISHED_OLDEST->value => __('Date published, oldest first'),
    ];
    $statusLabels = [PlaylistStatus::PUBLIC->value => __('Public'), PlaylistStatus::UNLISTED->value => __('Unlisted'), PlaylistStatus::PRIVATE->value => __('Private')];
    $kinds = [PlaylistStatus::PUBLIC->value => 'public', PlaylistStatus::UNLISTED->value => 'unlisted', PlaylistStatus::PRIVATE->value => 'private'];
    $errorCount = $errors->count();
    // what the member typed and the server refused is still unsaved (principle 7); a view-only refusal is not (6.18)
    $startDirty = ! $editing || (session()->hasOldInput() && ! $errors->has('view_only_demo'));
    $loaded = array_values(array_map('intval', $loaded ?? []));
    // [id, title, picture, duration, views text, private (1/0), added (Unix seconds), published (Unix seconds), views]
    $rows = array_map(fn (array $item) => [(int) $item['id'], (string) $item['title'], (string) $item['thumb'], (string) $item['dur'], (string) $item['views'], $item['private'] ? 1 : 0, (int) $item['added'], (int) $item['published'], (int) $item['viewsCount']], $items);
    $texts = [
        'inList' => __('In this playlist'),
        'add' => __('Add videos'),
        'order' => __('Order'),
        'dragHint' => __('Drag to reorder. Saving keeps every video in this order, also the ones further down.'),
        'showing' => __('Showing :shown of :count videos'),
        'more' => json_encode(array_map(fn (int $n) => $n === 0 ? '' : trans_choice('Show :count more|Show :count more', $n, ['count' => StudioFormat::number($n)]), range(0, 24)), JSON_UNESCAPED_UNICODE),
        'orderHint' => __('Choose Manual order to drag the videos.'),
        'remove' => __('Remove from playlist'),
        // a name and its value in the reader's punctuation (the Remove button's name: "Remove from playlist: <title>")
        'pair' => __(':label: :value'),
        'search' => __('Search your videos and the site'),
        'added' => __('Added'),
        'addOne' => __('Add'),
        'empty' => __('No videos yet'),
        'emptyHint' => __('Search for videos and add them here. Your own videos and every public video can be added.'),
        'noResults' => __('No video matches these words.'),
        'private' => __('A video that is not public any more'),
        'drag' => __('Drag to move'),
    ];
@endphp
<div class="avd-mk mk-page mk-page--has-bar"
     x-data="{ dirty: {{ $startDirty ? 'true' : 'false' }}, sending: false, pane: @js($pane), mark(event) { if (this.sending || (event && event.target && event.target.closest && event.target.closest('[data-mk-pristine]'))) return; this.dirty = true; } }"
     x-effect="document.documentElement.dataset.mkBar = dirty ? 'save' : ''"
     @beforeunload.window="if (dirty && !sending && {{ $editing ? 'true' : 'false' }}) { $event.preventDefault(); $event.returnValue = ''; }"
     @mk-playlist-change.window="mark()">
    <ol class="mk-crumbs">
        <li><a href="{{ route('user.playlists.index') }}">{{ __('Playlists') }}</a></li>
        <li><span dir="auto">{{ $editing ? $playlist->title : __('New playlist') }}</span></li>
    </ol>
    <header class="mk-head">
        <div class="mk-head__text">
            <span class="mk-head__kicker"><i class="fa-solid fa-rectangle-list" aria-hidden="true"></i>{{ __('Playlist') }}</span>
            <h1 class="mk-head__title mk-clamp-2" dir="auto">{{ $editing ? $playlist->title : __('New playlist') }}</h1>
            @if($editing)
                <p class="mk-head__desc">{{ trans_choice(':count video|:count videos', $count, ['count' => StudioFormat::number($count)]) }}&nbsp;· <x-mk.status :kind="$kinds[$playlist->status?->value ?? 0] ?? 'private'" :label="$statusLabels[$playlist->status?->value ?? 0] ?? __('Private')" />&nbsp;· {{ __('Updated :date', ['date' => StudioFormat::date($playlist->updated_at ?? $playlist->created_at)]) }}</p>
            @else
                <p class="mk-head__desc">{{ __('Name it, pick who can see it, then add videos.') }}</p>
            @endif
        </div>
        @if($editing && $playlist->status !== PlaylistStatus::PRIVATE)
            <div class="mk-head__actions">
                <a class="mk-btn mk-btn--secondary" href="{{ $playlist->route }}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span>{{ __('View playlist') }}</span></a>
            </div>
        @endif
    </header>
    <x-mk.flash refusal />
    @unless($errors->has('view_only_demo'))
        @if($errorCount >= 2)
            <x-mk.alert type="danger" :title="__('Please check these fields')" tabindex="-1" data-mk-focus>
                <ul style="margin:0;padding-inline-start:1.1rem">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
            </x-mk.alert>
        @elseif($errors->has('videos') || $errors->has('videos.*') || $errors->has('videos_order') || $errors->has('videos_loaded'))
            <x-mk.alert type="danger" tabindex="-1" data-mk-focus>{{ $errors->first('videos') ?: ($errors->first('videos.*') ?: ($errors->first('videos_order') ?: $errors->first('videos_loaded'))) }}</x-mk.alert>
        @endif
    @endunless

    <form method="POST" action="{{ $editing ? route('user.playlists.update', $playlist) : route('user.playlists.store') }}" id="mk-playlist-form"
          @input="mark($event)" @change="mark($event)" @submit="sending = true; dirty = false" style="display:grid;gap:var(--mk-gap)">
        @csrf
        @if($editing)
            @method('PUT')
        @endif
        <section class="mk-panel" aria-labelledby="mk-pl-details-title">
            <div class="mk-panel__head"><h2 class="mk-panel__title" id="mk-pl-details-title">{{ __('Details') }}</h2></div>
            <div class="mk-panel__body">
                <div class="mk-form">
                    <div class="mk-form__row mk-form__row--2">
                        <div class="mk-field" x-data="{ count: {{ mb_strlen($titleValue) }} }">
                            <label class="mk-label" for="title">{{ __('Title') }}</label>
                            <input class="mk-input" id="title" name="title" type="text" data-mk-dir{!! trim($titleValue) !== '' ? ' dir="auto"' : '' !!} required maxlength="{{ $titleMax }}" value="{{ $titleValue }}" @input="count = $el.value.length" @if($errors->has('title')) aria-invalid="true" aria-describedby="title-error" @endif>
                            <div class="mk-counter"><span x-text="count">{{ mb_strlen($titleValue) }}</span> / {{ StudioFormat::number($titleMax) }}</div>
                            @if($errors->has('title'))<span class="mk-error" id="title-error">{{ $errors->first('title') }}</span>@endif
                        </div>
                        <div class="mk-field">
                            <label class="mk-label" for="status">{{ __('Visibility') }}</label>
                            <select class="form-select" id="status" name="status" data-native-select required>
                                @foreach($statusLabels as $value => $label)
                                    <option value="{{ $value }}" @selected($statusValue === (string) $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mk-field">
                        <label class="mk-label" for="description">{{ __('Description') }} <span class="mk-label__opt">{{ __('optional') }}</span></label>
                        <textarea class="mk-input" id="description" name="description" rows="3" data-mk-dir{!! trim((string) $descriptionValue) !== '' ? ' dir="auto"' : '' !!} maxlength="{{ $descriptionMax }}" @if($errors->has('description')) aria-invalid="true" aria-describedby="description-error" @endif>{{ $descriptionValue }}</textarea>
                        @if($errors->has('description'))<span class="mk-error" id="description-error">{{ $errors->first('description') }}</span>@endif
                    </div>
                </div>
            </div>
        </section>

        <nav class="mk-tabs mk-pl-tabs" aria-label="{{ __('Playlist editor') }}">
            <a class="mk-tab" href="?pane=list" :class="{ 'is-active': pane === 'list' }" :aria-current="pane === 'list' ? 'page' : null" @click.prevent="pane = 'list'" @if($pane === 'list') aria-current="page" @endif>{{ $texts['inList'] }}<span class="mk-count" data-mk-pl-count>{{ StudioFormat::number($count) }}</span></a>
            <a class="mk-tab" href="?pane=add" :class="{ 'is-active': pane === 'add' }" :aria-current="pane === 'add' ? 'page' : null" @click.prevent="pane = 'add'" @if($pane === 'add') aria-current="page" @endif>{{ $texts['add'] }}</a>
        </nav>
        <div class="mk-grid mk-grid--1-1 mk-pl-editor" data-pane="{{ $pane }}" :data-pane="pane">
            <playlist-videos uuid="{{ $playlist?->uuid }}" sort="{{ $sortValue }}" first="{{ $first }}" loaded="{{ implode(',', $loaded) }}" source="mk-pl-items" texts='@json($texts)' sorts='@json($sortLabels)'>
                <section class="mk-panel mk-pl-pane mk-pl-pane--list" aria-labelledby="mk-pl-in-title">
                    <div class="mk-panel__head">
                        <h2 class="mk-panel__title" id="mk-pl-in-title">{{ $texts['inList'] }} ({{ StudioFormat::number($count) }})</h2>
                        <select class="form-select" name="sort" data-native-select style="inline-size:auto" aria-label="{{ $texts['order'] }}">
                            @foreach($sortLabels as $value => $label)
                                <option value="{{ $value }}" @selected($sortValue === (string) $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mk-panel__sub">{{ $sortValue === (string) PlaylistSort::MANUAL->value ? $texts['dragHint'] : $texts['orderHint'] }}</p>
                    </div>
                    @if($count === 0)
                        <x-mk.empty icon="rectangle-list" :title="$texts['empty']" :text="$texts['emptyHint']" compact />
                    @else
                        <ol class="mk-rows">
                            @foreach(array_slice($items, 0, $first) as $index => $item)
                                <li class="mk-item mk-item--drag">
                                    @if($sortValue === (string) PlaylistSort::MANUAL->value)<span class="mk-drag" aria-hidden="true"><i class="fa-solid fa-grip-vertical"></i></span>@endif
                                    <span class="mk-item__pos">{{ $index + 1 }}</span>
                                    <span class="mk-item__media mk-item__media--sm"><span class="mk-thumb">@if($item['thumb'])<img src="{{ $item['thumb'] }}" alt="" width="160" height="90" loading="lazy" decoding="async">@else<span class="mk-thumb__empty"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>@endif @if($item['dur'] !== '')<span class="mk-thumb__dur">{{ $item['dur'] }}</span>@endif</span></span>
                                    <div class="mk-item__body"><span class="mk-item__title mk-clamp-2" dir="auto">{{ $item['private'] ? $texts['private'] : $item['title'] }}</span>@if($item['views'] !== '')<span class="mk-muted" style="font-size:.8125rem">{{ $item['views'] }}</span>@endif</div>
                                    <div class="mk-item__aside"><span class="mk-icon-btn mk-icon-btn--muted" aria-hidden="true"><i class="fa-solid fa-xmark"></i></span></div>
                                </li>
                            @endforeach
                        </ol>
                        @if($count > $first)
                            <div class="mk-panel__foot"><span class="mk-muted" style="font-size:.8125rem">{{ __('Showing :shown of :count videos', ['shown' => StudioFormat::number($first), 'count' => StudioFormat::number($count)]) }}</span><span class="mk-btn mk-btn--quiet mk-btn--sm">{{ trans_choice('Show :count more|Show :count more', min(24, $count - $first), ['count' => StudioFormat::number(min(24, $count - $first))]) }}</span></div>
                        @endif
                    @endif
                    <input type="hidden" name="videos_order" value="{{ implode(',', array_map(fn (array $item) => (int) $item['id'], $items)) }}">
                    @if($editing)
                        <input type="hidden" name="videos_loaded" value="{{ implode(',', $loaded) }}">
                    @endif
                </section>
                <section class="mk-panel mk-pl-pane mk-pl-pane--add" aria-labelledby="mk-pl-add-title">
                    <div class="mk-panel__head"><h2 class="mk-panel__title" id="mk-pl-add-title">{{ $texts['add'] }}</h2></div>
                    <div class="mk-panel__body">
                        <label class="mk-search" data-mk-pristine><span class="mk-sr">{{ $texts['search'] }}</span><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input class="mk-input" type="search" placeholder="{{ $texts['search'] }}" autocomplete="off"></label>
                    </div>
                    <ul class="mk-rows"></ul>
                </section>
            </playlist-videos>
        </div>
        <script type="application/json" id="mk-pl-items">{!! json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>

        <div class="mk-savebar">
            <span class="mk-savebar__note"><span class="mk-dot" aria-hidden="true"></span>{{ $editing ? __('Unsaved changes') : __('Not saved yet') }}</span>
            <a class="mk-btn mk-btn--quiet" href="{{ $editing ? route('user.playlists.edit', $playlist) : route('user.playlists.index') }}" @click="dirty = false">{{ __('Cancel') }}</a>
            <button type="submit" class="mk-btn mk-btn--primary" name="action" value="save"><i class="fa-solid fa-check" aria-hidden="true"></i><span>{{ $editing ? __('Save playlist') : __('Create playlist') }}</span></button>
        </div>
    </form>
</div>
