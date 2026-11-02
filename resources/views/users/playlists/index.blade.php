@extends('layouts.user')

@section('title', __('Playlists'))

@section('content')
@php
    use App\Support\Studio\StudioFormat;
    use App\Enums\PlaylistStatus;

    $rows = $playlists->getCollection();
    $count = $rows->count();
    $kinds = [PlaylistStatus::PUBLIC->value => ['public', __('Public')], PlaylistStatus::UNLISTED->value => ['unlisted', __('Unlisted')], PlaylistStatus::PRIVATE->value => ['private', __('Private')]];
    // What the row menus need that the row does not print (lean rows, 4.3): the delete address template and per row the
    // items it has (1 = the template). The delete dialog names the playlist by the row's title ([data-mk-name]).
    $menuData = ['u' => ['delete' => \App\Support\Studio\StudioHtml::pathTemplate('user.playlists.destroy', 'playlist')], 'r' => []];
    foreach ($rows as $playlist) {
        $menuData['r'][$playlist->id] = array_filter([
            'view' => \App\Support\Studio\StudioHtml::relative($playlist->route),
            'copy' => $playlist->status === PlaylistStatus::PRIVATE ? 0 : 1,
            'delete' => 1,
        ]);
    }
    $labels = [
        'selected' => \App\Support\Studio\StudioHtml::countWords(fn ($n, $c) => trans_choice(':count playlist selected|:count playlists selected', $n, ['count' => $c]), $count),
        'title' => \App\Support\Studio\StudioHtml::countWords(fn ($n, $c) => trans_choice('Delete :count playlist?|Delete :count playlists?', $n, ['count' => $c]), $count),
        'delete' => \App\Support\Studio\StudioHtml::countWords(fn ($n, $c) => trans_choice('Delete :count playlist|Delete :count playlists', $n, ['count' => $c]), $count),
    ];
    $value = fn (string $key) => is_string(request()->query($key)) ? request()->query($key) : '';
@endphp
<div class="avd-mk mk-page"
     x-data="{
        selected: [],
        labels: {},
        init() { try { this.labels = JSON.parse(document.getElementById('mk-playlist-labels').textContent); } catch (e) { this.labels = {}; } },
        boxes() { return Array.from(this.$root.querySelectorAll('.mk-item__check input[data-row]')); },
        toggle(event) {
            const box = event.target;
            if (!box || !box.matches || !box.matches('input[data-row]')) return;
            const id = Number(box.value);
            this.selected = box.checked ? [...new Set([...this.selected, id])] : this.selected.filter(v => v !== id);
        },
        all(on) { const ids = []; this.boxes().forEach(box => { box.checked = on; if (on) ids.push(Number(box.value)); }); this.selected = ids; },
        word(key) { return window.avdMkCount ? window.avdMkCount(this.labels[key], this.selected.length) : ''; }
     }"
     x-effect="document.documentElement.dataset.mkBar = selected.length ? 'bulk' : ''">
    <x-mk.head icon="rectangle-list" :kicker="__('Studio')" :title="__('Playlists')"
               :desc="trans_choice(':count playlist. Watch later and Liked videos are in your Library.|:count playlists. Watch later and Liked videos are in your Library.', $total, ['count' => StudioFormat::number($total)])">
        <x-slot:actions>
            <a class="mk-btn mk-btn--primary" href="{{ route('user.playlists.create') }}"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>{{ __('New playlist') }}</span></a>
        </x-slot:actions>
    </x-mk.head>
    <x-mk.flash refusal />

    @if($total === 0)
        <section class="mk-panel">
            <x-mk.empty icon="rectangle-list" :title="__('Create your first playlist')" :text="__('Group your videos and other channels\' videos in the order you like. Watch later and Liked videos are in your Library.')">
                <a class="mk-btn mk-btn--primary" href="{{ route('user.playlists.create') }}"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>{{ __('New playlist') }}</span></a>
            </x-mk.empty>
        </section>
    @else
        <section class="mk-panel" aria-label="{{ __('Playlists') }}" data-selection="0" :data-selection="selected.length ? 1 : 0">
            {{-- 1.2.3 round 1 (R1-026): the toolbar and the bulk bar share one cell (.mk-bars, member/_kit.scss): a tick swaps them without moving the list --}}
            <div class="mk-bars">
                @include('users.partials.filters', [
                    'id' => 'playlists',
                    'action' => route('user.playlists.index'),
                    'hidden' => [],
                    'search' => ['name' => 'search', 'value' => $value('search'), 'label' => __('Search playlists'), 'placeholder' => __('Search playlists')],
                    'selects' => [
                        ['name' => 'status', 'label' => __('Visibility'), 'options' => ['' => __('Any visibility'), PlaylistStatus::PUBLIC->value => __('Public'), PlaylistStatus::UNLISTED->value => __('Unlisted'), PlaylistStatus::PRIVATE->value => __('Private')], 'value' => $value('status'), 'fold' => false],
                        ['name' => 'sort', 'label' => __('Sort'), 'options' => ['' => __('Last updated'), 'created' => __('Newest first'), 'title' => __('By title')], 'value' => $value('sort'), 'fold' => false],
                    ],
                    'custom' => null,
                    'clearUrl' => route('user.playlists.index'),
                    'active' => $filtersActive,
                ])
                <div class="mk-bulkbar" role="region" aria-label="{{ __('Selected playlists') }}">
                    <label class="mk-item__check mk-bulkbar__all"><input class="mk-check" type="checkbox" aria-label="{{ __('Select all on this page') }}" :checked="selected.length > 0 && selected.length === boxes().length" x-effect="$el.indeterminate = selected.length > 0 && selected.length < boxes().length" @change="all($event.target.checked)"></label>
                    <span class="mk-bulkbar__count" x-text="word('selected')" aria-live="polite"></span>
                    <button class="mk-btn mk-btn--danger-quiet mk-btn--sm" type="button" data-bs-toggle="modal" data-bs-target="#mk-delete-playlists"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ __('Delete') }}</span></button>
                    <button class="mk-icon-btn mk-icon-btn--muted mk-bulkbar__close" type="button" aria-label="{{ __('Clear selection') }}" title="{{ __('Clear selection') }}" @click="all(false)"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
            </div>
            @if($count === 0)
                <x-mk.empty icon="filter" :title="__('No playlists match these filters')" :text="__('Try other words or another filter.')" compact>
                    <a class="mk-btn mk-btn--secondary" href="{{ route('user.playlists.index') }}">{{ __('Clear filters') }}</a>
                </x-mk.empty>
            @else
                <ul class="mk-rows" data-mk-menu-tpl="mk-playlist-menu" data-mk-menu-data="mk-playlist-rows" @change="toggle($event)">
                    @foreach($rows as $playlist)
                        @php
                            $cover = $playlist->videos->first();
                            $coverUrl = $cover && ($cover->thumbnail || ! empty($cover->poster_url)) ? $cover->thumbnail_url : null;
                            [$kind, $kindLabel] = $kinds[$playlist->status?->value ?? 0] ?? ['private', __('Private')];
                        @endphp
                        <li class="mk-item mk-item--media">
                            <label class="mk-item__check"><input class="mk-check" type="checkbox" value="{{ $playlist->id }}" data-row aria-label="{{ __('Select :title', ['title' => $playlist->title]) }}"></label>
                            <a href="{{ route('user.playlists.edit', $playlist) }}" class="mk-item__media" tabindex="-1" aria-hidden="true">
                                <span class="mk-thumb">
                                    @if($coverUrl)<img src="{{ $coverUrl }}" alt="" width="160" height="90" @if($loop->index >= 4) loading="lazy" @endif decoding="async">@else<span class="mk-thumb__empty"><i class="fa-solid fa-list"></i></span>@endif
                                    <span class="mk-thumb__count"><i class="fa-solid fa-list" aria-hidden="true"></i> {{ StudioFormat::number((int) $playlist->videos_count) }}</span>
                                </span>
                            </a>
                            <div class="mk-item__body">
                                <a class="mk-item__title" href="{{ route('user.playlists.edit', $playlist) }}" data-mk-name><bdi>{{ $playlist->title }}</bdi></a>
                                <div class="mk-row__meta"><span><x-mk.status :kind="$kind" :label="$kindLabel" /></span><span>{{ trans_choice(':count video|:count videos', (int) $playlist->videos_count, ['count' => StudioFormat::number((int) $playlist->videos_count)]) }}</span><span>{{ __('Updated :date', ['date' => StudioFormat::date($playlist->updated_at ?? $playlist->created_at)]) }}</span></div>
                            </div>
                            <div class="mk-item__aside">
                                <a class="mk-icon-btn mk-icon-btn--muted" href="{{ route('user.playlists.edit', $playlist) }}" title="{{ __('Edit playlist') }}" aria-label="{{ __('Edit playlist') }}"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                                <div class="mk-row__menu">
                                    <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="menu" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}" data-mk-menu="{{ $playlist->id }}"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
            {{ $playlists->onEachSide(1)->links('vendor.pagination.mk') }}
        </section>

        <form id="mk-playlists-delete-form" method="POST" action="{{ route('user.playlists.mass-delete') }}" hidden @submit="avdMkIds($el, selected)">
            @csrf
            @method('DELETE')
        </form>
        <script type="application/json" id="mk-playlist-rows">{!! json_encode($menuData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <script type="application/json" id="mk-playlist-labels">{!! json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <template id="mk-playlist-menu">
            <a class="dropdown-item mk-menu__item" data-k="view" target="_blank" rel="noopener" role="menuitem"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>{{ __('View playlist') }}</a>
            <button class="dropdown-item mk-menu__item" type="button" data-k="copy" role="menuitem"><i class="fa-regular fa-copy" aria-hidden="true"></i><span>{{ __('Copy link') }}</span></button>
            <hr>
            <button class="dropdown-item mk-menu__item mk-menu__item--danger" type="button" data-k="delete" data-bs-toggle="modal" data-bs-target="#mk-delete-playlist" role="menuitem"><i class="fa-regular fa-trash-can" aria-hidden="true"></i>{{ __('Delete playlist…') }}</button>
        </template>

        <div class="modal fade" id="mk-delete-playlist" tabindex="-1" aria-labelledby="mk-delete-playlist-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
                <div class="modal-content avd-mk">
                    <div class="mk-sheet__head">
                        <h2 class="mk-sheet__title" id="mk-delete-playlist-title">{{ __('Delete this playlist?') }}</h2>
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <div class="mk-sheet__body"><p class="mk-sheet__lead"><strong data-mk-title dir="auto"></strong></p><p class="mk-muted">{{ __('The videos stay where they are; only the list goes.') }}</p></div>
                    <div class="mk-sheet__foot">
                        <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal" data-mk-autofocus>{{ __('Cancel') }}</button>
                        <button type="submit" class="mk-btn mk-btn--danger" form="mk-delete-playlist-form"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ __('Delete playlist') }}</span></button>
                    </div>
                    <form id="mk-delete-playlist-form" method="POST" action="" data-mk-action-from hidden>@csrf @method('DELETE')</form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="mk-delete-playlists" tabindex="-1" aria-labelledby="mk-delete-playlists-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
                <div class="modal-content avd-mk">
                    <div class="mk-sheet__head">
                        <h2 class="mk-sheet__title" id="mk-delete-playlists-title" x-text="word('title')">{{ __('Delete these playlists?') }}</h2>
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <div class="mk-sheet__body"><p class="mk-sheet__lead">{{ __('The videos stay where they are; only the lists go. You can\'t undo this.') }}</p></div>
                    <div class="mk-sheet__foot">
                        <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal" data-mk-autofocus>{{ __('Cancel') }}</button>
                        <button type="submit" class="mk-btn mk-btn--danger" form="mk-playlists-delete-form"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span x-text="word('delete')"></span></button>
                    </div>
                </div>
            </div>
        </div>
    @endif
    @stack('mk-overlays')
</div>
@endsection

@push('scripts')
    @include('users.partials.studio-scripts', ['studioScriptsParts' => ['lists']])
@endpush
