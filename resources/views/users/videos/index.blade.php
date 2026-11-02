@extends('layouts.user')

@section('title', __('My videos'))

@section('content')
@php
    use App\Support\Studio\StudioFormat;
    use App\Enums\VideoStatus;

    $mkRows = $videos->getCollection();
    $mkCount = $mkRows->count();
    $mkQuery = request()->only(['search', 'category', 'type', 'date', 'from', 'to', 'sort']);
    $mkQuery = array_filter($mkQuery, fn ($value) => is_string($value) && $value !== '');
    $mkTabQuery = fn (?VideoStatus $status) => array_filter($mkQuery + ['status' => $status?->value], fn ($value) => $value !== null && $value !== '');
    $mkTabLabels = [
        'all' => __('All'), 'public' => __('Public'), 'scheduled' => __('Scheduled'), 'unlisted' => __('Unlisted'),
        'private' => __('Private'), 'drafts' => __('Drafts'), 'failed' => __('Failed'), 'banned' => __('Banned'),
    ];
    $mkCurrentStatus = is_string(request()->query('status')) ? request()->query('status') : '';
    $mkToday = \Carbon\CarbonImmutable::now(\App\Support\Studio\ZoneLabel::zone());

    // What the row menus and the delete dialogs need that the row does not print (lean rows, 4.3): address templates,
    // the menu items each row has (1 = the template), the dialog's date, the counts, and the counted words of the
    // counts this page has. Title, picture, length and status come from the row itself (info() below).
    $mkTpl = fn (string $name) => \App\Support\Studio\StudioHtml::pathTemplate($name, 'video');
    // "upload" is the upload panel's trigger (#video_create), not an address: a failed upload's menu offers it (R1-093)
    $mkData = ['u' => [
        'finish' => $mkTpl('user.videos.create'), 'edit' => $mkTpl('user.videos.edit'), 'analytics' => $mkTpl('user.videos.show'),
        'pin' => $mkTpl('user.videos.pin'), 'unpin' => $mkTpl('user.videos.unpin'), 'delete' => $mkTpl('user.videos.destroy'),
        'upload' => '#video_create',
    ], 'r' => [], 'w' => ['c' => [], 'l' => [], 'd' => [], 'p' => [], 'and' => __(':likes and :dislikes')]];
    foreach ($mkRows as $mkVideo) {
        $s = $mkVideo->status instanceof VideoStatus ? $mkVideo->status : VideoStatus::tryFrom((int) $mkVideo->status);
        $failed = $s === VideoStatus::FAILED;
        $draft = $s === VideoStatus::DRAFT;
        $banned = $s === VideoStatus::BANNED;
        $created = ! $failed && ! $draft && ! $mkVideo->is_uploading;
        // Pin or Unpin exactly as VideoPolicy allows it (its is_pinned reads the owner's pinned video through the active
        // scopes: an owner without a verified e-mail can never unpin, a 403 page before), as the 1.2.2 page did with
        // @can / @elsecan. The owner's pinned video is read once (the rows share the signed-in user).
        $mkPin = $created && $mkVideo->is_active
            ? (\Illuminate\Support\Facades\Gate::allows('pin', $mkVideo) ? 'pin' : (\Illuminate\Support\Facades\Gate::allows('unpin', $mkVideo) ? 'unpin' : null))
            : null;
        $mkData['r'][$mkVideo->id] = array_filter([
            'finish' => $draft ? 1 : 0,
            // a failed upload cannot be edited (VideoPolicy::update answers 403): its menu offers "Upload again" (when
            // uploads are on) and Delete, as the wide row does (1.2.3 round 1, R1-093). A draft (one still processing on
            // Upload18 too) has "Finish the draft" only: Video details sends every draft to that page (round 2, R2A-034).
            'edit' => ! $draft && ($created || $mkVideo->is_uploading) && ! $banned && ! $failed ? 1 : 0,
            'upload' => $failed && $uploadsAllowed ? 1 : 0,
            'analytics' => $created ? 1 : 0,
            'view' => $created && ! $banned ? \App\Support\Studio\StudioHtml::relative($mkVideo->route) : 0,
            'copy' => $created && ! $banned ? 1 : 0,
            'pin' => $mkPin === 'pin' ? 1 : 0,
            'unpin' => $mkPin === 'unpin' ? 1 : 0,
            'delete' => 1,
            't' => StudioFormat::date($created ? ($mkVideo->published_at ?? $mkVideo->created_at) : $mkVideo->created_at),
            // what deleting removes: every comment, the hidden ones too (the row shows the ones viewers see)
            'c' => (int) ($mkVideo->comments_all_count ?? $mkVideo->comments_count),
            'l' => (int) $mkVideo->likes_count,
            'd' => (int) $mkVideo->dislikes_count,
            'p' => (int) $mkVideo->playlists_count,
            'x' => ! $failed && ! $draft && ! $banned && ! $mkVideo->is_uploading ? 1 : 0,
            'pv' => $s === VideoStatus::PRIVATE ? 1 : 0,
        ]);
        // the words of the counts this page has, each count once
        [$c, $l, $d, $p] = [(int) ($mkVideo->comments_all_count ?? $mkVideo->comments_count), (int) $mkVideo->likes_count, (int) $mkVideo->dislikes_count, (int) $mkVideo->playlists_count];
        $mkData['w']['c'][$c] ??= trans_choice(':count comment and its replies|:count comments and their replies', $c, ['count' => StudioFormat::number($c)]);
        $mkData['w']['l'][$l] ??= trans_choice(':count like|:count likes', $l, ['count' => StudioFormat::number($l)]);
        $mkData['w']['d'][$d] ??= trans_choice(':count dislike|:count dislikes', $d, ['count' => StudioFormat::number($d)]);
        if ($p > 0) {
            $mkData['w']['p'][$p] ??= trans_choice('Its place in :count playlist|Its place in :count playlists', $p, ['count' => StudioFormat::number($p)]);
        }
    }

    // the counted words for every number of ticked rows this page can have, as runs (exact plural forms in every
    // language: StudioHtml::countWords, read by avdMkCount())
    $mkPlural = fn (\Closure $choice) => \App\Support\Studio\StudioHtml::countWords($choice, $mkCount);
    $mkLabels = [
        'selected' => $mkPlural(fn ($n, $c) => trans_choice(':count video selected|:count videos selected', $n, ['count' => $c])),
        'title' => $mkPlural(fn ($n, $c) => trans_choice('Delete :count video?|Delete :count videos?', $n, ['count' => $c])),
        'delete' => $mkPlural(fn ($n, $c) => trans_choice('Delete :count video|Delete :count videos', $n, ['count' => $c])),
        'private' => $mkPlural(fn ($n, $c) => trans_choice('Make :count video private|Make :count videos private', $n, ['count' => $c])),
        'public' => $mkPlural(fn ($n, $c) => trans_choice('Make :count video public|Make :count videos public', $n, ['count' => $c])),
        'unlisted' => $mkPlural(fn ($n, $c) => trans_choice('Make :count video unlisted|Make :count videos unlisted', $n, ['count' => $c])),
        'sheet' => $mkPlural(fn ($n, $c) => trans_choice('Change the visibility of :count video|Change the visibility of :count videos', $n, ['count' => $c])),
        'more' => $mkPlural(fn ($n, $c) => trans_choice('and :count more|and :count more', $n, ['count' => $c])),
    ];
    $mkDateOptions = ['' => __('Any date'), '7' => __('Last 7 days'), '28' => __('Last 28 days'), '90' => __('Last 90 days'), 'year' => __('This year'), 'custom' => __('Custom dates')];
    $mkCategoryOptions = ['' => __('All categories'), 'without' => __('Without a category')] + $categories->mapWithKeys(fn ($category) => [$category->id => __($category->title)])->all();
    $mkTypeOptions = ['' => __('All types'), 'videos' => __('Videos'), 'shorts' => __('Shorts')];
    $mkSortOptions = ['' => __('Newest first'), 'oldest' => __('Oldest first'), 'views' => __('Most viewed'), 'comments' => __('Most comments')];
    $mkFilterValue = fn (string $key) => is_string(request()->query($key)) ? request()->query($key) : '';
@endphp
{{-- My videos' Alpine component (mkVideos: the ticked rows, the bulk bar, the bulk dialog and sheet, the single Delete
     dialog) is registered by resources/js/member/studio-pages.js, one cached file (1.2.3 round 1b, R1R-01: it was 5 KB of
     every My videos page). --}}
<div class="avd-mk mk-page"
     x-data="mkVideos"
     x-effect="document.documentElement.dataset.mkBar = selected.length ? 'bulk' : ''">
    <x-mk.head icon="clapperboard" :kicker="__('Studio')" :title="__('My videos')"
               :desc="trans_choice(':count video. Visitors only see the public ones.|:count videos. Visitors only see the public ones.', $tabs['all'], ['count' => StudioFormat::number($tabs['all'])])">
        @if($uploadsAllowed)
            <x-slot:actions>
                <button type="button" class="mk-btn mk-btn--primary mk-head__upload" data-bs-toggle="modal" data-bs-target="#video_create"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i><span>{{ __('Upload video') }}</span></button>
            </x-slot:actions>
        @endif
    </x-mk.head>
    <x-mk.flash refusal />

    @if($tabs['all'] === 0)
        <section class="mk-panel">
            <x-mk.empty icon="clapperboard" :title="__('Upload your first video')" :text="$uploadsAllowed ? __('Your videos appear here, with their visibility, views and comments.') : __('Uploads are switched off on this site.')">
                @if($uploadsAllowed)
                    <button type="button" class="mk-btn mk-btn--primary" data-bs-toggle="modal" data-bs-target="#video_create"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i><span>{{ __('Upload video') }}</span></button>
                @endif
            </x-mk.empty>
        </section>
    @else
        <section class="mk-panel" aria-label="{{ __('My videos') }}" data-selection="0" :data-selection="selected.length ? 1 : 0">
            <nav class="mk-tabs" aria-label="{{ __('Filter by visibility') }}" style="padding-inline:var(--mk-pad)">
                @foreach(\App\Http\Controllers\User\VideoController::TABS as $mkKey => $mkStatus)
                    @continue($mkKey !== 'all' && ($tabs[$mkKey] ?? 0) === 0 && $currentTab !== $mkKey)
                    <a @class(['mk-tab', 'is-active' => $currentTab === $mkKey]) href="{{ route('user.videos.index', $mkTabQuery($mkStatus)) }}" @if($currentTab === $mkKey) aria-current="page" @endif>{{ $mkTabLabels[$mkKey] }}<span class="mk-count">{{ StudioFormat::number($tabs[$mkKey] ?? 0) }}</span></a>
                @endforeach
            </nav>
            {{-- 1.2.3 round 1 (R1-026): the toolbar and the bulk bar share one cell (.mk-bars, member/_kit.scss): a tick swaps them without moving the list --}}
            <div class="mk-bars">
                @include('users.partials.filters', [
                    'id' => 'videos',
                    'action' => route('user.videos.index'),
                    'hidden' => ['status' => $mkCurrentStatus],
                    'search' => ['name' => 'search', 'value' => $mkFilterValue('search'), 'label' => __('Search my videos'), 'placeholder' => __('Search title or description')],
                    'selects' => [
                        ['name' => 'category', 'label' => __('Category'), 'options' => $mkCategoryOptions, 'value' => $mkFilterValue('category'), 'native' => $categoriesNative, 'fold' => true],
                        ['name' => 'type', 'label' => __('Type'), 'options' => $mkTypeOptions, 'value' => $mkFilterValue('type'), 'fold' => true, 'seg' => true, 'segOptions' => ['' => __('All'), 'videos' => __('Videos'), 'shorts' => __('Shorts')]],
                        ['name' => 'date', 'label' => __('Date'), 'options' => $mkDateOptions, 'value' => $mkFilterValue('date'), 'fold' => true, 'custom' => true],
                        ['name' => 'sort', 'label' => __('Sort'), 'sheetLabel' => __('Sort by'), 'options' => $mkSortOptions, 'value' => $mkFilterValue('sort'), 'fold' => true],
                    ],
                    'custom' => ['from' => $mkFilterValue('from') ?: $mkToday->subDays(27)->toDateString(), 'to' => $mkFilterValue('to') ?: $mkToday->toDateString(), 'max' => $mkToday->toDateString()],
                    'clearUrl' => route('user.videos.index', array_filter(['status' => $mkCurrentStatus])),
                    'active' => $filtersActive,
                    'submitLabel' => __('Show videos'),
                ])
                <div class="mk-bulkbar" role="region" aria-label="{{ __('Selected videos') }}">
                    <label class="mk-item__check mk-bulkbar__all"><input class="mk-check" type="checkbox" aria-label="{{ __('Select all on this page') }}" :checked="selected.length > 0 && selected.length === rows" x-effect="$el.indeterminate = selected.length > 0 && selected.length < rows" @change="all($event.target.checked)"></label>
                    <span class="mk-bulkbar__count" x-text="word('selected')" aria-live="polite"></span>
                    <button class="mk-btn mk-btn--secondary mk-btn--sm mk-bulkbar__vis--menu" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="menu"><i class="fa-regular fa-eye" aria-hidden="true"></i><span>{{ __('Visibility') }}</span></button>
                    <div class="dropdown-menu mk-menu" role="menu" aria-label="{{ __('Change visibility') }}">
                        @foreach(['public' => ['earth-americas', __('Public')], 'unlisted' => ['link', __('Unlisted')], 'private' => ['lock', __('Private')]] as $mkVis => [$mkIcon, $mkLabel])
                            <button class="dropdown-item mk-menu__item" type="submit" form="mk-visibility-form" name="visibility" value="{{ $mkVis }}" role="menuitem"><i class="fa-solid fa-{{ $mkIcon }}" aria-hidden="true"></i>{{ $mkLabel }}</button>
                        @endforeach
                        <hr><span class="mk-menu__note">{{ __('Drafts and failed uploads keep their state.') }}</span>
                    </div>
                    <button class="mk-btn mk-btn--secondary mk-btn--sm mk-bulkbar__vis--sheet" type="button" data-bs-toggle="offcanvas" data-bs-target="#mk-visibility-sheet" aria-controls="mk-visibility-sheet"><i class="fa-regular fa-eye" aria-hidden="true"></i><span>{{ __('Visibility') }}</span></button>
                    <button class="mk-btn mk-btn--danger-quiet mk-btn--sm" type="button" data-bs-toggle="modal" data-bs-target="#mk-delete-videos"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ __('Delete') }}</span></button>
                    <button class="mk-icon-btn mk-icon-btn--muted mk-bulkbar__close" type="button" aria-label="{{ __('Clear selection') }}" title="{{ __('Clear selection') }}" @click="all(false)"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
            </div>
            @if($mkCount === 0)
                <x-mk.empty icon="filter" :title="__('No videos match these filters')" :text="__('Try other words or another filter.')" compact>
                    <a class="mk-btn mk-btn--secondary" href="{{ route('user.videos.index') }}">{{ __('Clear filters') }}</a>
                </x-mk.empty>
            @else
                <div class="mk-list">
                    <div class="mk-list__head" aria-hidden="true">
                        <span></span><span>{{ __('Video') }}</span><span></span><span>{{ __('Visibility') }}</span><span class="is-num">{{ __('Views') }}</span><span class="is-num">{{ __('Comments') }}</span><span>{{ __('Likes') }}</span><span></span>
                    </div>
                    <ul class="mk-rows" data-mk-menu-tpl="mk-video-menu" data-mk-menu-data="mk-video-rows" @change="toggle($event)">
                        @foreach($mkRows as $video)
                            @include('users.videos.partials.row', ['video' => $video, 'rowIndex' => $loop->index])
                        @endforeach
                    </ul>
                </div>
            @endif
            {{ $videos->onEachSide(1)->links('vendor.pagination.mk') }}
        </section>

        {{-- The ticked videos, once: the visibility choices post here; Delete posts the same ids with its own address and
             method (formaction, _method from the button). --}}
        <form id="mk-visibility-form" method="POST" action="{{ route('user.videos.mass-visibility') }}" hidden @submit="avdMkIds($el, selected)">
            @csrf
        </form>
        <script type="application/json" id="mk-video-rows">{!! json_encode($mkData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <script type="application/json" id="mk-video-labels">{!! json_encode($mkLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <template id="mk-video-menu">
            <button class="dropdown-item mk-menu__item" type="button" data-k="upload" data-bs-toggle="modal" data-bs-target="#video_create" role="menuitem"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i><span>{{ __('Upload again') }}</span></button>
            <a class="dropdown-item mk-menu__item" data-k="finish" role="menuitem"><i class="fa-solid fa-file-pen" aria-hidden="true"></i>{{ __('Finish the draft') }}</a>
            <a class="dropdown-item mk-menu__item" data-k="edit" role="menuitem"><i class="fa-solid fa-pen" aria-hidden="true"></i>{{ __('Edit details') }}</a>
            <a class="dropdown-item mk-menu__item" data-k="analytics" role="menuitem"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i>{{ __('Analytics') }}</a>
            <a class="dropdown-item mk-menu__item" data-k="view" target="_blank" rel="noopener" role="menuitem"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>{{ __('View on the site') }}</a>
            <button class="dropdown-item mk-menu__item" type="button" data-k="copy" role="menuitem"><i class="fa-regular fa-copy" aria-hidden="true"></i><span>{{ __('Copy link') }}</span></button>
            <form method="POST" data-k="pin">@csrf<button class="dropdown-item mk-menu__item" type="submit" role="menuitem"><i class="fa-solid fa-thumbtack" aria-hidden="true"></i><span>{{ __('Pin to my channel') }}</span></button></form>
            <form method="POST" data-k="unpin">@csrf<button class="dropdown-item mk-menu__item" type="submit" role="menuitem"><i class="fa-solid fa-link-slash" aria-hidden="true"></i><span>{{ __('Unpin from my channel') }}</span></button></form>
            <hr>
            <button class="dropdown-item mk-menu__item mk-menu__item--danger" type="button" data-k="delete" data-bs-toggle="modal" data-bs-target="#mk-delete-video" role="menuitem"><i class="fa-regular fa-trash-can" aria-hidden="true"></i>{{ __('Delete video…') }}</button>
        </template>
    @endif

    {{-- Delete one video (6.6): a dialog in the look's "Pop-up window style"; what goes with it, and the gentle choice. --}}
    {{-- Its row comes from the control that opened it (the row's own Delete button or its menu's item: data-mk-delete), read
         when it opens (the page root's show listener); no listener on every click of the page (1.2.3 round 1, R1-026). --}}
    <div class="modal fade" id="mk-delete-video" tabindex="-1" aria-labelledby="mk-delete-video-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
            <div class="modal-content avd-mk">
                <div class="mk-sheet__head">
                    <h2 class="mk-sheet__title" id="mk-delete-video-title">{{ __('Delete this video?') }}</h2>
                    <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
                <div class="mk-sheet__body">
                    <div class="mk-summary">
                        <span class="mk-thumb">
                            <img x-show="current.thumb" :src="current.thumb || null" alt="" width="160" height="90">
                            <span class="mk-thumb__empty" x-show="!current.thumb"><i class="fa-regular fa-image" aria-hidden="true"></i></span>
                            <span class="mk-thumb__dur" x-show="current.dur" x-text="current.dur"></span>
                        </span>
                        <div style="display:grid;gap:.2rem;min-width:0">
                            <strong class="mk-clamp-2" dir="auto" x-text="current.title"></strong>
                            <span class="mk-muted" style="font-size:.8125rem" x-text="current.facts"></span>
                            <span><span class="mk-status" :class="'mk-status--' + (current.kind || 'private')" x-text="current.label"></span></span>
                        </div>
                    </div>
                    <div style="display:grid;gap:.6rem">
                        <strong>{{ __('This also deletes') }}</strong>
                        <ul class="mk-facts">
                            <li><i class="fa-regular fa-comment" aria-hidden="true"></i><span x-text="current.comments"></span></li>
                            <li><i class="fa-regular fa-thumbs-up" aria-hidden="true"></i><span x-text="current.ratings"></span></li>
                            <li x-show="current.playlists"><i class="fa-solid fa-list" aria-hidden="true"></i><span x-text="current.playlists"></span></li>
                            <li><i class="fa-regular fa-file-video" aria-hidden="true"></i><span>{{ __('The video file and its thumbnails') }}</span></li>
                        </ul>
                    </div>
                    <x-mk.alert type="danger">{{ __('You can\'t undo this. To hide the video instead, make it private.') }}</x-mk.alert>
                </div>
                <div class="mk-sheet__foot">
                    <button type="submit" class="mk-btn mk-btn--quiet" form="mk-private-one" x-show="current.changeable && !current.private"><i class="fa-solid fa-eye-slash" aria-hidden="true"></i><span>{{ __('Make private instead') }}</span></button>
                    <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal" data-mk-autofocus>{{ __('Cancel') }}</button>
                    <button type="submit" class="mk-btn mk-btn--danger" form="mk-delete-one"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ __('Delete video') }}</span></button>
                </div>
                <form id="mk-delete-one" method="POST" :action="current.destroy" hidden>@csrf @method('DELETE')</form>
                <form id="mk-private-one" method="POST" action="{{ route('user.videos.mass-visibility') }}" hidden>@csrf<input type="hidden" name="ids[]" :value="current.id"><input type="hidden" name="visibility" value="private"></form>
            </div>
        </div>
    </div>

    @if($tabs['all'] > 0)
        {{-- Delete the ticked videos (6.6): their titles, the totals, and the gentle choice. --}}
        <div class="modal fade" id="mk-delete-videos" tabindex="-1" aria-labelledby="mk-delete-videos-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
                <div class="modal-content avd-mk">
                    <div class="mk-sheet__head">
                        <h2 class="mk-sheet__title" id="mk-delete-videos-title" data-mk-bulk="title">{{ __('Delete these videos?') }}</h2>
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <div class="mk-sheet__body">
                        <ul class="mk-rows mk-sheet-list" data-mk-bulk="items"></ul>
                        <template data-mk-bulk="item"><li class="mk-item"><span class="mk-thumb"><img alt="" width="160" height="90" hidden><span class="mk-thumb__empty"><i class="fa-regular fa-image" aria-hidden="true"></i></span></span><div class="mk-item__body"><strong class="mk-clamp-2" dir="auto"></strong><span class="mk-muted" style="font-size:.8125rem"></span></div></li></template>
                        <p class="mk-muted" data-mk-bulk="more" hidden></p>
                        <div style="display:grid;gap:.6rem">
                            <strong>{{ __('This also deletes') }}</strong>
                            <ul class="mk-facts">
                                <li><i class="fa-regular fa-comment" aria-hidden="true"></i><span>{{ __('Comments and their replies:') }}{{ \App\Support\LabelGap::member() }}<strong data-mk-bulk="c"></strong></span></li>
                                <li><i class="fa-regular fa-thumbs-up" aria-hidden="true"></i><span>{{ __('Likes:') }}{{ \App\Support\LabelGap::member() }}<strong data-mk-bulk="l"></strong> &nbsp;· {{ __('Dislikes:') }}{{ \App\Support\LabelGap::member() }}<strong data-mk-bulk="d"></strong></span></li>
                                <li><i class="fa-solid fa-list" aria-hidden="true"></i><span>{{ __('Places in playlists:') }}{{ \App\Support\LabelGap::member() }}<strong data-mk-bulk="p"></strong></span></li>
                                <li><i class="fa-regular fa-file-video" aria-hidden="true"></i><span>{{ __('The video files and their thumbnails') }}</span></li>
                            </ul>
                        </div>
                        <x-mk.alert type="danger">{{ __('You can\'t undo this. To hide them instead, make them private.') }}</x-mk.alert>
                    </div>
                    <div class="mk-sheet__foot">
                        <button type="submit" class="mk-btn mk-btn--quiet" form="mk-visibility-form" name="visibility" value="private" data-mk-bulk="private-btn" hidden><i class="fa-solid fa-eye-slash" aria-hidden="true"></i><span data-mk-bulk="private"></span></button>
                        <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal" data-mk-autofocus>{{ __('Cancel') }}</button>
                        <button type="submit" class="mk-btn mk-btn--danger" form="mk-visibility-form" formaction="{{ route('user.videos.mass-delete') }}" name="_method" value="DELETE"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span data-mk-bulk="remove"></span></button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Visibility of the ticked videos on phones: a bottom sheet (menus never open off-screen). --}}
        <div class="offcanvas offcanvas-end mk-drawer avd-mk" tabindex="-1" id="mk-visibility-sheet" aria-labelledby="mk-visibility-sheet-title">
            <span class="mk-sheet__grip" aria-hidden="true"></span>
            <div class="mk-sheet__head">
                <h2 class="mk-sheet__title" id="mk-visibility-sheet-title" data-mk-bulk="title">{{ __('Change visibility') }}</h2>
                <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="offcanvas" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="mk-sheet__body">
                <div class="mk-choices" role="radiogroup" aria-label="{{ __('Visibility') }}">
                    @foreach(['public' => ['earth-americas', __('Public'), __('Everyone can find and watch them.')], 'unlisted' => ['link', __('Unlisted'), __('Only people with the link.')], 'private' => ['lock', __('Private'), __('Only you.')]] as $mkVis => [$mkIcon, $mkLabel, $mkDesc])
                        <label class="mk-choice"><input class="mk-radio" type="radio" name="mk-bulk-visibility" value="{{ $mkVis }}" x-model="visibility"><span class="mk-choice__body"><span class="mk-choice__title"><i class="fa-solid fa-{{ $mkIcon }}" aria-hidden="true"></i>{{ $mkLabel }}</span><span class="mk-choice__desc">{{ $mkDesc }}</span></span></label>
                    @endforeach
                </div>
                <p class="mk-help">{{ __('Drafts and failed uploads keep their state.') }}</p>
            </div>
            <div class="mk-sheet__foot">
                <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="offcanvas">{{ __('Cancel') }}</button>
                <button type="submit" class="mk-btn mk-btn--primary" form="mk-visibility-form" name="visibility" :value="visibility" :disabled="bulk.changeable === 0" x-text="bulk.changeable ? count(visibility, bulk.changeable) : ''"></button>
            </div>
        </div>
    @endif

    @stack('mk-overlays')
</div>
@endsection

@push('scripts')
    @include('users.partials.studio-scripts', ['studioScriptsParts' => ['lists']])
@endpush
