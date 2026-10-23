{{--
    Community > Announcements in the redesigned admin (the old admin keeps announcements/index.blade.php): "New
    announcement" (a bar at the top or bottom of every page), the announcements with their state and dates, a row menu
    filled from one template (modules/list-rows.ts: show / hide with Undo, delete with Undo), the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Announcements'))

@section('content')
    @php
        $types = ['info' => __('Information (blue)'), 'success' => __('Good news (green)'), 'warning' => __('Warning (yellow)'), 'danger' => __('Urgent (red)')];
        $tones = ['info' => 'info', 'success' => 'ok', 'warning' => 'warn', 'danger' => 'danger'];
        $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y, H:i') : null;
    @endphp

    <x-admin.page-header page="community.announcements" />

    <details class="ad-panel ad-console-block ad-fold" @if ($announcements->total() === 0 || $errors->any()) open @endif>
        <summary class="ad-panel-h"><h2>{{ __('New announcement') }}</h2></summary>
        <form method="POST" action="{{ route('admin.announcements.store') }}" class="ad-panel-b ad-record-fields">
            @csrf
            <x-admin.field name="title" :label="__('Title')" />
            <x-admin.field name="message" type="textarea" :label="__('Message')" />
            <div class="ad-record-fields is-3">
                <x-admin.field name="type" :label="__('Colour')">
                    <select class="ad-select" id="type" name="type">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @selected(old('type', 'info') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.field name="position" :label="__('Place')">
                    <select class="ad-select" id="position" name="position">
                        <option value="top" @selected(old('position', 'top') === 'top')>{{ __('Top of the page') }}</option>
                        <option value="bottom" @selected(old('position') === 'bottom')>{{ __('Bottom of the page') }}</option>
                    </select>
                </x-admin.field>
                <span></span>
                <x-admin.field name="starts_at" type="datetime-local" :label="__('Starts (optional)')" />
                <x-admin.field name="expires_at" type="datetime-local" :label="__('Ends (optional)')" />
            </div>
            <input type="hidden" name="is_active" value="1">
            <div class="ad-record-actions"><button type="submit" class="ad-btn is-primary">{{ AdminTheme::icon('megaphone') }}{{ __('Show announcement') }}</button></div>
        </form>
    </details>

    <form id="ad-announcement-toggle" method="POST" action="{{ route('admin.announcements.index', [], false) }}" data-ad-row-action="change" hidden></form>
    <form id="ad-announcement-delete" method="POST" action="{{ route('admin.announcements.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Announcement deleted') }}"
          data-ad-confirm="{{ __('Delete this announcement?') }}" data-ad-confirm-body="{{ __('You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete announcement') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-announcement-menu">
        <button type="submit" form="ad-announcement-toggle" formaction="{{ route('admin.announcements.toggle', ['id' => '__ID__'], false) }}" data-ad-when="on" data-ad-expect="off" data-ad-done="{{ __('Announcement hidden') }}" data-ad-undo-url="{{ route('admin.announcements.toggle', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('eye-off') }}{{ __('Hide') }}</button>
        <button type="submit" form="ad-announcement-toggle" formaction="{{ route('admin.announcements.toggle', ['id' => '__ID__'], false) }}" data-ad-when="off" data-ad-expect="on" data-ad-done="{{ __('Announcement shown') }}" data-ad-undo-url="{{ route('admin.announcements.toggle', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('eye') }}{{ __('Show') }}</button>
        <hr>
        <button type="submit" form="ad-announcement-delete" formaction="{{ route('admin.announcements.destroy', ['id' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete announcement...') }}</button>
    </template>

    <x-admin.table :paginator="$announcements" :label="__('Announcements')" list="announcements" table-class="is-list" row-menu="ad-announcement-menu">
        <x-slot:head>
            <th scope="col">{{ __('Announcement') }}</th>
            <th scope="col">{{ __('State') }}</th>
            <th scope="col" class="hide-tab">{{ __('When') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="megaphone" :title="__('No announcements yet')">{{ __('An announcement is a bar every visitor sees at the top or bottom of the pages.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($announcements as $announcement)
            @php
                $ended = $announcement->expires_at && \Illuminate\Support\Carbon::parse($announcement->expires_at)->isPast();
                $period = trim(($date($announcement->starts_at) ?? '').' - '.($date($announcement->expires_at) ?? ''), ' -');
            @endphp
            <tr>
                <td class="c-main"><div class="ad-vid"><span class="ad-sym is-{{ $tones[$announcement->type] ?? 'info' }}" aria-hidden="true">{{ AdminTheme::icon('megaphone') }}</span><p><b id="ad-an-{{ $announcement->id }}">{{ $announcement->title }}</b><small>{{ \Illuminate\Support\Str::limit((string) $announcement->message, 140) }}</small></p></div></td>
                <td class="c-meta"><x-admin.status :type="$ended ? 'off' : ($announcement->is_active ? 'ok' : 'off')">{{ $ended ? __('Ended') : ($announcement->is_active ? __('Shown') : __('Hidden')) }}</x-admin.status><small class="ad-phone-only">{{ $announcement->position === 'bottom' ? __('Bottom of the page') : __('Top of the page') }}</small></td>
                <td class="hide-tab">{{ $period !== '' ? $period : __('Always') }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-an-{{ $announcement->id }}" data-id="{{ $announcement->id }}" data-state="{{ $announcement->is_active ? 'on' : 'off' }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
@endsection
