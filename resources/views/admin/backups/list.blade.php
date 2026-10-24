{{--
    Tools > Backups in the redesigned admin (the old admin keeps backups/index.blade.php): how big the database is,
    "Back up now", the backups kept on the server with their size and date, a row menu filled from one template
    (modules/list-rows.ts: download, delete with Undo), the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Backups'))

@section('content')
    @php ob_start(); @endphp
    @php
        $latest = $backups->first();
        // "Your database is 3.38 MB. 1 backup takes 35.3 KB on the server.": sizes as people read them (KB under 1 MB,
        // like the Size column); a part the page cannot say is left out (no backups yet: the callout below says so).
        $summary = array_filter([
            filled($dbSize ?? null) ? __('Your database is :size.', ['size' => $dbSize]) : null,
            $backupCount > 0 ? trans_choice('1 backup takes :total on the server.|:count backups take :total on the server.', $backupCount, ['count' => number_format($backupCount), 'total' => $backupTotalText]) : null,
        ]);
    @endphp

    <x-admin.page-header page="tools.backups" :description="$summary === [] ? null : implode(' ', $summary)">
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.backups.create') }}" data-ad-busy="{{ __('Backing up...') }}">
                @csrf
                <button type="submit" class="ad-btn is-primary">{{ AdminTheme::icon('archive') }}{{ __('Back up now') }}</button>
            </form>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($latest === null || $latest['date'] < now()->subDays(30)->getTimestamp())
        <x-admin.callout type="warn" class="ad-console-block" :title="$latest === null ? __('No backup yet') : __('The last backup is more than 30 days old')">
            {{ __('A backup is a copy of your database: keep one in case something goes wrong. Download it to keep it off the server too.') }}
        </x-admin.callout>
    @endif

    <form id="ad-backup-delete" method="POST" action="{{ route('admin.backups.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Backup deleted') }}"
          data-ad-confirm="{{ __('Delete this backup?') }}" data-ad-confirm-body="{{ __('The file is removed from the server. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete backup') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-backup-menu">
        <a href="{{ route('admin.backups.download', ['filename' => '__NAME__'], false) }}">{{ AdminTheme::icon('download') }}{{ __('Download') }}</a>
        <hr>
        <button type="submit" form="ad-backup-delete" formaction="{{ route('admin.backups.delete', ['filename' => '__NAME__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete backup...') }}</button>
    </template>

    <x-admin.table :paginator="$backups" :label="__('Backups')" list="backups" table-class="is-list" row-menu="ad-backup-menu">
        <x-slot:head>
            <th scope="col">{{ __('Backup') }}</th>
            <th scope="col" class="is-num">{{ __('Size') }}</th>
            <th scope="col" class="hide-tab">{{ __('Made') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="archive" :title="__('No backups yet')">{{ __('"Back up now" saves a copy of your database here.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($backups as $backup)
            @php($made = \Illuminate\Support\Carbon::createFromTimestamp($backup['date']))
            {{-- On phones the row is "name" over "size  age": the age sits in the size cell (a row with a gap there), not
                 straight after the file name, where it ran into it ("....sql18 seconds ago"). --}}
            <tr>
                <td class="c-main"><p><b id="ad-bk-{{ $loop->index }}"><code>{{ $backup['name'] }}</code></b></p></td>
                <td class="c-meta is-num text-nowrap">{{ $backup['size_text'] }}<small class="ad-phone-only">{{ $made->diffForHumans() }}</small></td>
                <td class="hide-tab is-date" title="{{ $made->translatedFormat('j M Y, H:i') }}">{{ $made->diffForHumans() }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-bk-{{ $loop->index }}" data-id="{{ $backup['name'] }}" data-name="{{ rawurlencode($backup['name']) }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
@endsection
