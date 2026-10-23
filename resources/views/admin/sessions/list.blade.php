{{--
    Members > Logins & devices > Signed in now, in the redesigned admin (the old admin keeps sessions/index.blade.php):
    who is signed in where (the database session store), everyone / members / visitors, a row menu filled from one
    template (modules/list-rows.ts: sign out with Undo), signing out the visitors or everyone else, the pager.
    Other session stores keep no sessions in the database: the page says so.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Logins & devices'))

@section('content')
    <x-admin.list-page>
    @php
        // "Chrome on Windows" from a browser's user agent (the full text stays in the row's title).
        $device = function (?string $agent): string {
            $agent = (string) $agent;
            $browser = collect(['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'])
                ->first(fn ($name, $needle) => str_contains($agent, $needle));
            $system = collect(['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'Linux' => 'Linux'])
                ->first(fn ($name, $needle) => str_contains($agent, $needle));

            return match (true) {
                $browser !== null && $system !== null => __(':browser on :system', ['browser' => $browser, 'system' => $system]),
                $browser !== null || $system !== null => (string) ($browser ?? $system),
                $agent !== '' => \Illuminate\Support\Str::limit($agent, 40),
                default => __('Unknown device'),
            };
        };
    @endphp

    @if ($sessions === null)
        <x-admin.page-header page="members.logins" />
        <x-admin.callout type="info" :title="__('Sessions are not kept in the database')">
            {{ __('This site keeps its sessions in ":store", so they cannot be listed here. The database store lists them; it is chosen in the settings file.', ['store' => $driver]) }}
        </x-admin.callout>
    @else
        <x-admin.page-header page="members.logins"
            :description="trans_choice('1 session is open.|:count sessions are open.', $stats['total'], ['count' => number_format($stats['total'])]).' '.__('Members: :members. Visitors: :visitors.', ['members' => number_format($stats['members']), 'visitors' => number_format($stats['visitors'])])">
            <x-slot:actions>
                @if ($stats['visitors'] > 0)
                    <form method="POST" action="{{ route('admin.sessions.clear-guests') }}">
                        @csrf
                        <button type="submit" class="ad-btn">{{ __('Sign out the visitors') }}</button>
                    </form>
                @endif
                @if ($stats['total'] > 1)
                    <form method="POST" action="{{ route('admin.sessions.clear-all') }}"
                          data-ad-confirm="{{ __('Sign out everyone else?') }}" data-ad-confirm-body="{{ __('Every member and visitor is signed out, except you on this device.') }}" data-ad-confirm-label="{{ __('Sign everyone out') }}">
                        @csrf
                        <button type="submit" class="ad-btn is-danger">{{ AdminTheme::icon('logout') }}{{ __('Sign out everyone else...') }}</button>
                    </form>
                @endif
            </x-slot:actions>
        </x-admin.page-header>

        <form id="ad-session-end" method="POST" action="{{ route('admin.sessions.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Signed out') }}"
              data-ad-confirm="{{ __('Sign out this session?') }}" data-ad-confirm-body="{{ __('That device has to sign in again. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Sign out') }}" hidden>@method('DELETE')</form>
        <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
        <template id="ad-session-menu">
            <a href="{{ route('admin.users.edit', ['user' => '__USER__'], false) }}" data-ad-if="user">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
            <button type="submit" form="ad-session-end" formaction="__URL__" class="is-danger">{{ AdminTheme::icon('logout') }}{{ __('Sign out...') }}</button>
        </template>

        <x-admin.table :paginator="$sessions" :label="__('Open sessions')" list="sessions" table-class="is-list" row-menu="ad-session-menu">
            <x-slot:toolbar>
                <form method="GET" action="{{ route('admin.sessions.index') }}" class="ad-filter" aria-label="{{ __('Filter sessions') }}">
                    <select class="ad-select" name="who" aria-label="{{ __('Show') }}" data-ad-autosubmit>
                        <option value="">{{ __('Everyone') }}</option>
                        <option value="members" @selected($who === 'members')>{{ __('Members') }}</option>
                        <option value="visitors" @selected($who === 'visitors')>{{ __('Visitors') }}</option>
                    </select>
                    <noscript><button type="submit" class="ad-btn">{{ __('Show') }}</button></noscript>
                </form>
            </x-slot:toolbar>

            <x-slot:head>
                <th scope="col">{{ __('Who') }}</th>
                <th scope="col">{{ __('Device') }}</th>
                <th scope="col" class="hide-tab">{{ __('Address') }}</th>
                <th scope="col">{{ __('Last seen') }}</th>
                <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
            </x-slot:head>

            <x-slot:empty>
                <x-admin.empty icon="users" :title="__('No open sessions')">{{ __('Sessions appear here while people use your site.') }}</x-admin.empty>
            </x-slot:empty>

            @foreach ($sessions as $session)
                @php
                    $mine = $session->id === $current;
                    $seen = \Illuminate\Support\Carbon::createFromTimestamp((int) $session->last_activity);
                    $key = 'ad-s-'.substr(md5($session->id), 0, 10);
                @endphp
                <tr>
                    <td class="c-main"><p><b id="{{ $key }}">{{ $session->username ?? __('Visitor') }}</b><small>{{ $mine ? __('You, on this device') : ($session->user_email ?? __('Not signed in')) }}</small></p></td>
                    <td class="c-meta" title="{{ $session->user_agent }}">{{ $device($session->user_agent) }}@if ($mine) <x-admin.status type="ok">{{ __('This device') }}</x-admin.status>@endif</td>
                    <td class="hide-tab"><code>{{ $session->ip_address ?? '-' }}</code></td>
                    <td class="is-date" title="{{ $seen->translatedFormat('j M Y, H:i') }}">{{ $seen->diffForHumans(short: true) }}</td>
                    <td class="is-actions">@unless ($mine)<button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act {{ $key }}" data-id="{{ $key }}" data-url="{{ route('admin.sessions.destroy', $session->id, false) }}" @if ($session->user_id) data-user="{{ $session->user_id }}" @endif></button>@endunless</td>
                </tr>
            @endforeach
        </x-admin.table>
    @endif
    </x-admin.list-page>
@endsection
