{{--
    Site status pill: green "Site is live" only when the site is open, listed by Google and sends email;
    otherwise amber with the first problem. Opens a list of every check with a link to fix it.
    Props: status (App\Support\AdminNav::siteStatus()).
--}}
@props(['status' => null])
@php($status ??= \App\Support\AdminNav::siteStatus())
<div class="ad-popwrap">
    <button type="button" @class(['ad-status', 'is-warn' => ! $status['ok']]) data-ad-menu aria-controls="ad-status-menu" aria-expanded="false" aria-haspopup="true">
        <b aria-hidden="true"></b><span class="ad-status-t">{{ __($status['label']) }}</span>
        <span class="ad-vh">{{ __('Site status') }}</span>
    </button>
    <div class="ad-menu ad-status-pop" id="ad-status-menu" hidden>
        <ul>
            @foreach ($status['checks'] as $check)
                <li>
                    <span @class(['ad-state', 'is-ok' => $check['ok'], 'is-warn' => ! $check['ok']]) aria-hidden="true"></span>
                    <span>
                        {{ __($check['label']) }}
                        @if (! $check['ok'] && $check['url'])
                            <br><a href="{{ $check['url'] }}">{{ __('Fix this') }}</a>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
</div>
