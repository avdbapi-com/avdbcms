{{-- Subscribers (member design 6.8): who follows the channel, newest first. Kit rows: avatar 40, name,
     @handle, their own subscriber count (hidden when the site or the member hides it), "Subscribed 5 hours ago", and
     Subscribe back / Subscribed (mk-btn--fixed: the same width in both states, so the row never moves). The button is
     server-rendered and toggles through the site's subscribe API (the same route Subscribe.tsx calls); no component
     mounts over it. Filters: search, date; per_page_users (15). --}}
@extends('layouts.user')

@section('title', __('Subscribers'))

@section('content')
@php
    use App\Support\NumberFormat;
    use App\Support\Studio\StudioFormat;

    $rows = $subscriptions->getCollection();
    $value = fn (string $key) => is_string(request()->query($key)) ? request()->query($key) : '';
    $desc = $total === 0
        ? __('Nobody follows your channel yet.')
        : trans_choice(':count person follows your channel.|:count people follow your channel.', $total, ['count' => StudioFormat::number($total)])
            .($recent > 0 ? ' '.trans_choice(':count joined in the last 28 days.|:count joined in the last 28 days.', $recent, ['count' => StudioFormat::number($recent)]) : '');
    $me = auth()->user();
@endphp
<div class="avd-mk mk-page">
    <x-mk.head icon="users" :kicker="__('Studio')" :title="__('Subscribers')" :desc="$desc" />
    <x-mk.flash refusal />

    @if($total === 0)
        <section class="mk-panel">
            <x-mk.empty icon="users" :title="__('No subscribers yet')" :text="__('People who subscribe to your channel appear here. New videos bring new subscribers.')">
                @if($uploadsAllowed)
                    <button type="button" class="mk-btn mk-btn--primary" data-bs-toggle="modal" data-bs-target="#video_create"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i><span>{{ __('Upload a video') }}</span></button>
                @endif
            </x-mk.empty>
        </section>
    @else
        <section class="mk-panel" aria-label="{{ __('Subscribers') }}">
            @include('users.partials.filters', [
                'id' => 'subscribers',
                'action' => route('user.subscribers'),
                'hidden' => [],
                'search' => ['name' => 'search', 'value' => $value('search'), 'label' => __('Search subscribers'), 'placeholder' => __('Search names')],
                'selects' => [
                    ['name' => 'date', 'label' => __('Date'), 'options' => ['' => __('Any date'), '7' => __('Last 7 days'), '28' => __('Last 28 days'), '90' => __('Last 90 days')], 'value' => $value('date'), 'fold' => false],
                ],
                'custom' => null,
                'clearUrl' => route('user.subscribers'),
                'active' => $filtersActive,
            ])
            @if($rows->isEmpty())
                <x-mk.empty icon="filter" :title="__('No subscribers match these filters')" :text="__('Try other words or another filter.')" compact>
                    <a class="mk-btn mk-btn--secondary" href="{{ route('user.subscribers') }}">{{ __('Clear filters') }}</a>
                </x-mk.empty>
            @else
                <ul class="mk-rows">
                    @foreach($rows as $subscription)
                        @php($member = $subscription->subscriber)
                        <li class="mk-item">
                            @if($member)
                                <img class="mk-avatar mk-avatar--40" src="{{ $member->avatar_url }}" alt="" width="40" height="40" @if($loop->index >= 6) loading="lazy" @endif decoding="async">
                                <div class="mk-item__body">
                                    <a class="mk-item__title mk-clamp-1" href="{{ $member->route }}" dir="auto">{{ $member->username }}</a>
                                    <div class="mk-row__meta">
                                        @if($member->slug)<span>{{ '@'.$member->slug }}</span>@endif
                                        @if($showSubscriberCounts && ($member->show_subscribers ?? true))
                                            <span>{{ (int) $member->subscribers_count === 0 ? __('No subscribers yet') : trans_choice(':count subscriber|:count subscribers', (int) $member->subscribers_count, ['count' => NumberFormat::abbreviate((int) $member->subscribers_count)]) }}</span>
                                        @endif
                                        <span>{{ __('Subscribed :when', ['when' => StudioFormat::ago($subscription->subscribe_at)]) }}</span>
                                    </div>
                                </div>
                                <div class="mk-item__aside">
                                    @if($me && (int) $member->id !== (int) $me->id)
                                        @php($following = (bool) $member->is_current_user_subscribe)
                                        <button type="button" @class(['mk-btn', 'mk-btn--sm', 'mk-btn--fixed', 'mk-btn--quiet' => $following, 'mk-btn--secondary' => ! $following])
                                                data-mk-subscribe="{{ route('subscribe', $member) }}" aria-pressed="{{ $following ? 'true' : 'false' }}"
                                                aria-label="{{ $following ? __('Subscribed to :name', ['name' => $member->username]) : __('Subscribe back to :name', ['name' => $member->username]) }}">
                                            <i @class(['fa-solid', 'fa-check' => $following, 'd-none' => ! $following]) aria-hidden="true"></i><span>{{ $following ? __('Subscribed') : __('Subscribe back') }}</span>
                                        </button>
                                    @endif
                                </div>
                            @else
                                <span class="mk-avatar mk-avatar--40" aria-hidden="true"></span>
                                <div class="mk-item__body">
                                    <span class="mk-item__title mk-muted">{{ __('Deleted member') }}</span>
                                    <div class="mk-row__meta"><span>{{ __('Subscribed :when', ['when' => StudioFormat::ago($subscription->subscribe_at)]) }}</span></div>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            {{ $subscriptions->onEachSide(1)->links('vendor.pagination.mk') }}
        </section>
    @endif
    @stack('mk-overlays')
</div>
@endsection

@push('scripts')
    @include('users.partials.studio-scripts', ['studioScriptsParts' => []])
    {{-- sent without its comment lines and indentation (1.2.3 round 1, R1-069) --}}
    @php(ob_start())
    <script>
        (function () {
            // Subscribe back / Subscribed: one fixed width in both states; the server toggles the subscription.
            var texts = {
                on: @json(__('Subscribed')),
                off: @json(__('Subscribe back')),
                failed: @json(__('That did not work. Try again in a moment.'))
            };
            var token = document.querySelector('meta[name="csrf-token"]');

            document.addEventListener('click', function (event) {
                var button = event.target.closest ? event.target.closest('[data-mk-subscribe]') : null;
                if (!button || button.getAttribute('aria-busy') === 'true') return;
                event.preventDefault();
                button.setAttribute('aria-busy', 'true');
                fetch(button.getAttribute('data-mk-subscribe'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
                        'X-Locale': window.LANG || document.documentElement.lang || 'en'
                    }
                }).then(function (response) {
                    if (!response.ok) {
                        return response.json().catch(function () { return {}; }).then(function (data) {
                            throw new Error((data && data.message) || texts.failed);
                        });
                    }
                    var on = button.getAttribute('aria-pressed') !== 'true';
                    button.setAttribute('aria-pressed', on ? 'true' : 'false');
                    button.classList.toggle('mk-btn--quiet', on);
                    button.classList.toggle('mk-btn--secondary', !on);
                    var icon = button.querySelector('i');
                    if (icon) { icon.classList.toggle('fa-check', on); icon.classList.toggle('d-none', !on); }
                    var label = button.querySelector('span');
                    if (label) label.textContent = on ? texts.on : texts.off;
                    button.removeAttribute('aria-label');
                }).catch(function (error) {
                    if (window.avdMkToast) window.avdMkToast(error && error.message ? error.message : texts.failed, 'error');
                }).finally(function () {
                    button.removeAttribute('aria-busy');
                });
            });
        })();
    </script>
    {!! \App\Support\Studio\StudioHtml::js((string) ob_get_clean()) !!}
@endpush
