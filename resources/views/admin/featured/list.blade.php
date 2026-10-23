{{--
    Content > Homepage picks in the redesigned admin (the old admin keeps featured/index.blade.php): "Add a pick" (the
    same fields as before: a video, channel, category or banner in a homepage place, with its order and dates), the
    picks in their order with their state, a row menu filled from one template (modules/list-rows.ts: turn on / off
    with Undo, delete with Undo), the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Homepage picks'))

@section('content')
    <x-admin.list-page>
    @php
        $kinds = ['video' => __('Video'), 'user' => __('Channel'), 'category' => __('Category'), 'banner' => __('Banner')];
        $places = ['homepage_hero' => __('Top of the homepage'), 'homepage_sidebar' => __('Homepage side column'), 'trending' => __('Trending section'), 'category_top' => __('Top of category pages')];
        $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value) : null;
    @endphp

    <x-admin.page-header page="content.featured" :primary="false" />

    <details class="ad-panel ad-console-block ad-fold" @if ($featured->total() === 0 || $errors->any()) open @endif>
        <summary class="ad-panel-h"><h2>{{ __('Add a pick') }}</h2></summary>
        <form method="POST" action="{{ route('admin.featured.store') }}" class="ad-panel-b ad-record-fields">
            @csrf
            <div class="ad-record-fields is-3">
                <x-admin.field name="type" :label="__('What')">
                    <select class="ad-select" id="type" name="type">
                        @foreach ($kinds as $value => $label)
                            <option value="{{ $value }}" @selected(old('type', 'video') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.field name="position" :label="__('Place')">
                    <select class="ad-select" id="position" name="position">
                        @foreach ($places as $value => $label)
                            <option value="{{ $value }}" @selected(old('position', 'homepage_hero') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.field name="sort_order" type="number" :label="__('Order')" :help="__('Smaller numbers come first.')" value="0" />
                <x-admin.field name="video_id" type="number" :label="__('Video number (optional)')" />
                <x-admin.field name="user_id" type="number" :label="__('Member number (optional)')" />
                <x-admin.field name="category_id" type="number" :label="__('Category number (optional)')" />
            </div>
            <x-admin.field name="title" :label="__('Title (optional)')" :help="__('Shown instead of the video, channel or category name.')" />
            <x-admin.field name="description" type="textarea" :label="__('Description (optional)')" />
            <div class="ad-record-fields is-3">
                <x-admin.field name="image_url" :label="__('Picture address (optional)')" placeholder="https://" />
                <x-admin.field name="link_url" :label="__('Link (optional)')" placeholder="https://" />
                <span></span>
                <x-admin.field name="starts_at" type="datetime-local" :label="__('Starts (optional)')" />
                <x-admin.field name="expires_at" type="datetime-local" :label="__('Ends (optional)')" />
            </div>
            {{-- No hidden "off" value: the store action reads "is_active present" as on, as the old form sent it. --}}
            <x-admin.toggle name="is_active" :label="__('Show it right away')" :checked="! $errors->any() || old('is_active') === '1'" :off="null" />
            <div class="ad-record-actions"><button type="submit" class="ad-btn is-primary">{{ AdminTheme::icon('star') }}{{ __('Add pick') }}</button></div>
        </form>
    </details>

    <form id="ad-featured-toggle" method="POST" action="{{ route('admin.featured.index', [], false) }}" data-ad-row-action="change" hidden></form>
    <form id="ad-featured-delete" method="POST" action="{{ route('admin.featured.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Pick deleted') }}"
          data-ad-confirm="{{ __('Delete this pick?') }}" data-ad-confirm-body="{{ __('It is no longer promoted. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete pick') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-featured-menu">
        <button type="submit" form="ad-featured-toggle" formaction="{{ route('admin.featured.toggle', ['id' => '__ID__'], false) }}" data-ad-when="on" data-ad-expect="off" data-ad-done="{{ __('Pick turned off') }}" data-ad-undo-url="{{ route('admin.featured.toggle', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('eye-off') }}{{ __('Turn off') }}</button>
        <button type="submit" form="ad-featured-toggle" formaction="{{ route('admin.featured.toggle', ['id' => '__ID__'], false) }}" data-ad-when="off" data-ad-expect="on" data-ad-done="{{ __('Pick turned on') }}" data-ad-undo-url="{{ route('admin.featured.toggle', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('eye') }}{{ __('Turn on') }}</button>
        <a href="__LINK__" target="_blank" rel="noopener" data-ad-if="link">{{ AdminTheme::icon('external') }}{{ __('Open link') }}</a>
        <hr>
        <button type="submit" form="ad-featured-delete" formaction="{{ route('admin.featured.destroy', ['id' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete pick...') }}</button>
    </template>

    <x-admin.table :paginator="$featured" :label="__('Homepage picks')" list="featured" table-class="is-list" row-menu="ad-featured-menu">
        <x-slot:head>
            <th scope="col">{{ __('Pick') }}</th>
            <th scope="col">{{ __('State') }}</th>
            <th scope="col" class="hide-tab">{{ __('Place') }}</th>
            <th scope="col" class="is-num hide-tab">{{ __('Order') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="star" :title="__('No homepage picks yet')">{{ __('Add a video, channel, category or banner to promote it on the homepage.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($featured as $pick)
            @php
                $starts = $date($pick->starts_at);
                $ends = $date($pick->expires_at);
                [$tone, $state] = match (true) {
                    ! $pick->is_active => ['off', __('Off')],
                    $ends !== null && $ends->isPast() => ['off', __('Ended')],
                    $starts !== null && $starts->isFuture() => ['warn', __('Starts :date', ['date' => $starts->translatedFormat('j M')])],
                    default => ['ok', __('On')],
                };
                $name = $pick->title ?: ($pick->video_title ?? $pick->username ?? $pick->category_title ?? $kinds[$pick->type] ?? $pick->type);
            @endphp
            <tr>
                <td class="c-main"><p><b id="ad-f-{{ $pick->id }}">{{ \Illuminate\Support\Str::limit((string) $name, 90) }}</b><small>{{ $kinds[$pick->type] ?? $pick->type }}@if ($ends) &middot; {{ __('until :date', ['date' => $ends->translatedFormat('j M Y')]) }}@endif</small></p></td>
                <td class="c-meta"><x-admin.status :type="$tone">{{ $state }}</x-admin.status><small class="ad-phone-only">{{ $places[$pick->position] ?? $pick->position }}</small></td>
                <td class="hide-tab">{{ $places[$pick->position] ?? \Illuminate\Support\Str::headline((string) $pick->position) }}</td>
                <td class="is-num hide-tab">{{ $pick->sort_order }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-f-{{ $pick->id }}" data-id="{{ $pick->id }}" data-state="{{ $pick->is_active ? 'on' : 'off' }}" @if (preg_match('#^(https?://|/)#i', (string) $pick->link_url)) data-link="{{ $pick->link_url }}" @endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
