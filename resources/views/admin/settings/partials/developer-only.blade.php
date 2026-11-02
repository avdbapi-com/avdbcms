{{--
    The developer settings of a page while Developer mode is off (SettingsPageController::form() developerOnly): each one
    by name, with what it does and that it appears in Developer mode, never its value or a control. A search link to
    one of them (#key) opens this fold and highlights its row; "Turn on Developer mode" on a row switches it on for this
    admin and comes back to that setting (admin.shell.preferences, anchor); the button of the note comes back to the
    page's first developer setting (#key: in Developer mode the settings sit in their own cards, and the page opens
    the fold that holds it). A view-only demo account is refused like every other change.
    Data: $developerOnly, $pageId.
--}}
@if (($developerOnly ?? []) !== [])
@php($devForm = 'ad-devmode-'.\Illuminate\Support\Str::slug($pageId))
@php($devCount = array_sum(array_map(fn (array $c): int => count($c['fields']), $developerOnly)))
@php($devFirst = (string) ($developerOnly[0]['fields'][0]['key'] ?? ''))
{{-- Its few rules here, so pages without developer settings carry none. Colours come from the admin tokens. --}}
<style>
.ad-devonly{margin-top:var(--s6,24px)}
.ad-devonly>summary{display:flex;align-items:center;gap:8px;padding:var(--s3,12px) 0;border-top:1px solid var(--border);font-weight:600;color:var(--text-2);list-style:none;cursor:pointer}
.ad-devonly>summary::-webkit-details-marker{display:none}
.ad-devonly-note{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:4px 0 12px;padding:var(--s3,12px) var(--s4,16px);border:1px solid var(--border);border-radius:var(--r-md);background:var(--surface-2)}
.ad-devonly-note p{margin:0;flex:1 1 260px;color:var(--text-2);font-size:var(--fs-sm)}
.ad-devonly .ad-field.is-locked .ad-label{color:var(--text-2)}
.ad-devonly .ad-field-meta button.ad-link{background:none;border:0;padding:0;font:inherit;font-weight:600;color:var(--accent-ink);text-decoration:underline;cursor:pointer}
.ad-devonly .ad-field.is-highlight,.ad-devonly .ad-field.is-target{background:var(--accent-soft)}
</style>
<form id="{{ $devForm }}" method="POST" action="{{ route('admin.shell.preferences') }}" hidden>
    @csrf
    <input type="hidden" name="key" value="developer_mode">
    <input type="hidden" name="value" value="1">
</form>
<details class="ad-adv ad-devonly" id="developer-settings" data-devonly>
<summary>{{ __('Settings for developers') }} <span class="ad-badge">{{ $devCount }}</span></summary>
<div class="ad-devonly-note">
<p>{{ __('These settings appear in Developer mode (account menu). A wrong value can break your site, so they stay hidden until you need them.') }}</p>
<button type="submit" form="{{ $devForm }}" class="ad-btn is-primary" name="anchor" value="{{ $devFirst !== '' ? $devFirst : 'developer-settings' }}">{{ __('Turn on Developer mode') }}</button>
</div>
@foreach ($developerOnly as $card)
<section class="ad-sec" aria-labelledby="devonly-{{ $card['id'] }}-title">
<div class="ad-sec-intro"><h2 id="devonly-{{ $card['id'] }}-title">{{ __($card['title']) }}</h2></div>
<div class="ad-sec-body">
@foreach ($card['fields'] as $f)
<div class="ad-field is-locked" id="{{ $f['key'] }}-row" data-locked>
<span class="ad-label" id="{{ $f['key'] }}">{{ __($f['label']) }}</span>
<div class="ad-help">{{ __($f['help']) }}</div>
<div class="ad-field-meta"><span>{{ __('This setting appears in Developer mode (account menu).') }}</span> <button type="submit" form="{{ $devForm }}" class="ad-link" name="anchor" value="{{ $f['key'] }}">{{ __('Turn on Developer mode') }}</button></div>
</div>
@endforeach
</div>
</section>
@endforeach
</details>
@endif
