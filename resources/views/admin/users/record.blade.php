{{--
    Members > one member (the edit and details addresses both open it), in the redesigned admin (the old admin keeps
    users/edit and show): the account form (saved with the save bar, Ctrl+S or the header's Save), beside it the
    member's state, numbers and latest videos, and the member actions in the header's More menu - administrator rights
    through the bulk action (users.is_admin cannot be written by the form), block, confirm email, log in as, delete -
    each risky one with its confirmation.
--}}
@use('App\Support\AdminNav')
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', $user->username)

@section('content')
    @php
        $self = $user->id === auth()->id();
        [$tone, $state] = match (true) {
            (bool) $user->is_banned => ['danger', __('Blocked')],
            (bool) $user->is_admin => ['info', __('Administrator')],
            (bool) $user->is_premium => ['ok', __('Premium')],
            $user->email_verified_at === null => ['warn', __('Email not confirmed')],
            default => ['ok', __('Active')],
        };
        $date = fn ($value) => $value?->translatedFormat('j M Y, H:i');
        $impersonate = $self || $user->is_admin ? null : AdminNav::url(['route' => 'admin.impersonate', 'params' => ['id' => $user->id]]);
        $facts = array_filter([
            __('Joined') => $date($user->created_at),
            __('Last sign-in') => $date($user->last_login_at) ?? __('Never'),
            __('Last IP address') => $user->last_login_ip,
            __('Email confirmed') => $user->email_verified_at ? $date($user->email_verified_at) : __('No'),
            __('Blocked since') => $user->banned_at ? $date($user->banned_at) : null,
            __('Videos') => number_format((int) $user->videos_count),
            __('Comments') => number_format((int) $user->comments_count),
            __('Subscribers') => number_format((int) $user->subscribers_count),
            __('Premium plan') => $user->premium_subscription?->plan?->name ?? null,
            'ID' => '#'.$user->id,
        ], fn ($value) => $value !== null && $value !== '');
    @endphp

    <x-admin.page-header page="members.all" :title="$user->username"
        :description="$state.' · '.$user->email"
        :crumbs="[['label' => __('Members'), 'url' => route('admin.users.index')], ['label' => __('All members'), 'url' => route('admin.users.index')], ['label' => $user->username, 'url' => null]]"
        :tabs="[]">
        <x-slot:actions>
            <a class="ad-btn" href="{{ $user->route }}" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('Open channel') }}</a>
            @unless ($self)
                <x-admin.menu id="ad-user-more" :label="__('More actions')">
                    @if ($impersonate)
                        <a href="{{ $impersonate }}">{{ AdminTheme::icon('key') }}{{ __('Log in as this member') }}</a>
                    @endif
                    <a href="{{ route('admin.videos.index', ['user' => $user->id]) }}">{{ AdminTheme::icon('film') }}{{ __('Their videos') }}</a>
                    <a href="{{ route('admin.comments.index', ['user' => $user->id]) }}">{{ AdminTheme::icon('chat') }}{{ __('Their comments') }}</a>
                    <hr>
                    @if ($user->email_verified_at === null)
                        <form method="POST" action="{{ route('admin.users.confirm', $user) }}">
                            @csrf
                            <button type="submit">{{ AdminTheme::icon('check-circle') }}{{ __('Mark email as confirmed') }}</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.bulk.users') }}"
                          @unless ($user->is_admin) data-ad-confirm="{{ __('Make this member an administrator?') }}" data-ad-confirm-body="{{ __('Administrators can change everything in this admin.') }}" data-ad-confirm-label="{{ __('Make administrator') }}" data-ad-confirm-tone="primary" @endunless>
                        @csrf
                        <input type="hidden" name="ids[]" value="{{ $user->id }}">
                        <input type="hidden" name="action" value="{{ $user->is_admin ? 'remove_admin' : 'make_admin' }}">
                        <button type="submit">{{ AdminTheme::icon('shield') }}{{ $user->is_admin ? __('Remove administrator') : __('Make administrator') }}</button>
                    </form>
                    @if ($user->is_banned)
                        <form method="POST" action="{{ route('admin.users.unban', $user) }}">
                            @csrf
                            <button type="submit">{{ AdminTheme::icon('check-circle') }}{{ __('Unblock') }}</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.users.ban', $user) }}"
                              data-ad-confirm="{{ __('Block this member?') }}" data-ad-confirm-body="{{ __('They can no longer sign in, upload or comment. You can unblock them later.') }}" data-ad-confirm-label="{{ __('Block member') }}">
                            @csrf
                            <button type="submit" class="is-danger">{{ AdminTheme::icon('ban') }}{{ __('Block member') }}</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.users.delete', $user) }}"
                          data-ad-confirm="{{ __('Delete this member for good?') }}" data-ad-confirm-body="{{ __('Their videos, comments and playlists are deleted too. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete member') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete member...') }}</button>
                    </form>
                </x-admin.menu>
            @endunless
            <button type="submit" class="ad-btn is-primary" form="ad-user-form">{{ __('Save changes') }}</button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="ad-record">
        <form id="ad-user-form" method="POST" action="{{ route('admin.users.update', $user) }}" data-ad-form class="ad-record-main">
            @csrf
            @method('PUT')

            <section class="ad-panel" aria-labelledby="ad-user-account">
                <div class="ad-panel-h"><h2 id="ad-user-account">{{ __('Account') }}</h2></div>
                <div class="ad-panel-b ad-record-fields">
                    <x-admin.field name="username" :label="__('Name')" :value="$user->username" :help="__('Also the address of their channel.')" />
                    <x-admin.field name="email" type="email" :label="__('Email')" :value="$user->email" />
                    <x-admin.field name="password" type="password" :label="__('New password')" :help="__('Leave it empty to keep their password.')">
                        <input class="ad-input" type="password" id="password" name="password" autocomplete="new-password" aria-describedby="password-help">
                    </x-admin.field>
                </div>
            </section>

            <section class="ad-panel" aria-labelledby="ad-user-profile">
                <div class="ad-panel-h"><h2 id="ad-user-profile">{{ __('Profile') }}</h2></div>
                <div class="ad-panel-b ad-record-fields">
                    <x-admin.field name="country" :label="__('Country')" :value="$user->country" :help="__('Two letters, e.g. US or VN.')">
                        <input class="ad-input" id="country" name="country" value="{{ old('country', $user->country) }}" maxlength="2" aria-describedby="country-help" autocomplete="country">
                    </x-admin.field>
                    <x-admin.field name="description" type="textarea" :label="__('About')" :value="$user->description" />
                </div>
            </section>

            <x-admin.savebar form="ad-user-form" />
        </form>

        <aside class="ad-record-side" aria-label="{{ __('About this member') }}">
            <section class="ad-panel">
                <div class="ad-panel-h">
                    <span class="ad-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr((string) $user->username, 0, 2)) }}</span>
                    <b class="ad-home-grow">{{ $user->username }}</b>
                    <x-admin.status :type="$tone">{{ $self ? __('You') : $state }}</x-admin.status>
                </div>
                <dl class="ad-facts">
                    @foreach ($facts as $label => $value)
                        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>

            <section class="ad-panel" aria-labelledby="ad-user-videos">
                <div class="ad-panel-h">
                    <h2 id="ad-user-videos" class="ad-home-grow">{{ __('Latest videos') }}</h2>
                    @if ((int) $user->videos_count > 0)
                        <a class="ad-small" href="{{ route('admin.videos.index', ['user' => $user->id]) }}">{{ __('All their videos') }}</a>
                    @endif
                </div>
                @if ($user->videos->isEmpty())
                    <p class="ad-panel-b ad-muted">{{ __('No videos yet.') }}</p>
                @else
                    <ul class="ad-list">
                        @foreach ($user->videos as $video)
                            <li>
                                <span class="ad-li-text">
                                    <a href="{{ route('admin.videos.edit', $video) }}"><b>{{ \Illuminate\Support\Str::limit((string) $video->title, 60) }}</b></a>
                                    <span>{{ trans_choice('1 view|:count views', (int) $video->views, ['count' => number_format((int) $video->views)]) }} · {{ $video->created_at?->translatedFormat('j M Y') }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </aside>
    </div>
@endsection
