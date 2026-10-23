{{--
    Content > All videos > one video, in the redesigned admin (the old admin keeps videos/edit.blade.php): the details
    form (saved with the save bar, Ctrl+S or the header's Save), the poster and facts beside it, the latest comments,
    and the risky actions (ban, delete) in the header's More menu with their confirmation.
--}}
@use('App\Enums\VideoStatus')
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', $video->title)

@section('content')
    @php
        $statusLabels = [
            VideoStatus::PUBLIC->value => __('Public'),
            VideoStatus::UNLISTED->value => __('Unlisted'),
            VideoStatus::PRIVATE->value => __('Private'),
            VideoStatus::PLANNED->value => __('Scheduled'),
            VideoStatus::DRAFT->value => __('Draft'),
            VideoStatus::BANNED->value => __('Banned'),
            VideoStatus::FAILED->value => __('Failed'),
        ];
        $languages = ['en', 'ar', 'fr', 'zh', 'nl', 'de', 'hi', 'it', 'ko', 'es', 'pt', 'ru'];
        $language = (string) old('language', $video->language ?? '');
        $date = fn ($value) => $value?->translatedFormat('j M Y, H:i');
        $facts = array_filter([
            __('Views') => number_format((int) $video->views),
            __('Likes') => number_format((int) $video->likes_count),
            __('Comments') => number_format((int) $video->comments_count),
            __('Duration') => $video->duration ?: null,
            __('File size') => $video->size ? round($video->size / 1048576, 1).' MB' : null,
            __('Added') => $date($video->created_at),
            __('Published') => $date($video->published_at),
            __('Scheduled') => $video->scheduled_at ? $date($video->scheduled_at) : null,
            __('Banned') => $video->banned_at ? $date($video->banned_at) : null,
            'ID' => '#'.$video->id,
        ], fn ($value) => $value !== null && $value !== '');
    @endphp

    <x-admin.page-header page="content.videos" :title="$video->title"
        :description="__('By :name', ['name' => $video->user?->username ?? __('Deleted member')]).' · '.($statusLabels[$video->status->value] ?? '')"
        :crumbs="[['label' => __('Content'), 'url' => route('admin.videos.index')], ['label' => __('All videos'), 'url' => route('admin.videos.index')], ['label' => \Illuminate\Support\Str::limit($video->title, 40), 'url' => null]]"
        :tabs="[]">
        <x-slot:actions>
            @if ($video->is_public || $video->is_unlisted)
                <a class="ad-btn" href="{{ $video->route }}" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('Open on site') }}</a>
            @endif
            <x-admin.menu id="ad-video-more" :label="__('More actions')">
                <a href="{{ route('user.videos.show', $video) }}">{{ AdminTheme::icon('chart') }}{{ __('Statistics') }}</a>
                <a href="{{ route('admin.comments.index', ['video' => $video->id]) }}">{{ AdminTheme::icon('chat') }}{{ __('All comments') }}</a>
                <a href="{{ route('admin.thumbnails.index') }}">{{ AdminTheme::icon('image') }}{{ __('All thumbnails') }}</a>
                <hr>
                @if ($video->is_banned)
                    <form method="POST" action="{{ route('admin.videos.unban', $video) }}">
                        @csrf
                        <button type="submit">{{ AdminTheme::icon('check-circle') }}{{ __('Unban') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.videos.ban', $video) }}"
                          data-ad-confirm="{{ __('Ban this video?') }}" data-ad-confirm-body="{{ __('Visitors can no longer watch it. It stays in this list, where you can unban it.') }}" data-ad-confirm-label="{{ __('Ban video') }}">
                        @csrf
                        <button type="submit" class="is-danger">{{ AdminTheme::icon('ban') }}{{ __('Ban video') }}</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('admin.videos.delete', $video) }}"
                      data-ad-confirm="{{ __('Delete this video for good?') }}" data-ad-confirm-body="{{ __('Its file, comments and views are deleted too. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete video') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete video...') }}</button>
                </form>
            </x-admin.menu>
            <button type="submit" class="ad-btn is-primary" form="ad-video-form">{{ __('Save changes') }}</button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="ad-record">
        <form id="ad-video-form" method="POST" action="{{ route('admin.videos.update', $video) }}" data-ad-form class="ad-record-main">
            @csrf
            @method('PUT')

            <section class="ad-panel" aria-labelledby="ad-video-details">
                <div class="ad-panel-h"><h2 id="ad-video-details">{{ __('Details') }}</h2></div>
                <div class="ad-panel-b ad-record-fields">
                    <x-admin.field name="title" :label="__('Title')" :value="$video->title" />
                    <x-admin.field name="description" :label="__('Description')" type="textarea" :value="$video->description" />
                </div>
            </section>

            <section class="ad-panel" aria-labelledby="ad-video-where">
                <div class="ad-panel-h"><h2 id="ad-video-where">{{ __('Who can watch it') }}</h2></div>
                <div class="ad-panel-b ad-record-fields is-3">
                    <x-admin.field name="status" :label="__('Visibility')">
                        <select class="ad-select" id="status" name="status">
                            @foreach ($statusLabels as $value => $label)
                                <option value="{{ $value }}" @selected((string) old('status', $video->status->value) === (string) $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field name="category_id" :label="__('Category')">
                        <select class="ad-select" id="category_id" name="category_id">
                            <option value="">{{ __('No category') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((string) old('category_id', $video->category_id) === (string) $category->id)>{{ $category->title }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field name="language" :label="__('Language')">
                        <select class="ad-select" id="language" name="language">
                            @if ($language === '')
                                <option value="" selected>{{ __('Not set') }}</option>
                            @endif
                            @foreach (array_filter(array_unique(array_merge($languages, [$language]))) as $code)
                                <option value="{{ $code }}" @selected($language === $code)>{{ strtoupper($code) }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                </div>
            </section>

            <section class="ad-panel" aria-labelledby="ad-video-options">
                <div class="ad-panel-h"><h2 id="ad-video-options">{{ __('On the video page') }}</h2></div>
                {{-- The update reads a switch as on when it is sent, so these rows send nothing when off (no hidden "0"
                     like <x-admin.toggle>); same markup and classes as that component. --}}
                @foreach ([
                    'show_ad' => [__('Show ads'), __('The ads you set up in Money > Ads play with this video.')],
                    'allow_comments' => [__('Allow comments'), null],
                    'show_likes' => [__('Show likes'), null],
                    'is_short' => [__('Short video'), __('Listed with the shorts: vertical, under a minute.')],
                ] as $name => [$label, $help])
                    <label class="ad-toggle-row" for="{{ $name }}" data-ad-field="{{ $name }}">
                        <span class="ad-toggle-text">
                            <b>{{ $label }}</b>
                            @if ($help)
                                <span class="ad-help" id="{{ $name }}-help">{{ $help }}</span>
                            @endif
                        </span>
                        <span class="ad-switch">
                            <input type="checkbox" role="switch" id="{{ $name }}" name="{{ $name }}" value="1" @checked(session()->hasOldInput() ? old($name) === '1' : (bool) $video->{$name}) @if ($help) aria-describedby="{{ $name }}-help" @endif>
                            <i aria-hidden="true"></i>
                        </span>
                    </label>
                @endforeach
            </section>

            <x-admin.savebar form="ad-video-form" />
        </form>

        <aside class="ad-record-side" aria-label="{{ __('About this video') }}">
            <section class="ad-panel">
                <span class="ad-record-poster">
                    <img src="{{ $video->thumbnail_url }}" alt="" width="320" height="180" decoding="async">
                    @if ($video->duration !== '')
                        <span class="ad-dur">{{ $video->duration }}</span>
                    @endif
                </span>
                <dl class="ad-facts">
                    @foreach ($facts as $label => $value)
                        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>

            <section class="ad-panel" aria-labelledby="ad-video-comments">
                <div class="ad-panel-h">
                    <h2 id="ad-video-comments" class="ad-home-grow">{{ __('Latest comments') }}</h2>
                    @if ((int) $video->comments_count > 0)
                        <a class="ad-small" href="{{ route('admin.comments.index', ['video' => $video->id]) }}">{{ __('All comments') }}</a>
                    @endif
                </div>
                @if ($video->comments->isEmpty())
                    <p class="ad-panel-b ad-muted">{{ __('No comments yet.') }}</p>
                @else
                    <ul class="ad-list">
                        @foreach ($video->comments as $comment)
                            <li>
                                <span class="ad-li-text">
                                    <b>{{ $comment->user?->username ?? __('Deleted member') }}</b>
                                    <span class="ad-home-quote">{{ \Illuminate\Support\Str::limit((string) $comment->content, 100) }}</span>
                                    <span>{{ $comment->created_at?->diffForHumans() }}@if ($comment->banned_at) · {{ __('Hidden') }}@endif</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </aside>
    </div>
@endsection
