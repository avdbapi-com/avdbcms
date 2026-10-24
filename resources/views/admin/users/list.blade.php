{{--
    Members > All members in the redesigned admin (the old admin keeps users/index.blade.php): tabs with counts
    (Blocked, Premium, and the "Email not confirmed" page), search, bulk actions, a row menu per member filled from one
    template (modules/list-rows.ts), the pager. Initials instead of avatar images keep the page light. A view-only demo
    account (users.is_view_only) carries a "View-only" badge beside its status.
--}}
@use('App\Http\Middleware\ViewOnlyDemo')
@use('App\Support\AdminTheme')
@use('App\Support\AdminNav')
@extends('layouts.admin')

@section('title', __('All members'))

@section('content')
    @php
        $status = (string) request()->query('status', '');
        $tab = fn (string $value, int $count, string $label) => [
            'label' => $label,
            'url' => route('admin.users.index', array_filter(['status' => $value])),
            'active' => $status === $value,
            'count' => number_format($count),
        ];
        $tabs = [
            $tab('', $counts['all'], __('All members')),
            $tab('banned', $counts['banned'], __('Blocked')),
            $tab('premium', $counts['premium'], __('Premium')),
            ['label' => __('Email not confirmed'), 'url' => route('admin.verification.index'), 'active' => false, 'count' => number_format($counts['unverified'])],
        ];
        $impersonate = AdminNav::url(['route' => 'admin.impersonate', 'params' => ['id' => '__ID__']]);
        $me = auth()->id();
    @endphp

    <x-admin.page-header page="members.all"
        :description="trans_choice('1 member.|:count members.', $counts['all'], ['count' => number_format($counts['all'])])"
        :tabs="$tabs">
        <x-slot:actions>
            <a class="ad-btn" href="{{ route('admin.users.export', request()->query()) }}">{{ AdminTheme::icon('download') }}{{ __('Export') }}</a>
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-users-bulk" method="POST" action="{{ route('admin.bulk.users') }}" hidden>@csrf</form>
    {{-- The row menus' actions: one form each, the row's button brings its member (formaction). --}}
    {{-- Administrator rights go through the bulk action (users.is_admin is not mass-assignable, so the old
         toggle-admin address does not change it); granting them asks first, as the bulk bar does. --}}
    <form id="ad-user-make-admin" method="POST" action="{{ route('admin.bulk.users') }}" data-ad-row-action="change" data-ad-done="{{ __('Now an administrator') }}"
          data-ad-confirm="{{ __('Make this member an administrator?') }}" data-ad-confirm-body="{{ __('Administrators can change everything in this admin.') }}" data-ad-confirm-label="{{ __('Make administrator') }}" data-ad-confirm-tone="primary" hidden><input type="hidden" name="action" value="make_admin"></form>
    <form id="ad-user-remove-admin" method="POST" action="{{ route('admin.bulk.users') }}" data-ad-row-action="change" data-ad-done="{{ __('No longer an administrator') }}" hidden><input type="hidden" name="action" value="remove_admin"></form>
    <form id="ad-user-confirm" method="POST" action="{{ route('admin.bulk.users') }}" data-ad-row-action="change" data-ad-done="{{ __('Email marked as confirmed') }}" hidden></form>
    <form id="ad-user-ban" method="POST" action="{{ route('admin.bulk.users') }}" data-ad-row-action="change" data-ad-done="{{ __('Member blocked') }}"
          data-ad-confirm="{{ __('Block this member?') }}" data-ad-confirm-body="{{ __('They can no longer sign in, upload or comment. You can unblock them later.') }}" data-ad-confirm-label="{{ __('Block member') }}" hidden></form>
    <form id="ad-user-ban-all" method="POST" action="{{ route('admin.bulk.users') }}" data-ad-row-action="change" data-ad-done="{{ __('Member blocked') }}"
          data-ad-confirm="{{ __('Block this member and hide their videos and comments?') }}" data-ad-confirm-body="{{ __('Their videos are banned and their comments hidden too.') }}" data-ad-confirm-label="{{ __('Block member') }}" hidden><input type="hidden" name="ban_videos" value="1"><input type="hidden" name="ban_comments" value="1"></form>
    <form id="ad-user-unban" method="POST" action="{{ route('admin.bulk.users') }}" data-ad-row-action="change" data-ad-done="{{ __('Member unblocked') }}" hidden></form>
    <form id="ad-user-delete" method="POST" action="{{ route('admin.bulk.users') }}" data-ad-row-action="delete" data-ad-done="{{ __('Member deleted') }}"
          data-ad-confirm="{{ __('Delete this member for good?') }}" data-ad-confirm-body="{{ __('Their videos, comments and playlists are deleted too. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete member') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-select">{{ __('Select') }}</span><span hidden id="ad-l-actions">{{ __('Actions for') }}</span>
    <template id="ad-user-menu">
        <a href="{{ route('admin.users.edit', ['user' => '__ID__'], false) }}">{{ AdminTheme::icon('pencil') }}{{ __('Edit') }}</a>
        <a href="{{ route('admin.users.show', ['user' => '__ID__'], false) }}">{{ AdminTheme::icon('user') }}{{ __('Details') }}</a>
        <a href="__URL__" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('Open channel') }}</a>
        @if ($impersonate !== null)
            <a href="{{ $impersonate }}" data-ad-when="!self,admin">{{ AdminTheme::icon('key') }}{{ __('Log in as this member') }}</a>
        @endif
        <hr>
        <button type="submit" form="ad-user-confirm" formaction="{{ route('admin.users.confirm', ['user' => '__ID__'], false) }}" data-ad-if="unverified" data-ad-undo-url="{{ route('admin.users.unverify', ['user' => '__ID__'], false) }}">{{ AdminTheme::icon('check-circle') }}{{ __('Mark email as confirmed') }}</button>
        <button type="submit" form="ad-user-make-admin" name="ids[]" value="__ID__" data-ad-when="member,premium" data-ad-expect="admin" data-ad-undo-url="{{ route('admin.bulk.users', [], false) }}" data-ad-undo-fields='{"ids":["__ID__"],"action":"remove_admin"}'>{{ AdminTheme::icon('shield') }}{{ __('Make administrator') }}</button>
        <button type="submit" form="ad-user-remove-admin" name="ids[]" value="__ID__" data-ad-when="admin" data-ad-expect="!admin" data-ad-undo-url="{{ route('admin.bulk.users', [], false) }}" data-ad-undo-fields='{"ids":["__ID__"],"action":"make_admin"}'>{{ AdminTheme::icon('shield') }}{{ __('Remove administrator') }}</button>
        <hr>
        <button type="submit" form="ad-user-unban" formaction="{{ route('admin.users.unban', ['user' => '__ID__'], false) }}" data-ad-when="banned" data-ad-expect="!banned">{{ AdminTheme::icon('check-circle') }}{{ __('Unblock') }}</button>
        <button type="submit" form="ad-user-ban" formaction="{{ route('admin.users.ban', ['user' => '__ID__'], false) }}" class="is-danger" data-ad-when="member" data-ad-expect="banned" data-ad-undo-url="{{ route('admin.users.unban', ['user' => '__ID__'], false) }}">{{ AdminTheme::icon('ban') }}{{ __('Block member') }}</button>
        <button type="submit" form="ad-user-ban-all" formaction="{{ route('admin.users.ban', ['user' => '__ID__'], false) }}" class="is-danger" data-ad-when="member" data-ad-expect="banned">{{ AdminTheme::icon('ban') }}{{ __('Block, with videos and comments') }}</button>
        <button type="submit" form="ad-user-delete" formaction="{{ route('admin.users.delete', ['user' => '__ID__'], false) }}" class="is-danger" data-ad-when="!self">{{ AdminTheme::icon('trash') }}{{ __('Delete member...') }}</button>
    </template>

    <x-admin.table :paginator="$users" :label="__('Members')" list="users" table-class="is-list">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.users.index') }}" class="ad-filter" role="search" aria-label="{{ __('Find members') }}">
                @if ($status !== '')
                    <input type="hidden" name="status" value="{{ $status }}">
                @endif
                <span class="ad-search-field">
                    {{ AdminTheme::icon('search', 'sm') }}
                    <input class="ad-input" type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('Name, email or channel address') }}" aria-label="{{ __('Search members') }}">
                </span>
                <span class="ad-spacer"></span>
                <button type="submit" class="ad-btn">{{ __('Search') }}</button>
                @if (request()->filled('search'))
                    <a class="ad-btn is-ghost" href="{{ route('admin.users.index', array_filter(['status' => $status])) }}">{{ __('Clear filters') }}</a>
                @endif
            </form>
        </x-slot:toolbar>

        <x-slot:bulk>
            <button type="submit" class="ad-btn sm" form="ad-users-bulk" name="action" value="verify">{{ __('Mark emails as confirmed') }}</button>
            <button type="submit" class="ad-btn sm" form="ad-users-bulk" name="action" value="make_admin"
                    data-ad-confirm="{{ __('Make the selected members administrators?') }}" data-ad-confirm-body="{{ __('Administrators can change everything in this admin.') }}" data-ad-confirm-label="{{ __('Make administrators') }}" data-ad-confirm-tone="primary">{{ __('Make administrators') }}</button>
            <button type="submit" class="ad-btn sm" form="ad-users-bulk" name="action" value="remove_admin">{{ __('Remove administrator') }}</button>
            <button type="submit" class="ad-btn sm" form="ad-users-bulk" name="action" value="unban">{{ __('Unblock') }}</button>
            <button type="submit" class="ad-btn is-danger sm" form="ad-users-bulk" name="action" value="ban"
                    data-ad-confirm="{{ __('Block the selected members?') }}" data-ad-confirm-body="{{ __('They can no longer sign in, upload or comment. You can unblock them later.') }}" data-ad-confirm-label="{{ __('Block members') }}">{{ __('Block') }}</button>
            <button type="submit" class="ad-btn is-danger sm" form="ad-users-bulk" name="action" value="delete"
                    data-ad-confirm="{{ __('Delete the selected members for good?') }}" data-ad-confirm-body="{{ __('Their videos, comments and playlists are deleted too. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete members') }}">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
        </x-slot:bulk>

        <x-slot:head>
            <th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="{{ __('Select all members on this page') }}"></th>
            <th scope="col">{{ __('Member') }}</th>
            <th scope="col">{{ __('Status') }}</th>
            <th scope="col" class="is-num">{{ __('Videos') }}</th>
            <th scope="col" class="is-num hide-tab">{{ __('Comments') }}</th>
            <th scope="col" class="hide-tab">{{ __('Last sign-in') }}</th>
            <th scope="col">{{ __('Joined') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="users" :title="request()->filled('search') || $status !== '' ? __('No members match') : __('No members yet')">{{ __('Members appear here when they sign up.') }}</x-admin.empty>
        </x-slot:empty>

        @php ob_start(); @endphp
        @foreach ($users as $user)
            @php
                [$tone, $label, $state] = match (true) {
                    $user->id === $me => ['ok', __('You'), 'self'],
                    $user->is_banned => ['danger', __('Blocked'), 'banned'],
                    $user->is_admin => ['info', __('Administrator'), 'admin'],
                    $user->is_premium => ['ok', __('Premium'), 'premium'],
                    $user->email_verified_at === null => ['warn', __('Email not confirmed'), 'member'],
                    default => ['ok', __('Active'), 'member'],
                };
                $joined = $user->created_at;
            @endphp
            <tr>
                <td class="is-check">@if ($user->id !== $me)<input type="checkbox" class="ad-check" name="ids[]" value="{{ $user->id }}" form="ad-users-bulk" data-ad-select aria-labelledby="ad-l-select ad-u-{{ $user->id }}">@endif</td>
                <td class="c-main">
                    <div class="ad-vid">
                        <span class="ad-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr((string) $user->username, 0, 2)) }}</span>
                        <span class="ad-vid-text">
                            <a class="ad-vid-title" id="ad-u-{{ $user->id }}" href="{{ route('admin.users.edit', $user, false) }}">{{ $user->username }}</a>
                            <span class="ad-vid-sub">{{ $user->email }}</span>
                        </span>
                    </div>
                </td>
                <td class="c-meta">
                    <x-admin.status :type="$tone">{{ $label }}</x-admin.status>
                    @if (ViewOnlyDemo::isViewOnlyUser($user))
                        <x-admin.status type="info" data-view-only-badge="1">{{ __('View-only') }}</x-admin.status>
                    @endif
                    <span class="ad-phone-only ad-faint">{{ trans_choice('1 video|:count videos', (int) $user->videos_count, ['count' => number_format((int) $user->videos_count)]) }}</span>
                </td>
                <td class="is-num">{{ number_format((int) $user->videos_count) }}</td>
                <td class="is-num hide-tab">{{ number_format((int) $user->comments_count) }}</td>
                <td class="ad-muted hide-tab">{{ $user->last_login_at?->diffForHumans() ?? __('Never') }}</td>
                <td class="ad-muted is-date"><time datetime="{{ $joined?->toIso8601String() }}">{{ $joined?->translatedFormat('j M Y') }}</time></td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" data-ad-row-menu="ad-user-menu" aria-haspopup="true" aria-labelledby="ad-l-actions ad-u-{{ $user->id }}" data-id="{{ $user->id }}" data-state="{{ $state }}" data-url="{{ $user->route }}"@if ($user->email_verified_at === null && $user->id !== $me) data-unverified="1"@endif></button></td>
            </tr>
        @endforeach
        {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
    </x-admin.table>
@endsection
