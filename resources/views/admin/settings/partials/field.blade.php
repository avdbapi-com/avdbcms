{{--
  One setting row (config/admin_settings.php -> SettingsPageController::field()). Every control posts
  groups[group][key]. The row tells the page script its default (Reset), the consequence of risky switches and
  whether the value is write-only; the script reads the starting values from the controls themselves.
  Passwords and keys never print their stored value; long code boxes load their value after the page.
  Kept shallow on purpose: this partial repeats up to 60 times per page (80 KB page budget), so a default equal
  to the value is sent once (data-default-same) and "Default: ... Reset" is filled in by the script when needed.
--}}
@php
$id = $f['key'];
$error = $errors->first($f['group'].'.'.$f['key']);
$value = old('groups.'.$f['group'].'.'.$f['key'], $f['value']);
$lazy = in_array($f['control'], ['code', 'code_editor'], true) && ! $errors->any();
$isDefault = $f['default'] !== null && ! $lazy && ! $errors->any() && (string) $f['default'] === (string) $f['value'];
// "Default: ... Reset" under the field, except where the row has its own line (passwords, keys, code, switches, ...).
$meta = $f['default'] !== null && $f['default'] !== '' && ! $f['reveal'] && ! in_array($f['control'], ['secret', 'code', 'code_editor', 'toggle'], true)
  && ! in_array($f['key'], ['mail_mailer', 'indexnow_key', 'admin_ip_whitelist'], true);
$data = 'data-key="'.e($id).'" data-group="'.e($f['group']).'"'.($f['type'] === 'bool' ? ' data-type="bool"' : '').($f['control'] === 'size' ? ' data-size' : '').($meta ? ' data-meta' : '')
  .($f['default'] !== null ? ($isDefault ? ' data-default-same' : ' data-default="'.e($f['default']).'"') : '')
  .($f['confirm'] ? ' data-confirm="'.e($f['confirm']).'" data-confirm-when="'.e($f['confirm_when']).'" data-confirm-action="'.e(__($f['confirm_action'])).'"' : '')
  .($f['secret'] || $f['reveal'] ? ' data-write-only="1" data-saved="'.($f['saved'] ? '1' : '0').'"' : '')
  .($lazy ? ' data-lazy="1"' : '');
@endphp
@if ($f['control'] === 'toggle')
<label class="ad-toggle-row ad-field{{ $error ? ' is-error' : '' }}" id="{{ $id }}-row" for="{{ $id }}" {!! $data !!}>
<span class="ad-toggle-text"><b>{{ __($f['label']) }}</b><span class="ad-help" id="{{ $id }}-help">{{ __($f['help']) }}</span>@if ($error)<span class="ad-error" role="alert" data-error>{{ $error }}</span>@endif</span>
<input type="checkbox" class="ad-sr" id="{{ $id }}" name="{{ $f['name'] }}" value="1" role="switch" aria-describedby="{{ $id }}-help" @checked($value === '1')>
<span class="ad-switch{{ $value === '1' ? ' is-on' : '' }}" aria-hidden="true"></span>
</label>
@else
<div class="ad-field{{ $error ? ' is-error' : '' }}" id="{{ $id }}-row" {!! $data !!}>
@if (in_array($f['control'], ['segmented', 'radio_cards'], true))
<span class="ad-label{{ ($soleField ?? false) ? ' ad-sr' : '' }}" id="{{ $id }}-label">{{ __($f['label']) }}</span>
@else
<label class="ad-label{{ ($soleField ?? false) ? ' ad-sr' : '' }}" for="{{ $id }}">{{ __($f['label']) }}</label>
@endif
<div class="ad-help" id="{{ $id }}-help">{{ __($f['help']) }}</div>
<div class="ad-control">
@switch($f['control'])
@case('secret')
<div class="ad-row">
<span class="ad-badge{{ $f['saved'] ? ' is-ok' : '' }}" data-secret-status>{{ $f['saved'] ? __('Saved') : __('Not set') }}</span>
<span class="ad-pw" data-secret-input @if ($f['saved']) hidden @endif><input type="password" class="ad-input" id="{{ $id }}" name="{{ $f['name'] }}" value="" autocomplete="new-password" spellcheck="false" aria-describedby="{{ $id }}-help"></span>
<button type="button" class="ad-btn sm" data-secret-replace @unless ($f['saved']) hidden @endunless>{{ __('Replace') }}</button> <button type="button" class="ad-btn sm is-ghost" data-secret-clear @unless ($f['saved']) hidden @endunless>{{ __('Remove') }}</button>
</div>
@break
@case('textarea')
@case('link_list')
@case('code')
@case('code_editor')
<textarea class="ad-textarea{{ $f['control'] !== 'textarea' ? ' ad-mono' : '' }}" id="{{ $id }}" name="{{ $f['name'] }}" rows="{{ $f['control'] === 'textarea' ? 3 : 5 }}" spellcheck="{{ $f['control'] === 'textarea' ? 'true' : 'false' }}" aria-describedby="{{ $id }}-help"@if ($lazy) readonly placeholder="{{ __('Loading...') }}"@endif>{{ $lazy ? '' : $value }}</textarea>
@break
@case('select')
<select class="ad-select" id="{{ $id }}" name="{{ $f['name'] }}" aria-describedby="{{ $id }}-help">
@unless (array_key_exists($value, $f['options']))<option value="{{ $value }}" selected>{{ $value === '' ? __('Not set') : $value }}</option>@endunless
@foreach ($f['options'] as $optionValue => $optionLabel)
@if ((string) $optionValue === $optionLabel)<option @selected($optionLabel === (string) $value)>{{ $optionLabel }}</option>@else<option value="{{ $optionValue }}" @selected((string) $optionValue === (string) $value)>{{ __($optionLabel) }}</option>@endif
@endforeach
</select>
@break
@case('segmented')
<div class="ad-seg" role="radiogroup" aria-labelledby="{{ $id }}-label" aria-describedby="{{ $id }}-help">
@foreach ($f['options'] as $optionValue => $optionLabel)
<label @class(['is-active' => (string) $optionValue === (string) $value])><input type="radio" class="ad-sr" name="{{ $f['name'] }}" value="{{ $optionValue }}" @if ($loop->first) id="{{ $id }}" @endif @checked((string) $optionValue === (string) $value)>{{ __($optionLabel) }}</label>
@endforeach
</div>
@break
@case('radio_cards')
<div class="ad-radio-list" role="radiogroup" aria-labelledby="{{ $id }}-label">
@php($known = array_key_exists($value, $f['options']))
@foreach ($f['options'] as $optionValue => $optionLabel)
<label class="ad-rc{{ (string) $optionValue === (string) $value ? ' is-on' : '' }}"><input type="radio" class="ad-sr" name="{{ $f['name'] }}" value="{{ $optionValue }}" @if ($loop->first) id="{{ $id }}" @endif @checked((string) $optionValue === (string) $value)>
<b>{{ __($optionLabel) }}@if ($loop->first && $f['key'] === 'mail_mailer') <span class="ad-badge is-ok">{{ __('Recommended') }}</span>@endif</b>@isset($f['option_help'][$optionValue])<span>{{ __($f['option_help'][$optionValue]) }}</span>@endisset</label>
@endforeach
@if ($f['more_options'])
<label class="ad-rc{{ $known ? '' : ' is-on' }}"><input type="radio" class="ad-sr" name="{{ $f['name'] }}" value="{{ $known ? array_key_first($f['more_options']) : $value }}" data-more-radio @checked(! $known)>
<b>{{ __('Other services') }}</b><span><select class="ad-select" data-more-select aria-label="{{ __('Other services') }}">@foreach ($f['more_options'] as $service => $serviceLabel)<option value="{{ $service }}" @selected($service === $value)>{{ __($serviceLabel) }}</option>@endforeach</select></span></label>
@endif
</div>
@break
@case('upload')
{{-- Pick a picture (stored at once by POST /admin/settings/upload, used after Save) or type its address. --}}
<div class="ad-upload" data-upload>
<img src="{{ $value !== '' ? site_asset_url($value) : '' }}" alt="" width="120" height="63" data-upload-preview @if ($value === '') hidden @endif>
<div class="ad-upload-body">
<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" aria-label="{{ __('Choose a picture') }}" aria-describedby="{{ $id }}-help" data-upload-file>
<details><summary class="ad-small">{{ __('Use a link instead') }}</summary><input type="text" class="ad-input" id="{{ $id }}" name="{{ $f['name'] }}" value="{{ $value }}" placeholder="https://" spellcheck="false" aria-describedby="{{ $id }}-help" @if ($f['max'] !== null) maxlength="{{ $f['max'] }}" @endif></details>
</div>
</div>
@break
@case('number')
<div class="ad-input-group"><input type="number" class="ad-input" id="{{ $id }}" name="{{ $f['name'] }}" value="{{ $value }}" aria-describedby="{{ $id }}-help" @required($f['required']) @if ($f['min'] !== null) min="{{ $f['min'] }}" @endif @if ($f['max'] !== null) max="{{ $f['max'] }}" @endif>@if ($f['suffix'])<span class="ad-suffix">{{ __($f['suffix']) }}</span>@endif</div>
@break
@case('size')
{{-- Stored in bytes, typed in MB or GB: the page script sends "500 MB"; without it the unit comes as units[group][key]. --}}
@php([$sizeNumber, $sizeUnit] = \App\Http\Controllers\Admin\SettingsPageController::sizeParts($value, old('units.'.$f['group'].'.'.$f['key'])))
<div class="ad-input-group ad-size"><input type="number" class="ad-input" id="{{ $id }}" name="{{ $f['name'] }}" value="{{ $sizeNumber }}" min="0" step="any" inputmode="decimal" aria-describedby="{{ $id }}-help" @required($f['required'])><select class="ad-select" name="units[{{ $f['group'] }}][{{ $f['key'] }}]" aria-label="{{ __('Unit') }}" data-size-unit>@foreach (['MB', 'GB'] as $sizeOption)<option value="{{ $sizeOption }}" @selected($sizeOption === $sizeUnit)>{{ $sizeOption }}</option>@endforeach</select></div>
@break
@default
<input type="{{ in_array($f['control'], ['email', 'url'], true) ? $f['control'] : 'text' }}" class="ad-input" id="{{ $id }}" name="{{ $f['name'] }}" value="{{ $f['reveal'] ? '' : $value }}" aria-describedby="{{ $id }}-help" @required($f['required'] && ! $f['reveal']) @if ($f['max'] !== null) maxlength="{{ $f['max'] }}" @endif @if ($f['reveal']) autocomplete="off" spellcheck="false" placeholder="{{ __('Type a new secret word to replace the link') }}" @endif>
@endswitch
</div>
@if ($f['reveal'])
<div class="ad-field-meta"><span data-reveal-text>{{ $f['saved'] ? __('A secret link is saved. It is hidden until you type your password.') : __('No secret link yet.') }}</span> <button type="button" class="ad-link" data-reveal-open @unless ($f['saved']) hidden @endunless>{{ __('Show and copy the link') }}</button></div>
<div class="ad-row" data-reveal-form hidden><input type="password" class="ad-input" autocomplete="current-password" aria-label="{{ __('Your admin password') }}" placeholder="{{ __('Your admin password') }}" style="max-width:260px"> <button type="button" class="ad-btn sm" data-reveal-submit>{{ __('Show link') }}</button></div>
<div class="ad-row" data-reveal-result hidden><input type="text" class="ad-input ad-mono" readonly aria-label="{{ __('Secret preview link') }}"> <button type="button" class="ad-btn sm" data-copy>{{ __('Copy bypass link') }}</button></div>
@elseif ($f['secret'])
<div class="ad-field-meta"><span>{{ __('Stored safely. It is never shown again after saving.') }}</span></div>
@elseif ($f['key'] === 'indexnow_key')
<div class="ad-field-meta"><span>{{ __('This key is public: search engines read it from your site.') }}</span>@if ($f['value'] !== '') <a href="{{ url('/'.$f['value'].'.txt') }}" target="_blank" rel="noopener">{{ __('Verify') }}</a>@endif</div>
@elseif ($f['key'] === 'admin_ip_whitelist')
<div class="ad-field-meta"><span>{{ __('Your address is :ip. When the list is not empty, saving adds your address to it so you keep access.', ['ip' => $clientIp ?? '-']) }}</span></div>
@elseif ($f['key'] === 'mail_mailer')
<div class="ad-field-meta"><span data-saved-now data-labels="{{ json_encode(array_map(fn (string $label): string => __($label), $f['options'] + $f['more_options'])) }}">{{ __('Saved now: :value', ['value' => __($f['options'][$value] ?? $f['more_options'][$value] ?? $value)]) }}</span></div>
@elseif ($meta && (string) $f['default'] !== (string) $value)
<div class="ad-field-meta" data-default-line><span>{{ __('Default: :value', ['value' => __($f['options'][$f['default']] ?? \Illuminate\Support\Str::limit($f['default'], 60))]) }}</span> <button type="button" class="ad-link" data-reset>{{ __('Reset') }}</button></div>
@endif
@if ($error)<div class="ad-error" role="alert" data-error>{{ $error }}</div>@endif
</div>
@endif
