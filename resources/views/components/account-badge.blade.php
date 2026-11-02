{{--
    The badge under a member's name in the account panel, the back sidebar and the profile: what kind of account it is.
    A view-only demo account first (it can be an administrator too), then administrators (they upload with the premium
    limits), premium members, everyone else.
    <x-account-badge :user="auth()->user()" />
--}}
@props(['user'])
@php
    $accountKind = match (true) {
        \App\Http\Middleware\ViewOnlyDemo::isViewOnlyUser($user) => 'demo',
        (bool) ($user?->is_admin ?? false) => 'admin',
        (bool) ($user?->is_premium ?? false) => 'premium',
        default => 'standard',
    };
@endphp
@if($accountKind === 'demo')
    <div {{ $attributes->class(['badge bg-info align-self-start']) }}>
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
        <span>{{ __('View-only demo') }}</span>
    </div>
@elseif($accountKind === 'admin')
    <div {{ $attributes->class(['badge bg-primary align-self-start']) }}>
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        <span>{{ __('Administrator') }}</span>
    </div>
@elseif($accountKind === 'premium')
    <div {{ $attributes->class(['badge bg-warning align-self-start']) }}>
        <i class="fa-solid fa-star" aria-hidden="true"></i>
        <span>{{ __('Premium account') }}</span>
    </div>
@else
    <div {{ $attributes->class(['badge bg-secondary align-self-start']) }}>{{ __('Standard account') }}</div>
@endif
