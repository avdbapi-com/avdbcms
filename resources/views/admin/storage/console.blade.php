{{--
    Tools > Cache & storage in the redesigned admin (the old admin keeps storage/index.blade.php). The page answers at
    once; the folder sizes come from the "folders" status probe (cached 10 minutes) and fill in after it appears.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Cache & storage'))

@section('content')
    @php
        $clearable = ['storage/framework/cache' => 'cache', 'storage/framework/sessions' => 'sessions', 'storage/framework/views' => 'views', 'storage/logs' => 'logs'];
        $gb = fn ($bytes) => number_format($bytes / 1073741824, 1);
        $tone = $disk['percent'] >= 90 ? 'danger' : ($disk['percent'] >= 70 ? 'warn' : 'ok');
    @endphp

    <x-admin.page-header />

    <section class="ad-panel ad-console-block" aria-labelledby="ad-storage-disk">
        <div class="ad-panel-h">
            <h2 id="ad-storage-disk" class="ad-home-grow">{{ __('Disk space') }}</h2>
            <x-admin.status :type="$tone">{{ __(':percent% used', ['percent' => $disk['percent']]) }}</x-admin.status>
        </div>
        <div class="ad-panel-b">
            <meter class="ad-meter is-{{ $tone }}" min="0" max="100" low="70" high="90" optimum="0" value="{{ $disk['percent'] }}" aria-labelledby="ad-storage-disk"></meter>
            <p class="ad-small ad-muted ad-console-note">
                {{ __(':used GB used of :total GB. :free GB free.', ['used' => $gb($disk['used']), 'total' => $gb($disk['total']), 'free' => $gb($disk['free'])]) }}
                @if ($disk['percent'] >= 90)
                    {{ __('Uploads, imports and backups stop when the disk is full: clear old logs, or ask your host for more space.') }}
                @endif
            </p>
        </div>
    </section>

    <section class="ad-tablewrap ad-console-block" aria-labelledby="ad-storage-folders" data-ad-defer="folders"
             @if ($directories === null) data-ad-defer-probes="{{ route('admin.status.probe', ['probe' => 'folders']) }}" @endif>
        <div class="ad-toolbar">
            <h2 id="ad-storage-folders" class="ad-h2 ad-home-grow">{{ __('Folders') }}</h2>
            <span class="ad-small ad-muted">{{ __('Files the site keeps on the disk. Caches, sessions, compiled pages and logs can be cleared at any time.') }}</span>
        </div>
        <table class="ad-table is-list">
            <thead>
                <tr>
                    <th scope="col">{{ __('Folder') }}</th>
                    <th scope="col" class="is-num">{{ __('Files') }}</th>
                    <th scope="col" class="is-num">{{ __('Size') }}</th>
                    <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach (\App\Http\Controllers\Admin\StorageController::folders() as $name => $path)
                    @php($dir = $directories[$name] ?? null)
                    <tr>
                        <td class="c-main"><code>{{ $name }}</code>
                            @if ($dir !== null && ! $dir['exists'])
                                <x-admin.status type="off">{{ __('Missing') }}</x-admin.status>
                            @endif
                        </td>
                        <td class="is-num c-meta">
                            @if ($dir === null)
                                <span class="ad-skeleton" aria-hidden="true"></span><span class="ad-vh">{{ __('Counting...') }}</span>
                            @else
                                {{ number_format($dir['files']) }}@if (! empty($dir['partial']))+@endif
                            @endif
                        </td>
                        <td class="is-num c-meta">
                            @if ($dir === null)
                                <span class="ad-skeleton" aria-hidden="true"></span>
                            @else
                                {{ $dir['formatted_size'] ?? '0 B' }}
                            @endif
                        </td>
                        <td class="is-actions">
                            @isset($clearable[$name])
                                <form method="POST" action="{{ route('admin.storage.clear', $clearable[$name]) }}"
                                      data-ad-confirm="{{ __('Clear all files in :name?', ['name' => $name]) }}"
                                      data-ad-confirm-body="{{ match ($clearable[$name]) {
                                          'logs' => __('The error and activity logs are deleted. New ones start at once.'),
                                          'sessions' => config('session.driver') === 'file'
                                              ? __('Everyone who is signed in is signed out, you too.')
                                              : __('This site keeps its sessions elsewhere, so nobody is signed out.'),
                                          default => __('The site builds these files again when it needs them; the first pages may load a little slower.'),
                                      } }}"
                                      data-ad-confirm-label="{{ __('Clear') }}">
                                    @csrf
                                    <button type="submit" class="ad-btn is-danger sm" aria-label="{{ __('Clear :name', ['name' => $name]) }}">{{ AdminTheme::icon('trash') }}{{ __('Clear') }}</button>
                                </form>
                            @endisset
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endsection
