{{-- Studio list filters (member design 5.6, 6.3, 6.8): the member copy of the filter form (the shared
     forms/filter_form.blade.php stays for the v1 admin). A toolbar (a GET form): search + native selects
     (data-native-select, 5.9; a category list over 30 entries is the only TomSelect) that apply when they change.
     Selects marked 'fold' fold into one "Filters" button below 960px of toolbar width (the kit's container query): it
     opens a side sheet (a bottom sheet on phones) with the same fields. A Date select with a "custom" choice opens the
     Custom dates dialog on desktop and shows its two fields inside the sheet; closing the dialog without applying it
     (Cancel, Escape, its close button) puts the select back on the dates the list shows (1.2.3 round 1, R1-106) and the
     focus back on the select, its opener (or on "Filters" if the select folded away meanwhile: 1.2.3 round 3, studio
     script 1c).
     @include('users.partials.filters', [
         'id' => 'videos', 'action' => url, 'hidden' => ['status' => '0'],
         'search' => ['name' => 'search', 'value' => ..., 'label' => ..., 'placeholder' => ...] | null,
         'selects' => [['name', 'label', 'options' => [value => label], 'value', 'native' => true, 'fold' => true, 'seg' => false, 'custom' => false]],
         'custom' => ['from' => ..., 'to' => ...] | null,
         'clearUrl' => url, 'active' => bool, 'submitLabel' => __('Show videos'),
         'links' => ['label' => __('Show'), 'items' => [['label' => ..., 'url' => ..., 'current' => bool]]] | null,
     ])
     'links' prints a row of filter links first (Activity: All, Likes, Dislikes, Comments, Replies): plain links, so
     they work without JavaScript and keep the other filters in their address. --}}
@php
    $mkFid = 'mkf-'.$id;
    $mkHidden = array_filter($hidden ?? [], fn ($value) => $value !== null && $value !== '');
    $mkFolds = collect($selects ?? [])->contains(fn ($select) => ! empty($select['fold']));
    $mkCustom = $custom ?? null;
    $mkCustomSelect = collect($selects ?? [])->first(fn ($select) => ! empty($select['custom']));
@endphp
<form class="mk-toolbar" method="GET" action="{{ $action }}" role="search" id="{{ $mkFid }}-bar"
      x-data="{ submit(select) { if (select.dataset.custom && select.value === 'custom') { const dialog = document.getElementById('{{ $mkFid }}-dates'); if (dialog && window.bootstrap && window.matchMedia('(min-width: 576px)').matches) { dialog.addEventListener('hidden.bs.modal', () => { Array.from(select.options).forEach(option => { option.selected = option.defaultSelected; }); }, { once: true }); window.bootstrap.Modal.getOrCreateInstance(dialog).show(select); return; } } select.form.requestSubmit ? select.form.requestSubmit() : select.form.submit(); } }">
    @foreach($mkHidden as $mkName => $mkValue)
        <input type="hidden" name="{{ $mkName }}" value="{{ $mkValue }}">
    @endforeach
    @if(! empty($links['items']))
        <div class="mk-seg" role="group" aria-label="{{ $links['label'] ?? __('Show') }}">
            @foreach($links['items'] as $mkLink)
                <a class="mk-seg__item" href="{{ $mkLink['url'] }}" @if(! empty($mkLink['current'])) aria-current="true" @endif>{{ $mkLink['label'] }}</a>
            @endforeach
        </div>
    @endif
    @if(! empty($search))
        <label class="mk-search">
            <span class="mk-sr">{{ $search['label'] }}</span>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input class="mk-input" type="search" name="{{ $search['name'] }}" value="{{ $search['value'] }}" placeholder="{{ $search['placeholder'] ?? $search['label'] }}" maxlength="120" enterkeyhint="search">
        </label>
    @endif
    @foreach($selects ?? [] as $mkSelect)
        @continue(! empty($mkSelect['sheetOnly']))
        <select @class(['form-select', 'mk-toolbar__filter' => ! empty($mkSelect['fold'])]) name="{{ $mkSelect['name'] }}" style="inline-size:auto" aria-label="{{ $mkSelect['label'] }}"
                @if($mkSelect['native'] ?? true) data-native-select @endif
                @if(! empty($mkSelect['custom'])) data-custom="1" @endif
                @change="submit($el)">
            @foreach($mkSelect['options'] as $mkValue => $mkLabel)
                <option value="{{ $mkValue }}" @selected((string) ($mkSelect['value'] ?? '') === (string) $mkValue)>{{ $mkLabel }}</option>
            @endforeach
        </select>
    @endforeach
    @if($mkCustom && $mkCustomSelect && ($mkCustomSelect['value'] ?? '') === 'custom')
        <input type="hidden" name="from" value="{{ $mkCustom['from'] }}">
        <input type="hidden" name="to" value="{{ $mkCustom['to'] }}">
    @endif
    @if($mkFolds)
        <button class="mk-btn mk-btn--secondary mk-toolbar__more" type="button" data-bs-toggle="offcanvas" data-bs-target="#{{ $mkFid }}-sheet" aria-controls="{{ $mkFid }}-sheet">
            <i class="fa-solid fa-sliders" aria-hidden="true"></i><span>{{ __('Filters') }}</span>
        </button>
    @endif
    @if(! empty($active))
        <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ $clearUrl }}">{{ __('Clear filters') }}</a>
    @endif
</form>

@if($mkFolds)
    @push('mk-overlays')
        <div class="offcanvas offcanvas-end mk-drawer avd-mk" tabindex="-1" id="{{ $mkFid }}-sheet" aria-labelledby="{{ $mkFid }}-sheet-title">
            <span class="mk-sheet__grip" aria-hidden="true"></span>
            <div class="mk-sheet__head">
                <h2 class="mk-sheet__title" id="{{ $mkFid }}-sheet-title">{{ __('Filters') }}</h2>
                <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="offcanvas" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <form class="mk-sheet__body" method="GET" action="{{ $action }}" id="{{ $mkFid }}-sheet-form"
                  x-data="{ custom: @js(($mkCustomSelect['value'] ?? '') === 'custom') }">
                @foreach($mkHidden as $mkName => $mkValue)
                    <input type="hidden" name="{{ $mkName }}" value="{{ $mkValue }}">
                @endforeach
                @if(! empty($search) && $search['value'] !== '')
                    <input type="hidden" name="{{ $search['name'] }}" value="{{ $search['value'] }}">
                @endif
                <div class="mk-form">
                    @foreach($selects ?? [] as $mkSelect)
                        @if(! empty($mkSelect['seg']))
                            <div class="mk-field">
                                <span class="mk-label" id="{{ $mkFid }}-{{ $mkSelect['name'] }}-label">{{ $mkSelect['label'] }}</span>
                                <div class="mk-seg mk-seg--block" role="radiogroup" aria-labelledby="{{ $mkFid }}-{{ $mkSelect['name'] }}-label">
                                    @foreach($mkSelect['segOptions'] ?? $mkSelect['options'] as $mkValue => $mkLabel)
                                        <label class="mk-seg__item"><input type="radio" name="{{ $mkSelect['name'] }}" value="{{ $mkValue }}" @checked((string) ($mkSelect['value'] ?? '') === (string) $mkValue)><span class="mk-seg__label">{{ $mkLabel }}</span></label>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="mk-field">
                                <label class="mk-label" for="{{ $mkFid }}-{{ $mkSelect['name'] }}">{{ $mkSelect['sheetLabel'] ?? $mkSelect['label'] }}</label>
                                <select class="form-select" id="{{ $mkFid }}-{{ $mkSelect['name'] }}" name="{{ $mkSelect['name'] }}"
                                        @if($mkSelect['native'] ?? true) data-native-select @endif
                                        @if(! empty($mkSelect['custom'])) @change="custom = $el.value === 'custom'" @endif>
                                    @foreach($mkSelect['options'] as $mkValue => $mkLabel)
                                        <option value="{{ $mkValue }}" @selected((string) ($mkSelect['value'] ?? '') === (string) $mkValue)>{{ $mkLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if(! empty($mkSelect['custom']) && $mkCustom)
                                <div class="mk-form__row mk-form__row--2" x-show="custom" x-cloak @if(($mkSelect['value'] ?? '') !== 'custom') style="display:none" @endif>
                                    <div class="mk-field">
                                        <label class="mk-label" for="{{ $mkFid }}-from">{{ __('Start date') }}</label>
                                        <input class="mk-input" type="date" id="{{ $mkFid }}-from" name="from" value="{{ $mkCustom['from'] }}" max="{{ $mkCustom['max'] }}" :disabled="!custom">
                                    </div>
                                    <div class="mk-field">
                                        <label class="mk-label" for="{{ $mkFid }}-to">{{ __('End date') }}</label>
                                        <input class="mk-input" type="date" id="{{ $mkFid }}-to" name="to" value="{{ $mkCustom['to'] }}" max="{{ $mkCustom['max'] }}" :disabled="!custom">
                                    </div>
                                </div>
                            @endif
                        @endif
                    @endforeach
                </div>
            </form>
            <div class="mk-sheet__foot">
                <a class="mk-btn mk-btn--quiet" href="{{ $clearUrl }}">{{ __('Clear filters') }}</a>
                <button type="submit" class="mk-btn mk-btn--primary" form="{{ $mkFid }}-sheet-form">{{ $submitLabel ?? __('Show results') }}</button>
            </div>
        </div>
    @endpush
@endif

@if($mkCustom && $mkCustomSelect)
    @push('mk-overlays')
        <div class="modal fade" id="{{ $mkFid }}-dates" tabindex="-1" aria-labelledby="{{ $mkFid }}-dates-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mk-dialog">
                <div class="modal-content avd-mk">
                    <div class="mk-sheet__head">
                        <h2 class="mk-sheet__title" id="{{ $mkFid }}-dates-title">{{ __('Custom dates') }}</h2>
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <form class="mk-sheet__body" method="GET" action="{{ $action }}" id="{{ $mkFid }}-dates-form">
                        @foreach($mkHidden as $mkName => $mkValue)
                            <input type="hidden" name="{{ $mkName }}" value="{{ $mkValue }}">
                        @endforeach
                        @if(! empty($search) && $search['value'] !== '')
                            <input type="hidden" name="{{ $search['name'] }}" value="{{ $search['value'] }}">
                        @endif
                        @foreach($selects ?? [] as $mkSelect)
                            @continue(! empty($mkSelect['custom']) || (string) ($mkSelect['value'] ?? '') === '')
                            <input type="hidden" name="{{ $mkSelect['name'] }}" value="{{ $mkSelect['value'] }}">
                        @endforeach
                        <input type="hidden" name="{{ $mkCustomSelect['name'] }}" value="custom">
                        <div class="mk-form">
                            <div class="mk-form__row mk-form__row--2">
                                <div class="mk-field">
                                    <label class="mk-label" for="{{ $mkFid }}-dates-from">{{ __('Start date') }}</label>
                                    <input class="mk-input" type="date" id="{{ $mkFid }}-dates-from" name="from" value="{{ $mkCustom['from'] }}" max="{{ $mkCustom['max'] }}" required>
                                </div>
                                <div class="mk-field">
                                    <label class="mk-label" for="{{ $mkFid }}-dates-to">{{ __('End date') }}</label>
                                    <input class="mk-input" type="date" id="{{ $mkFid }}-dates-to" name="to" value="{{ $mkCustom['to'] }}" max="{{ $mkCustom['max'] }}" required>
                                </div>
                            </div>
                            <p class="mk-help">{{ __('Dates use the site\'s time zone (:city). Both days are included.', ['city' => \App\Support\Studio\ZoneLabel::city()]) }}</p>
                        </div>
                    </form>
                    <div class="mk-sheet__foot">
                        <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="mk-btn mk-btn--primary" form="{{ $mkFid }}-dates-form">{{ __('Apply dates') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endpush
@endif
