{{--
    The messages at the top of an Account settings section (member design 6.9, 6.18):
    - a change the view-only demo account tried: "Not saved" and the server's own sentence, focused (the redirect brought
      the stored values back, so nothing on the page looks changed);
    - the one-time messages after a save (<x-mk.flash>: always escaped, account-22);
    - errors of fields this section does not show (a field's own error is printed under the field: account-38).
    $fields: the field names (patterns) the section prints its errors for.
--}}
@php
    $shownFields = $fields ?? [];
    $otherErrors = collect($errors->getMessages())
        ->reject(fn ($messages, $key) => $key === 'view_only_demo' || \Illuminate\Support\Str::is($shownFields, $key))
        ->flatten()
        ->unique()
        ->values();
@endphp
@if($errors->has('view_only_demo'))
    <div class="mk-alert" role="alert" tabindex="-1" x-init="$nextTick(() => $el.focus({ preventScroll: false }))" data-view-only-refusal>
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
        <div class="mk-alert__body">
            <span class="mk-alert__title">{{ __('Not saved') }}</span>
            <span>{{ $errors->first('view_only_demo') }}</span>
        </div>
        <span></span>
    </div>
@endif
<x-mk.flash />
@if($otherErrors->isNotEmpty())
    <div class="mk-alert mk-alert--danger" role="alert">
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <div class="mk-alert__body">
            @if($otherErrors->count() === 1)
                <span>{{ $otherErrors->first() }}</span>
            @else
                <span class="mk-alert__title">{{ __('Not saved') }}</span>
                <ul class="mk-alert__list">
                    @foreach($otherErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
        <span></span>
    </div>
@endif
