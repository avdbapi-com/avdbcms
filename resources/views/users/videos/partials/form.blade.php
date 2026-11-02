{{-- Video details and the draft page (member design 6.4): one layout, one column in reading order on phones
     and tablets (preview, details, visibility, thumbnail, playlists, more settings, delete) and a main column + a 340px
     side column from 980px of form width. The draft page keeps the Upload18 hooks and texts (data-video-draft-form,
     data-video-processing-box, data-upload18-pending; "Processing on Upload18") and its completion poll (the extracted
     partial, included unchanged by create.blade.php).
     Variables: $video, $draft (bool), $categories, $categoriesNative, $languages, $playlists, $previewMode,
     $enhancedSelects, $customThumbnails, $commentsEnabled, $adsEnabled. --}}
@php
    use App\Enums\VideoStatus;
    use App\Enums\ThumbnailStatus;
    use App\Support\Studio\StudioFormat;
    use App\Support\Studio\ZoneLabel;

    $zone = ZoneLabel::zone();
    $nowLocal = \Carbon\CarbonImmutable::now($zone);
    $titleMax = (int) config('validation.video.title.max', 100);
    $descriptionMax = (int) config('validation.video.description.max', 5000);
    $rawTitle = (string) $video->getRawOriginal('title');
    $rawDescription = (string) $video->getRawOriginal('description');
    // the video's visibility as the cards show it: a scheduled video whose time has come is public (studio-8: the choice
    // is bound to the radio's VALUE, never to an option index)
    $storedStatus = $video->status instanceof VideoStatus ? $video->status : VideoStatus::tryFrom((int) $video->status);
    $currentStatus = match (true) {
        $storedStatus === VideoStatus::PLANNED && $video->scheduled_at !== null && ! $video->scheduled_at->isFuture() => VideoStatus::PUBLIC,
        default => $storedStatus ?? VideoStatus::PUBLIC,
    };
    $statusValue = (string) old('status', $draft
        ? (string) config('site.default_video_status', VideoStatus::PUBLIC->value)
        : (string) $currentStatus->value);
    if (! in_array((int) $statusValue, VideoStatus::validStatus(), true)) {
        $statusValue = (string) VideoStatus::PUBLIC->value;
    }
    $scheduled = $video->scheduled_at ? \Carbon\CarbonImmutable::instance($video->scheduled_at)->setTimezone($zone) : null;
    $defaultSchedule = ($scheduled && $scheduled->isFuture()) ? $scheduled : $nowLocal->addDay()->setTime(20, 0);
    $scheduledDate = (string) old('scheduled_date', $defaultSchedule->toDateString());
    $scheduledTime = (string) old('scheduled_time', $defaultSchedule->format('H:i'));
    try {
        $scheduleShown = \Carbon\CarbonImmutable::createFromFormat('Y-m-d H:i', $scheduledDate.' '.$scheduledTime, $zone) ?: $defaultSchedule;
    } catch (\Throwable) {
        $scheduleShown = $defaultSchedule;
    }
    $isProcessing = \App\Support\Upload18\UploadClaims::isProcessing($video);
    $uploading = (bool) $video->is_uploading;
    $readyThumbnails = $video->thumbnails->whereIn('status', [ThumbnailStatus::GENERATED, ThumbnailStatus::UPLOADED])->count();
    $shouldAutoGenerateThumbnails = $draft && $video->is_draft && $video->embed_code && $readyThumbnails === 0;
    $thumbnailTarget = max(1, min(5, (int) config('site.upload18_thumbnail_count', 3)));
    $initialThumbnails = $video->thumbnails
        ->sortBy('id')
        ->map(fn ($thumbnail) => [
            'id' => $thumbnail->id,
            'url' => route('video.thumbnails', ['video' => $video->uuid, 'thumbnail' => $thumbnail->id]),
            'status' => $thumbnail->status instanceof \BackedEnum ? $thumbnail->status->value : (int) $thumbnail->status,
            'is_active' => (bool) $thumbnail->is_active,
        ])
        ->values()
        ->all();
    $thumbs = collect($initialThumbnails);
    $uploadedThumb = $thumbs->firstWhere('status', ThumbnailStatus::UPLOADED->value);
    $otherThumbs = $thumbs->filter(fn ($t) => $t['status'] !== ThumbnailStatus::UPLOADED->value)->values();
    $activeThumb = $thumbs->first(fn ($t) => $t['is_active'] && in_array($t['status'], [ThumbnailStatus::GENERATED->value, ThumbnailStatus::UPLOADED->value], true));
    $selectedThumb = old('thumbnail', $activeThumb['id'] ?? null);
    // An imported video (AVDB, XVID, YouTube) often has only its poster address: that picture is shown first as
    // "Current picture" (checked while nothing else is chosen; its empty value keeps it), so the picker never looks
    // empty for a video viewers do see with a picture.
    $currentPoster = $activeThumb === null && ! empty($video->poster_url) ? (string) $video->poster_url : null;
    $posterUrl = ($video->thumbnail || ! empty($video->poster_url)) ? $video->thumbnail_url : null;
    $selectedPlaylists = old() ? array_map('intval', (array) old('playlists', [])) : $playlists->filter(fn ($p) => (bool) $p->has_video)->pluck('id')->map(fn ($id) => (int) $id)->all();
    $errorCount = $errors->count();
    $fieldError = fn (string $key) => $errors->first($key);
    // A save the server refused comes back with what the member typed (old input): it is still unsaved, so the save bar
    // and the leave warning start on (principle 7). A view-only refusal brings the stored values back: clean (6.18).
    // The draft page shows its bar (Publish, Save draft, Finish later) from the start through its own condition (the
    // component's "draft"), never by starting dirty: a draft nobody changed has no leave warning, so the Upload18 completion
    // poll reloads it quietly when the processing ends, and the studio menu leaves it without "Leave site?".
    // The warning is armed by the member's first real input or change, as on Video details.
    $startDirty = session()->hasOldInput() && ! $errors->has('view_only_demo');
    // The counters start where the browser's own count goes on (a field's value.length, which maxlength uses too): a
    // line break is one character (the stored text may carry the CRLF a browser posts: descriptions saved before line
    // breaks were normalised) and an emoji outside the basic plane is two (UTF-16 units), so nothing jumps on the first key.
    // a member's own text field takes the direction of its text (dir="auto", R3-053) once it has one; empty, it keeps the
    // page's (an empty dir="auto" field is laid out left to right: its caret and hint on the wrong side in Arabic). The
    // studio script follows what is typed (data-mk-dir).
    $autoDir = fn ($text): string => trim((string) $text) !== '' ? ' dir="auto"' : '';
    $countText = fn (?string $text) => intdiv(strlen((string) mb_convert_encoding(str_replace(["\r\n", "\r"], "\n", (string) $text), 'UTF-16LE', 'UTF-8')), 2);
    // the size in the member's language ("1,09 Mo", "1,09 MB", "1.09 ميغابايت": R3-045)
    $facts = array_filter([
        $video->original_file_name ?: null,
        $video->size ? \App\Support\NumberFormat::localFileSize($video->size) : null,
        $video->duration !== '' ? $video->duration : null,
    ]);
    $createdVideo = ! $draft && $video->is_created;
    $previewStates = $draft || $uploading;
    // 1.2.3 round 2 (R2A-009): "Published <date>" only for a video that has gone public. A scheduled video keeps the
    // scheduled time in published_at until then (UpdateVideoAction): its head says when it goes public, as the
    // Visibility panel and its My videos row do. Round 3: the goes-public part is isolated (<bdi>: in Arabic its Latin
    // "GMT+1" took the views count into its run, R3-043; the count and its word are joined by no-break spaces, so a
    // narrow line never ends with the count and starts the next one with its word), and a video that never went public
    // says when it was uploaded, as its Analytics head does (R3-044).
    $headViews = e(str_replace(' ', "\u{00A0}", trans_choice(':count view|:count views', (int) $video->views, ['count' => StudioFormat::number((int) $video->views)])));
    $headDesc = match (true) {
        (bool) $draft => __('Uploaded :date. Add a title, then publish.', ['date' => StudioFormat::date($video->created_at)]),
        $storedStatus === VideoStatus::PLANNED && $scheduled !== null && $scheduled->isFuture() => '<bdi>'.e(ZoneLabel::goesPublic($scheduled)).'</bdi>&nbsp;· '.$headViews,
        $video->published_at !== null && ! $video->published_at->isFuture() => __('Published :date', ['date' => StudioFormat::date($video->published_at)]).'&nbsp;· '.$headViews,
        default => e(__('Uploaded :date', ['date' => StudioFormat::date($video->uploaded_at ?? $video->created_at)])).'&nbsp;· '.$headViews,
    };
    // The page root's starting values as a plain object (lean page content, 4.3; R3-086): what is false by default
    // (dirty, draft) is left out.
    $formStart = array_filter(['dirty' => $startDirty, 'draft' => (bool) $draft], fn ($on) => $on) + ['status' => $statusValue, 'date' => $scheduledDate,
        'time' => $scheduledTime, 'offset' => ZoneLabel::offset($scheduleShown), 'zone' => $zone->getName()];
    $formStart = '{'.collect($formStart)->map(fn ($value, $key) => $key.':'.\Illuminate\Support\Js::from($value))->implode(',').'}';
    // The schedule help (R3-046): the zone sentence, then "It is ... there now." with the language's gap between them
    // (none after "。"); the parts the script rewrites when the date or time changes are marked (data-o, data-d, data-g),
    // so the sentences are sent once (R3-086), not a second time as a template. The live region reads a changed
    // sentence whole (aria-atomic on the sentence and on the goes-public line), as when the script rewrote them whole.
    $zoneSentence = ZoneLabel::zoneLine($scheduleShown);
    $mark = fn (string $text, array $parts): string => strtr(e($text), $parts);
    $zoneHtml = $mark(__(':city time (:offset on :date).', ['city' => ZoneLabel::city()]), [':offset' => '<span data-o>'.e(ZoneLabel::offset($scheduleShown)).'</span>', ':date' => '<span data-d>'.e(StudioFormat::shortDate($scheduleShown)).'</span>']);
    $goesHtml = $mark(__('Goes public :date, :time'), [':date' => '<span data-g>'.e(StudioFormat::date($scheduleShown)).'</span>', ':time' => '<span data-t>'.e(ZoneLabel::for($scheduleShown)).'</span>']);
    // Per status: [icon, label, help]. The Visibility panel below prints the icon from a variable, which the Font
    // Awesome subset scan cannot follow, so the four are named here: fa-earth-americas, fa-link, fa-lock, fa-calendar.
    $statusChoices = [
        VideoStatus::PUBLIC->value => ['earth-americas', __('Public'), __('Everyone can find and watch it.')],
        VideoStatus::UNLISTED->value => ['link', __('Unlisted'), __('Only people with the link.')],
        VideoStatus::PRIVATE->value => ['lock', __('Private'), __('Only you.')],
        VideoStatus::PLANNED->value => ['calendar', __('Scheduled'), __('Private until the date and time you pick.')],
    ];
@endphp
{{-- The form's state and its schedule helpers are one registered Alpine component (mkVideoForm, in the studio script,
     resources/js/member/studio-pages.js; lean page content, 4.3): the root passes only its starting values. The
     component also keeps html[data-mk-bar], the leave warning, the form's listeners and the two counters (1.2.3 round 3,
     R3-086: they were attributes of every page). --}}
<div class="avd-mk mk-page mk-page--form mk-page--has-bar" x-data="mkVideoForm({!! $formStart !!})">
    <ol class="mk-crumbs">
        <li><a href="{{ route('user.videos.index') }}">{{ __('My videos') }}</a></li>
        <li><span dir="auto">{{ $rawTitle !== '' ? $rawTitle : __('Untitled video') }}</span></li>
    </ol>
    <header class="mk-head">
        <div class="mk-head__text">
            <span class="mk-head__kicker"><i class="fa-solid fa-{{ $draft ? 'file-pen' : 'pen' }}" aria-hidden="true"></i>{{ $draft ? __('Draft') : __('Video details') }}</span>
            <h1 class="mk-head__title mk-clamp-2" dir="auto">{{ $draft ? __('Finish your video') : ($rawTitle !== '' ? $rawTitle : __('Untitled video')) }}</h1>
            <p class="mk-head__desc"><span data-mk-head-status><x-mk.status :video="$video" /></span> &nbsp;·&nbsp; {!! $headDesc !!}</p>
            @if($draft && $uploading)
                {{-- 1.2.3 round 1 (R1-166): the head says "Upload failed" too when the Upload18 completion check shows its
                     failed notice (the hands-off poll partial; the script below only watches for that notice). Only a
                     draft still being processed can fail while its page is open. --}}
                <template data-mk-failed-status><x-mk.status kind="failed" :label="__('Upload failed')" /></template>
            @endif
        </div>
        @if($createdVideo)
            <div class="mk-head__actions">
                <a class="mk-btn mk-btn--secondary" href="{{ route('user.videos.show', $video) }}"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i><span>{{ __('Analytics') }}</span></a>
                @unless($video->is_banned)
                    <a class="mk-btn mk-btn--secondary mk-head__extra" href="{{ $video->route }}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span>{{ __('View on the site') }}</span></a>
                @endunless
            </div>
        @endif
    </header>
    <x-mk.flash refusal />
    @if(! $errors->has('view_only_demo') && $errorCount >= 2)
        <x-mk.alert type="danger" :title="__('Please check these fields')" tabindex="-1" data-mk-focus>
            <ul style="margin:0;padding-inline-start:1.1rem">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-mk.alert>
    @endif
    @if($draft)
        {{-- read by the Upload18 poll partial (the draft page only) --}}
        <div id="planned_value" hidden>{{ VideoStatus::PLANNED->value }}</div>
    @endif
    <form class="mk-edit" method="POST" enctype="multipart/form-data" action="{{ $draft ? route('user.videos.store', $video) : route('user.videos.update', $video) }}" @if($draft) data-video-draft-form @endif>
        @csrf
        @unless($draft)
            @method('PUT')
        @endunless
        <div class="mk-edit__grid">
            <div class="mk-edit__main">
                <section class="mk-panel" data-order="details" aria-labelledby="mk-details-title">
                    <div class="mk-panel__head"><h2 class="mk-panel__title" id="mk-details-title">{{ __('Details') }}</h2></div>
                    <div class="mk-panel__body">
                        <div class="mk-form">
                            {{-- a title or description takes the direction of its own text (R3-053: a Latin title in an Arabic page
                                 showed its end and cut its start); the counters follow the field (mkVideoForm) --}}
                            <div class="mk-field">
                                <label class="mk-label" for="title">{{ __('Title') }} <span class="mk-label__opt">{{ __('required') }}</span></label>
                                <input class="mk-input" id="title" name="title" type="text" data-mk-dir{!! $autoDir(old('title', $rawTitle)) !!} required maxlength="{{ $titleMax }}" value="{{ old('title', $rawTitle) }}" @if($fieldError('title')) aria-invalid="true" aria-describedby="title-error" @endif>
                                <div class="mk-counter"><span>{{ $countText(old('title', $rawTitle)) }}</span> / {{ StudioFormat::number($titleMax) }}</div>
                                @if($fieldError('title'))<span class="mk-error" id="title-error">{{ $fieldError('title') }}</span>@endif
                            </div>
                            <div class="mk-field">
                                <label class="mk-label" for="description">{{ __('Description') }} <span class="mk-label__opt">{{ __('optional') }}</span></label>
                                <textarea class="mk-input" id="description" name="description" rows="8" data-mk-dir{!! $autoDir(old('description', $rawDescription)) !!} maxlength="{{ $descriptionMax }}" placeholder="{{ __('Tell viewers what the video is about. Time codes like 01:30 become chapters.') }}" @if($fieldError('description')) aria-invalid="true" aria-describedby="description-error" @endif>{{ old('description', $rawDescription) }}</textarea>
                                <div class="mk-counter"><span>{{ $countText(old('description', $rawDescription)) }}</span> / {{ StudioFormat::number($descriptionMax) }}</div>
                                @if($fieldError('description'))<span class="mk-error" id="description-error">{{ $fieldError('description') }}</span>@endif
                            </div>
                        </div>
                    </div>
                </section>

                <section class="mk-panel" data-order="thumbnail" aria-labelledby="mk-thumb-title">
                    <div class="mk-panel__head">
                        <h2 class="mk-panel__title" id="mk-thumb-title">{{ __('Thumbnail') }}</h2>
                        <p class="mk-panel__sub">
                            @if($draft && $readyThumbnails < $thumbnailTarget && ($uploading || $shouldAutoGenerateThumbnails))
                                {{ trans_choice(':ready of :count picture ready. You can fill in the details now.|:ready of :count pictures ready. You can fill in the details now.', $thumbnailTarget, ['ready' => $readyThumbnails, 'count' => $thumbnailTarget]) }}
                            @else
                                {{ __('Pick the picture viewers see first. A clear picture gets more clicks.') }}
                            @endif
                        </p>
                    </div>
                    <div class="mk-panel__body">
                        @php
                            $expectedThumbs = $draft && ($uploading || $shouldAutoGenerateThumbnails) ? $thumbnailTarget : 0;
                            $checkedThumb = $thumbs->first(fn ($t) => (string) $selectedThumb === (string) $t['id'] && in_array($t['status'], [ThumbnailStatus::GENERATED->value, ThumbnailStatus::UPLOADED->value], true));
                            $fallbackThumb = $checkedThumb ? null : $thumbs->firstWhere('is_active', true);
                        @endphp
                        <thumbnails-select video="{{ $video->id }}" initial='@json($initialThumbnails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)' allowupload="{{ $customThumbnails ? '1' : '0' }}" expected="{{ $expectedThumbs }}" poster="{{ $currentPoster }}">
                            <ul class="mk-thumbs">
                                @if($currentPoster)
                                    <li><label class="mk-thumb-pick"><input type="radio" name="thumbnail" value="" @checked($selectedThumb === null || (string) $selectedThumb === '')><span class="mk-thumb"><img src="{{ $currentPoster }}" alt="" width="160" height="90" loading="lazy" decoding="async"></span><span class="mk-thumb-pick__tick" aria-hidden="true"><i class="fa-solid fa-check"></i></span><span class="mk-sr">{{ __('Current picture') }}</span></label></li>
                                @endif
                                @if($uploadedThumb)
                                    <li><label class="mk-thumb-pick"><input type="radio" name="thumbnail" value="{{ $uploadedThumb['id'] }}" @checked((string) $selectedThumb === (string) $uploadedThumb['id'])><span class="mk-thumb"><img src="{{ $uploadedThumb['url'] }}" alt="" width="160" height="90" loading="lazy" decoding="async"></span><span class="mk-thumb-pick__tick" aria-hidden="true"><i class="fa-solid fa-check"></i></span><span class="mk-sr">{{ __('Your picture') }}</span></label></li>
                                @endif
                                @foreach($otherThumbs as $thumb)
                                    @if($thumb['status'] === ThumbnailStatus::GENERATED->value)
                                        <li><label class="mk-thumb-pick"><input type="radio" name="thumbnail" value="{{ $thumb['id'] }}" @checked((string) $selectedThumb === (string) $thumb['id'])><span class="mk-thumb"><img src="{{ $thumb['url'] }}" alt="" width="160" height="90" loading="lazy" decoding="async"></span><span class="mk-thumb-pick__tick" aria-hidden="true"><i class="fa-solid fa-check"></i></span><span class="mk-sr">{{ __('Picture :number', ['number' => $loop->iteration]) }}</span></label></li>
                                    @elseif($thumb['status'] === ThumbnailStatus::PENDING->value)
                                        <li><span class="mk-thumb-pick mk-thumb-wait"><span class="mk-skel" style="aspect-ratio:16/9;inline-size:100%"></span><span class="mk-sr">{{ __('This picture is being made') }}</span></span></li>
                                    @else
                                        <li><span class="mk-thumb-pick mk-thumb-wait"><span class="mk-thumb mk-thumb--icon" title="{{ __('This picture could not be made') }}"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i></span><span class="mk-sr">{{ __('This picture could not be made') }}</span></span></li>
                                    @endif
                                @endforeach
                                @for($i = $otherThumbs->count(); $i < $expectedThumbs; $i++)
                                    <li><span class="mk-thumb-pick mk-thumb-wait"><span class="mk-skel" style="aspect-ratio:16/9;inline-size:100%"></span><span class="mk-sr">{{ __('This picture is being made') }}</span></span></li>
                                @endfor
                                @if($customThumbnails)
                                    <li><label class="mk-thumb-add"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i><span>{{ $uploadedThumb ? __('Change picture') : __('Upload picture') }}</span><input type="file" name="thumbnail_file" class="mk-sr" accept="image/png,image/jpeg,image/webp"></label></li>
                                @endif
                            </ul>
                            @if($fallbackThumb)
                                <input type="hidden" name="thumbnail" value="{{ $fallbackThumb['id'] }}">
                            @endif
                        </thumbnails-select>
                        @if($customThumbnails)
                            <p class="mk-help" style="margin-block-start:.6rem">{{ __('Your own picture: JPG, PNG or WEBP, 16:9, at least :width x :height pixels, up to :size MB.', ['width' => (int) config('site.thumbnail_width', 1280), 'height' => (int) config('site.thumbnail_height', 720), 'size' => max(1, (int) round(((int) config('site.max_thumbnail_size', 2048)) / 1024))]) }}</p>
                        @endif
                        @if($fieldError('thumbnail') || $fieldError('thumbnail_file'))<span class="mk-error">{{ $fieldError('thumbnail_file') ?: $fieldError('thumbnail') }}</span>@endif
                    </div>
                </section>

                <section class="mk-panel" data-order="playlists" aria-labelledby="mk-playlists-title">
                    <div class="mk-panel__head">
                        <h2 class="mk-panel__title" id="mk-playlists-title">{{ __('Playlists') }}</h2>
                        <p class="mk-panel__sub">{{ __('Only your own playlists. Saving never changes other members\' playlists.') }}</p>
                    </div>
                    <div class="mk-panel__body">
                        <input type="hidden" name="playlists_present" value="1">
                        @if($playlists->isEmpty())
                            <p class="mk-help">{{ __('You have no playlists yet.') }} <a href="{{ route('user.playlists.create') }}">{{ __('Create a playlist') }}</a></p>
                        @elseif($enhancedSelects)
                            <label class="mk-sr" for="playlists">{{ __('Playlists') }}</label>
                            <select id="playlists" name="playlists[]" multiple class="select-multiple form-control" autocomplete="off" placeholder="{{ __('Add to a playlist') }}">
                                @foreach($playlists as $playlist)
                                    <option value="{{ $playlist->id }}"{{ in_array((int) $playlist->id, $selectedPlaylists, true) ? ' selected' : '' }}>{{ \Illuminate\Support\Str::limit((string) $playlist->title, 60) }}</option>
                                @endforeach
                            </select>
                        @else
                            <div class="mk-checklist" role="group" aria-labelledby="mk-playlists-title">
                                @foreach($playlists as $playlist)
                                    <label class="mk-option"><input class="mk-check" type="checkbox" name="playlists[]" value="{{ $playlist->id }}" @checked(in_array((int) $playlist->id, $selectedPlaylists, true))><span class="mk-option__text"><span>{{ $playlist->title }}</span></span></label>
                                @endforeach
                            </div>
                        @endif
                        @if($fieldError('playlists') || $errors->has('playlists.*'))<span class="mk-error">{{ $fieldError('playlists') ?: $errors->first('playlists.*') }}</span>@endif
                    </div>
                </section>

                <section class="mk-panel" data-order="settings" aria-labelledby="mk-more-title">
                    <div class="mk-panel__head"><h2 class="mk-panel__title" id="mk-more-title">{{ __('More settings') }}</h2></div>
                    <div class="mk-panel__body">
                        <div class="mk-form">
                            {{-- 1.2.3 round 1 (R1-110): Category has a row of its own (an owner's category names are long:
                                 "Uncensored Leaked", "Unzensiert durchgesickert" were cut in a third of the row at 1366px);
                                 Language and Comments order share the next one --}}
                            <div class="mk-field">
                                <label class="mk-label" for="category">{{ __('Category') }}</label>
                                <select class="form-select" id="category" name="category_id" @if($categoriesNative) data-native-select @endif>
                                    <option value="">{{ __('No category') }}</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}"{{ (string) old('category_id', $video->category_id) === (string) $category->id ? ' selected' : '' }}>{{ __($category->title) }}</option>
                                    @endforeach
                                </select>
                                @if($fieldError('category_id'))<span class="mk-error">{{ $fieldError('category_id') }}</span>@endif
                            </div>
                            <div class="mk-form__row mk-form__row--2">
                                <div class="mk-field">
                                    <label class="mk-label" for="language">{{ __('Language') }}</label>
                                    @php $languageValue = (string) old('language', $draft ? ($video->language ?: config('site.default_video_language', 'en')) : $video->language); @endphp
                                    <select class="form-select" id="language" name="language" data-native-select>
                                        <option value="">{{ __('Not set') }}</option>
                                        @foreach($languages as $code => $language)
                                            <option value="{{ $code }}"{{ $languageValue === (string) $code ? ' selected' : '' }}>{{ ucfirst((string) $language) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mk-field">
                                    <label class="mk-label" for="default_comments_sort">{{ __('Comments order') }}</label>
                                    <select class="form-select" id="default_comments_sort" name="default_comments_sort" data-native-select>
                                        @foreach(['top' => __('Top comments'), 'newest' => __('Newest first')] as $value => $label)
                                            <option value="{{ $value }}"{{ (string) old('default_comments_sort', $video->default_comments_sort ?: 'top') === $value ? ' selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            @php
                                $allowComments = (bool) old('allow_comments', $draft ? true : $video->allow_comments);
                                $showLikes = (bool) old('show_likes', $draft ? true : $video->show_likes);
                                $showAd = (bool) old('show_ad', $draft ? true : $video->show_ad);
                            @endphp
                            @if($commentsEnabled)
                                <input type="hidden" name="allow_comments" value="0">
                                <label class="mk-option mk-option--switch"><input class="mk-switch" type="checkbox" role="switch" name="allow_comments" value="1" @checked($allowComments)><span class="mk-option__text"><span>{{ __('Allow comments') }}</span><span class="mk-option__help">{{ __('Viewers can write comments under this video.') }}</span></span></label>
                            @else
                                <input type="hidden" name="allow_comments" value="{{ $allowComments ? 1 : 0 }}">
                            @endif
                            <input type="hidden" name="show_likes" value="0">
                            <label class="mk-option mk-option--switch"><input class="mk-switch" type="checkbox" role="switch" name="show_likes" value="1" @checked($showLikes)><span class="mk-option__text"><span>{{ __('Show the number of likes') }}</span></span></label>
                            @if($adsEnabled)
                                <input type="hidden" name="show_ad" value="0">
                                <label class="mk-option mk-option--switch"><input class="mk-switch" type="checkbox" role="switch" name="show_ad" value="1" @checked($showAd)><span class="mk-option__text"><span>{{ __('Show ads on this video') }}</span><span class="mk-option__help">{{ __('Only when ads are on for the site.') }}</span></span></label>
                            @else
                                <input type="hidden" name="show_ad" value="{{ $showAd ? 1 : 0 }}">
                            @endif
                        </div>
                    </div>
                </section>
            </div>

            <aside class="mk-edit__side">
                {{-- The preview's processing states exist where they can happen: on the draft page (the Upload18 poll and the
                     upload events of the private channel move them) and for a video still uploading. Video details of a
                     finished video prints the preview alone (lean page content, 4.3). x-show keeps each state's
                     display (style="display:none" until Alpine starts); the [hidden] attribute they had before is
                     display:none !important in Bootstrap's reboot, so "almost ready" could never show. --}}
                <section @class(['mk-panel', 'mk-preview-panel', 'mk-preview-panel--generating' => $shouldAutoGenerateThumbnails]) data-order="preview" aria-label="{{ __('Preview') }}"
                         @if($previewStates) x-data="{ state: @js($uploading ? 'processing' : 'ready') }" @uploaded.window="state = 'almost'" @terminate.window="state = 'ready'" @endif>
                    {{-- R1-092: while a processing state shows, it takes the whole compact preview row on phones (_studio.scss) --}}
                    <div @class(['mk-preview', 'mk-preview--busy' => $previewStates && $uploading]) @if($previewStates) :class="{ 'mk-preview--busy': state !== 'ready' }" @endif>
                        @if($previewStates)
                            <div class="mk-thumb mk-processing" x-show="state === 'processing'" @unless($uploading) style="display:none" @endunless data-video-processing-box>
                                <div class="mk-processing__body">
                                    <span class="mk-spinner" aria-hidden="true"></span>
                                    @if($isProcessing)
                                        {{-- Upload18 has the file and is still processing it: the site finishes this draft by itself. --}}
                                        <strong data-upload18-pending><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i> {{ __('Processing on Upload18') }}</strong>
                                    @else
                                        <strong>{{ __('Your video is processing ...') }}</strong>
                                    @endif
                                    <span class="mk-muted">{{ __('You will receive a notification once the processing is complete') }}</span>
                                </div>
                            </div>
                            @if($draft)
                                <div class="mk-thumb mk-processing" x-show="state === 'almost'" style="display:none">
                                    <div class="mk-processing__body">
                                        <span class="mk-spinner" aria-hidden="true"></span>
                                        <strong>{{ __('Your video is almost ready ...') }}</strong>
                                    </div>
                                </div>
                            @endif
                        @endif
                        <div @if($previewStates) x-show="state === 'ready'" @if($uploading) style="display:none" @endif @endif>
                            @if($previewMode === 'inline' && ! $uploading && $video->file_url)
                                <div class="mk-thumb mk-inline-player" style="background:#000">
                                    <video class="w-100 h-100" controls controlsList="nodownload" preload="metadata" @if($posterUrl) poster="{{ $posterUrl }}" @endif oncontextmenu="return false;">
                                        <source src="{{ $video->file_url }}" type="{{ \App\Models\Video::MIMETYPE }}">
                                    </video>
                                </div>
                            @elseif($previewMode === 'inline' && ! $uploading && $video->embed_url)
                                <div class="mk-thumb mk-inline-player" style="background:#000">
                                    <iframe class="w-100 h-100" src="{{ $video->embed_url }}" title="{{ __('Preview') }}" allow="autoplay; encrypted-media; fullscreen; picture-in-picture; web-share" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin" style="border:0"></iframe>
                                </div>
                            @else
                                <a href="{{ $video->route }}" class="mk-thumb" style="display:block" target="_blank" rel="noopener" aria-label="{{ __('Preview the video on the site') }}">
                                    @if($posterUrl)
                                        <img src="{{ $posterUrl }}" alt="" width="320" height="180" decoding="async">
                                    @else
                                        <span class="mk-thumb__empty"><i class="fa-regular fa-image" aria-hidden="true"></i></span>
                                    @endif
                                    <span class="mk-play-badge" aria-hidden="true"><i class="fa-solid fa-play"></i></span>
                                    @if($video->duration !== '')<span class="mk-thumb__dur">{{ $video->duration }}</span>@endif
                                </a>
                            @endif
                        </div>
                        <div class="mk-preview__facts">
                            <div class="mk-copy"><span class="mk-copy__label">{{ __('Video link') }}</span><span class="mk-copy__value" dir="ltr">{{ preg_replace('#^https?://#', '', $video->route) }}</span><button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--sm" title="{{ __('Copy link') }}" aria-label="{{ __('Copy link') }}" data-copy="{{ $video->route }}"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div>
                            <div class="mk-preview__more">
                                <div class="mk-copy"><span class="mk-copy__label">{{ __('Video ID') }}</span><span class="mk-copy__value" dir="ltr">{{ $video->uuid }}</span><button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--sm" title="{{ __('Copy video ID') }}" aria-label="{{ __('Copy video ID') }}" data-copy="{{ $video->uuid }}"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div>
                                @if($facts !== [])
                                    <div class="mk-muted" style="font-size:.8125rem;overflow-wrap:anywhere">{!! collect($facts)->map(fn ($fact) => e($fact))->implode('&nbsp;· ') !!}</div>
                                @endif
                                @if($video->is_short)
                                    <div class="mk-muted" style="font-size:.8125rem"><i class="fa-solid fa-mobile-screen" aria-hidden="true" style="color:var(--avd-brand-ink)"></i> {{ __('Shown as a Short: vertical, under a minute') }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                    @if($shouldAutoGenerateThumbnails)
                        <div id="thumb-processing" role="status" aria-live="polite">
                            <div class="tp-ring">
                                <svg viewBox="0 0 140 140" aria-hidden="true">
                                    <circle class="tp-bg" cx="70" cy="70" r="65"/>
                                    <circle class="tp-progress" id="tp-progress" cx="70" cy="70" r="65"/>
                                </svg>
                                <div class="tp-number" id="tp-number">0%</div>
                            </div>
                            <div class="tp-status" id="tp-status">
                                <i class="fa-solid fa-cloud-arrow-up me-2" aria-hidden="true"></i>
                                {{ __('Processing video...') }}
                            </div>
                            <div id="tp-steps">
                                <div class="tp-step active" id="step-process">
                                    <span class="tp-spin"><i class="fa-solid fa-gear" aria-hidden="true"></i></span>
                                    <span>{{ __('Video processing') }}</span>
                                </div>
                                <div class="tp-step" id="step-stream">
                                    <span class="pending"><i class="fa-regular fa-circle" aria-hidden="true"></i></span>
                                    <span>{{ __('Loading stream') }}</span>
                                </div>
                                <div class="tp-step" id="step-capture">
                                    <span class="pending"><i class="fa-regular fa-circle" aria-hidden="true"></i></span>
                                    <span>{{ __('Capturing thumbnails') }}</span>
                                </div>
                            </div>
                            <button type="button" class="mk-btn mk-btn--secondary mk-btn--sm" id="tp-skip-always">
                                <i class="fa-solid fa-forward" aria-hidden="true"></i><span>{{ __('Skip & upload manually') }}</span>
                            </button>
                        </div>
                    @endif
                </section>

                <section class="mk-panel" data-order="visibility" aria-labelledby="mk-visibility-title">
                    <div class="mk-panel__head"><h2 class="mk-panel__title" id="mk-visibility-title">{{ __('Visibility') }}</h2></div>
                    <div class="mk-panel__body">
                        <div class="mk-choices" role="radiogroup" aria-labelledby="mk-visibility-title">
                            @foreach($statusChoices as $value => [$icon, $label, $desc])
                                <label class="mk-choice"><input class="mk-radio" type="radio" name="status" value="{{ $value }}" x-model="status" @checked($statusValue === (string) $value)><span class="mk-choice__body"><span class="mk-choice__title"><i class="fa-solid fa-{{ $icon }}" aria-hidden="true"></i>{{ $label }}</span><span class="mk-choice__desc">{{ $desc }}</span></span></label>
                            @endforeach
                        </div>
                        @if($fieldError('status'))<span class="mk-error">{{ $fieldError('status') }}</span>@endif
                        <div class="mk-schedule-wrap" x-show="status === '{{ VideoStatus::PLANNED->value }}'" @if($statusValue !== (string) VideoStatus::PLANNED->value) style="display:none" @endif>
                            <div class="mk-form mk-schedule" style="margin-top:.85rem">
                                <div class="mk-form__row mk-form__row--2">
                                    <div class="mk-field">
                                        <label class="mk-label" for="scheduled_date">{{ __('Date') }}</label>
                                        <input class="mk-input" id="scheduled_date" name="scheduled_date" type="date" min="{{ $nowLocal->toDateString() }}" x-model="date" @change="zoneLine()" :required="status === '{{ VideoStatus::PLANNED->value }}'" :disabled="status !== '{{ VideoStatus::PLANNED->value }}'" @if($fieldError('scheduled_at')) aria-invalid="true" aria-describedby="scheduled-error" @endif>
                                    </div>
                                    <div class="mk-field">
                                        <label class="mk-label" for="scheduled_time">{{ __('Time') }}</label>
                                        <input class="mk-input" id="scheduled_time" name="scheduled_time" type="time" step="300" x-model="time" @change="zoneLine()" :required="status === '{{ VideoStatus::PLANNED->value }}'" :disabled="status !== '{{ VideoStatus::PLANNED->value }}'" @if($fieldError('scheduled_at')) aria-invalid="true" aria-describedby="scheduled-error" @endif>
                                    </div>
                                </div>
                            </div>
                            <p class="mk-help mk-schedule__zone" aria-live="polite">
                                <i class="fa-regular fa-clock" aria-hidden="true"></i>
                                <span aria-atomic="true">{!! $zoneHtml !!}</span>{{ ZoneLabel::gapAfter($zoneSentence) }}{{ ZoneLabel::nowLine() }}<br>
                                <strong aria-atomic="true">{!! $goesHtml !!}</strong>
                            </p>
                            @if($fieldError('scheduled_at'))<span class="mk-error" id="scheduled-error">{{ $fieldError('scheduled_at') }}</span>@endif
                        </div>
                    </div>
                </section>

                @unless($draft)
                    <section class="mk-panel mk-panel--danger" data-order="danger" aria-labelledby="mk-danger-title">
                        <div class="mk-panel__head">
                            <h2 class="mk-panel__title" id="mk-danger-title"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>{{ __('Delete video') }}</h2>
                            <p class="mk-panel__sub">{{ trans_choice('Removes the video, its :count comment and its likes. You can make it private instead.|Removes the video, its :count comments and its likes. You can make it private instead.', (int) $video->comments_count, ['count' => StudioFormat::number((int) $video->comments_count)]) }}</p>
                        </div>
                        <div class="mk-panel__body">
                            <button type="button" class="mk-btn mk-btn--danger-quiet mk-btn--sm" data-bs-toggle="modal" data-bs-target="#mk-delete-video"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ __('Delete video…') }}</span></button>
                        </div>
                    </section>
                @endunless
            </aside>
        </div>

        <div class="mk-savebar">
            <span class="mk-savebar__note"><span class="mk-dot" aria-hidden="true"></span>{{ $draft ? __('Not published yet') : __('Unsaved changes') }}</span>
            @if($draft)
                {{-- 1.2.3 round 1 (R1-024): "Finish later" keeps what was typed (it saves the draft and goes to My videos);
                     with nothing typed it just goes there. It used to drop the changes without asking. --}}
                <button type="submit" class="mk-btn mk-btn--quiet" name="action" value="later" @click="if (!dirty) { $event.preventDefault(); sending = true; window.location.href = @js(route('user.videos.index')); }">{{ __('Finish later') }}</button>
                <button type="submit" class="mk-btn mk-btn--secondary" name="action" value="draft">{{ __('Save draft') }}</button>
                <button type="submit" class="mk-btn mk-btn--primary"><i class="fa-solid fa-check" aria-hidden="true"></i><span>{{ __('Publish') }}</span></button>
            @else
                <a class="mk-btn mk-btn--quiet" href="{{ route('user.videos.edit', $video) }}" @click="dirty = false">{{ __('Cancel') }}</a>
                <button type="submit" class="mk-btn mk-btn--secondary">{{ __('Save and close') }}</button>
                <button type="submit" class="mk-btn mk-btn--primary" name="action" value="save"><i class="fa-solid fa-check" aria-hidden="true"></i><span>{{ __('Save') }}</span></button>
            @endif
        </div>
    </form>

    @unless($draft)
        @include('users.videos.modals.delete', ['video' => $video])
    @endunless
</div>
{{-- only the thumbnail's crop texts (1.2.3 round 1 pass 2, R1-069): the video pages crop thumbnails only, and the avatar's
     and banner's texts were sent as escaped JSON on every Video details and draft page (1.5 KB in Arabic) --}}
@include('users.partials.crop', ['cropKinds' => ['thumbnail']])

{{-- The form's component (mkVideoForm: its state and the schedule line helpers) and the R1-166 watcher of the failed
     notice are in resources/js/member/studio-pages.js, one cached file printed by layouts/user.blade.php (1.2.3 round 1b,
     R1R-01). --}}
