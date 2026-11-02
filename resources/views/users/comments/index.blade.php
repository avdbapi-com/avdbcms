@extends('layouts.user')

@section('title', __('Comments'))

@section('content')
@php
    use App\Support\Studio\StudioFormat;

    $rows = $comments->getCollection();
    $count = $rows->count();
    // What the row menus need that the row does not print (lean rows, 4.3): the address templates, each video's address
    // once ("s"), and per row the items it has (1 = the template, [i] = the shared string i). The remove dialog names
    // the comment by the row's own text ([data-mk-name]).
    $menuData = ['u' => ['delete' => \App\Support\Studio\StudioHtml::pathTemplate('user.comments.destroy', 'comment')], 's' => [], 'r' => []];
    $videoIndex = [];
    foreach ($rows as $comment) {
        $row = ['delete' => 1];
        if ($comment->video) {
            $videoIndex[$comment->video->id] ??= array_push($menuData['s'], \App\Support\Studio\StudioHtml::relative($comment->video->route)) - 1;
            $row = ['view' => [$videoIndex[$comment->video->id]], 'copy' => 1] + $row;
        }
        $menuData['r'][$comment->id] = $row;
    }
    $labels = [
        'selected' => \App\Support\Studio\StudioHtml::countWords(fn ($n, $c) => trans_choice(':count comment selected|:count comments selected', $n, ['count' => $c]), $count),
        'title' => \App\Support\Studio\StudioHtml::countWords(fn ($n, $c) => trans_choice('Remove :count comment?|Remove :count comments?', $n, ['count' => $c]), $count),
        'delete' => \App\Support\Studio\StudioHtml::countWords(fn ($n, $c) => trans_choice('Remove :count comment|Remove :count comments', $n, ['count' => $c]), $count),
    ];
    $value = fn (string $key) => is_string(request()->query($key)) ? request()->query($key) : '';
    $videoOptions = ['' => __('All videos')] + $videos->mapWithKeys(fn ($video) => [$video->id => \Illuminate\Support\Str::limit((string) ($video->getRawOriginal('title') ?: __('Untitled video')), 60)])->all();
    $dateOptions = ['' => __('Any date'), '7' => __('Last 7 days'), '28' => __('Last 28 days'), '90' => __('Last 90 days')];
@endphp
{{-- The page's Alpine component (mkComments: the ticked comments and their counted words) is registered by
     resources/js/member/studio-pages.js, one cached file (1.2.3 round 1b, R1R-01). --}}
<div class="avd-mk mk-page"
     x-data="mkComments"
     x-effect="document.documentElement.dataset.mkBar = selected.length ? 'bulk' : ''">
    <x-mk.head icon="comments" :kicker="__('Studio')" :title="__('Comments on your videos')" :desc="__('Remove anything that breaks your rules. Replies go with their comment.')" />
    <x-mk.flash refusal />
    @unless($commentsEnabled)
        <x-mk.alert type="info">{{ __('Comments are switched off on this site: nobody can write new ones.') }}</x-mk.alert>
    @endunless

    @if($comments->total() === 0 && ! $filtersActive)
        <section class="mk-panel">
            <x-mk.empty icon="comments" :title="__('No comments yet')" :text="__('Comments people write under your videos appear here, newest first.')" />
        </section>
    @else
        <section class="mk-panel" aria-label="{{ __('Comments') }}" data-selection="0" :data-selection="selected.length ? 1 : 0">
            {{-- 1.2.3 round 1 (R1-026): the toolbar and the bulk bar share one cell (.mk-bars, member/_kit.scss): a tick swaps them without moving the list --}}
            <div class="mk-bars">
                @include('users.partials.filters', [
                    'id' => 'comments',
                    'action' => route('user.comments.index'),
                    'hidden' => [],
                    'search' => ['name' => 'search', 'value' => $value('search'), 'label' => __('Search comments'), 'placeholder' => __('Search comments or names')],
                    'selects' => [
                        ['name' => 'video', 'label' => __('Video'), 'options' => $videoOptions, 'value' => $value('video'), 'fold' => true],
                        ['name' => 'date', 'label' => __('Date'), 'options' => $dateOptions, 'value' => $value('date'), 'fold' => false],
                    ],
                    'custom' => null,
                    'clearUrl' => route('user.comments.index'),
                    'active' => $filtersActive,
                    'submitLabel' => __('Show comments'),
                ])
                <div class="mk-bulkbar" role="region" aria-label="{{ __('Selected comments') }}">
                    <label class="mk-item__check mk-bulkbar__all"><input class="mk-check" type="checkbox" aria-label="{{ __('Select all on this page') }}" :checked="selected.length > 0 && selected.length === boxes().length" x-effect="$el.indeterminate = selected.length > 0 && selected.length < boxes().length" @change="all($event.target.checked)"></label>
                    <span class="mk-bulkbar__count" x-text="word('selected')" aria-live="polite"></span>
                    <button class="mk-btn mk-btn--danger-quiet mk-btn--sm" type="button" data-bs-toggle="modal" data-bs-target="#mk-remove-comments"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ __('Remove') }}</span></button>
                    <button class="mk-icon-btn mk-icon-btn--muted mk-bulkbar__close" type="button" aria-label="{{ __('Clear selection') }}" title="{{ __('Clear selection') }}" @click="all(false)"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
            </div>
            @if($count === 0)
                <x-mk.empty icon="filter" :title="__('No comments match these filters')" :text="__('Try other words or another filter.')" compact>
                    <a class="mk-btn mk-btn--secondary" href="{{ route('user.comments.index') }}">{{ __('Clear filters') }}</a>
                </x-mk.empty>
            @else
                <ul class="mk-rows" data-mk-menu-tpl="mk-comment-menu" data-mk-menu-data="mk-comment-rows" @change="toggle($event)">
                    @foreach($rows as $comment)
                        @php
                            $content = (string) $comment->content;
                            $long = mb_strlen($content) > $previewLength;
                            $video = $comment->video;
                            $videoTitle = $video ? (string) ($video->getRawOriginal('title') ?: __('Untitled video')) : __('A deleted video');
                            $poster = $video && ($video->thumbnail || ! empty($video->poster_url)) ? $video->thumbnail_url : null;
                            $author = $comment->user?->username ?? __('Deleted member');
                            $replies = (int) $comment->replies_count;
                        @endphp
                        <li class="mk-item mk-item--comment">
                            <label class="mk-item__check"><input class="mk-check" type="checkbox" value="{{ $comment->id }}" data-row aria-label="{{ __('Select the comment by :name', ['name' => $author]) }}"></label>
                            @if($comment->user)
                                <img class="mk-avatar mk-avatar--36" src="{{ \App\Support\Studio\StudioHtml::relative($comment->user->avatar_url) }}" alt="" width="36" height="36" loading="lazy" decoding="async">
                            @else
                                <span class="mk-avatar mk-avatar--36" aria-hidden="true"></span>
                            @endif
                            <div class="mk-item__body">
                                <div><strong><bdi>{{ $author }}</bdi></strong>&nbsp;<span class="mk-muted" style="font-size:.8125rem">· {{ StudioFormat::ago($comment->created_at) }}</span></div>
                                {{-- R1-025: a comment keeps its own direction in every language; names and titles are isolated --}}
                                <p class="mk-clamp-3" data-mk-name data-mk-clamp dir="auto">{{ $content }}</p>
                                @if($long)
                                    <button type="button" class="mk-btn mk-btn--quiet mk-btn--sm mk-readmore" aria-expanded="false" data-mk-more><span>{{ __('Read more') }}</span></button>
                                @endif
                                @if($video)
                                    <a class="mk-item__ref" href="{{ \App\Support\Studio\StudioHtml::path('user.comments.index', ['video' => $video->id]) }}"><span class="mk-item__media mk-item__media--xs"><span class="mk-thumb">@if($poster)<img src="{{ $poster }}" alt="" width="64" height="36" loading="lazy" decoding="async">@endif</span></span><span class="mk-ref-line">{!! strtr(e(__('on :title')), [':title' => '<bdi class="mk-ref-line__fit" dir="auto">'.e($videoTitle).'</bdi>']) !!}</span></a>
                                @endif
                                @if($replies)
                                    <div class="mk-item__tools">
                                        <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ \App\Support\Studio\StudioHtml::path('user.comments.replies', $comment) }}" data-mk-replies><i class="fa-solid fa-comments" aria-hidden="true"></i><span>{{ trans_choice(':count reply|:count replies', $replies, ['count' => StudioFormat::number($replies)]) }}</span></a>
                                    </div>
                                @endif
                            </div>
                            <div class="mk-item__aside">
                                <button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--danger" title="{{ __('Remove comment') }}" aria-label="{{ __('Remove comment') }}" data-bs-toggle="modal" data-bs-target="#mk-remove-comment" data-mk-delete="{{ $comment->id }}"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
                                <div class="mk-row__menu">
                                    <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="menu" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}" data-mk-menu="{{ $comment->id }}"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if($comments->hasPages())
                {{ $comments->onEachSide(1)->links('vendor.pagination.mk') }}
            @elseif($count > 0)
                <div class="mk-panel__foot"><span class="mk-muted" style="font-size:.8125rem">{{ $days
                    ? trans_choice(':count comment in the last :days days|:count comments in the last :days days', $comments->total(), ['count' => StudioFormat::number($comments->total()), 'days' => StudioFormat::number($days)])
                    : trans_choice(':count comment|:count comments', $comments->total(), ['count' => StudioFormat::number($comments->total())]) }}</span></div>
            @endif
        </section>

        <form id="mk-comments-remove-form" method="POST" action="{{ route('user.comments.mass-delete') }}" hidden @submit="avdMkIds($el, selected)">
            @csrf
            @method('DELETE')
        </form>
        <script type="application/json" id="mk-comment-rows">{!! json_encode($menuData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <script type="application/json" id="mk-comment-labels">{!! json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <template id="mk-comment-menu">
            <a class="dropdown-item mk-menu__item" data-k="view" target="_blank" rel="noopener" role="menuitem"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>{{ __('Open the video') }}</a>
            <button class="dropdown-item mk-menu__item" type="button" data-k="copy" role="menuitem"><i class="fa-regular fa-copy" aria-hidden="true"></i><span>{{ __('Copy link') }}</span></button>
            <hr>
            <button class="dropdown-item mk-menu__item mk-menu__item--danger" type="button" data-k="delete" data-bs-toggle="modal" data-bs-target="#mk-remove-comment" role="menuitem"><i class="fa-regular fa-trash-can" aria-hidden="true"></i>{{ __('Remove comment…') }}</button>
        </template>

        <div class="modal fade" id="mk-remove-comment" tabindex="-1" aria-labelledby="mk-remove-comment-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
                <div class="modal-content avd-mk">
                    <div class="mk-sheet__head">
                        <h2 class="mk-sheet__title" id="mk-remove-comment-title">{{ __('Remove this comment?') }}</h2>
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <div class="mk-sheet__body"><p class="mk-sheet__lead mk-clamp-3" data-mk-title dir="auto"></p><p class="mk-muted">{{ __('The comment and its replies are removed from your video. You can\'t undo this.') }}</p></div>
                    <div class="mk-sheet__foot">
                        <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal" data-mk-autofocus>{{ __('Cancel') }}</button>
                        <button type="submit" class="mk-btn mk-btn--danger" form="mk-remove-comment-form"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ __('Remove comment') }}</span></button>
                    </div>
                    <form id="mk-remove-comment-form" method="POST" action="" data-mk-action-from hidden>@csrf @method('DELETE')</form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="mk-remove-comments" tabindex="-1" aria-labelledby="mk-remove-comments-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
                <div class="modal-content avd-mk">
                    <div class="mk-sheet__head">
                        <h2 class="mk-sheet__title" id="mk-remove-comments-title" x-text="word('title')">{{ __('Remove these comments?') }}</h2>
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <div class="mk-sheet__body"><p class="mk-sheet__lead">{{ __('The comments and their replies are removed from your videos. You can\'t undo this.') }}</p></div>
                    <div class="mk-sheet__foot">
                        <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal" data-mk-autofocus>{{ __('Cancel') }}</button>
                        <button type="submit" class="mk-btn mk-btn--danger" form="mk-comments-remove-form"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span x-text="word('delete')"></span></button>
                    </div>
                </div>
            </div>
        </div>

        {{-- The replies of a comment (6.8, studio-21): a side panel, read and remove (its script: studio-pages.js, with the
             words of a failed load in data-failed). --}}
        <div class="offcanvas offcanvas-end mk-drawer avd-mk" tabindex="-1" id="mk-replies" aria-labelledby="mk-replies-title" data-failed="{{ __('The replies could not be loaded. Try again.') }}">
            <span class="mk-sheet__grip" aria-hidden="true"></span>
            <div class="mk-sheet__head">
                <h2 class="mk-sheet__title" id="mk-replies-title">{{ __('Replies') }}</h2>
                <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="offcanvas" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="mk-replies" id="mk-replies-body" aria-live="polite" aria-busy="true"></div>
            <template id="mk-replies-loading">
                <div class="mk-sheet__body">
                    @for($i = 0; $i < 3; $i++)
                        <div class="mk-item" aria-hidden="true"><span class="mk-skel" style="inline-size:32px;block-size:32px;border-radius:50%"></span><div class="mk-item__body"><span class="mk-skel mk-skel--title" style="inline-size:45%"></span><span class="mk-skel mk-skel--line" style="inline-size:80%"></span></div></div>
                    @endfor
                </div>
            </template>
        </div>
        @include('users.comments.partials.remove-reply')
    @endif
    @stack('mk-overlays')
</div>
@endsection

@push('scripts')
    @include('users.partials.studio-scripts')
    {{-- the replies sheet's script (it opens the sheet and loads the replies inside it) is in
         resources/js/member/studio-pages.js (1.2.3 round 1b, R1R-01) --}}
@endpush
