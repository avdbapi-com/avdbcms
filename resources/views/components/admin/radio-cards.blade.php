{{--
    Radio cards for choices that need a sentence each (how emails are sent, effects level).
    <x-admin.radio-cards name="mail_mailer" :value="$mailer" list :options="[
        'smtp' => ['label' => 'Email server (SMTP)', 'help' => 'Your email provider sends them.', 'badge' => 'Recommended'],
        'log' => ['label' => 'Log only (testing)', 'help' => 'Nothing is delivered.'],
    ]" />
    Props: name, options (value => label | [label, help, badge]), value, list (one card per row), label (group name).
--}}
@props(['name', 'options' => [], 'value' => null, 'list' => false, 'label' => null])
<div {{ $attributes->class(['ad-radio-cards', 'is-list' => $list]) }} role="radiogroup" @if($label) aria-label="{{ $label }}" @endif data-ad-field="{{ $name }}">
    @foreach ($options as $optionValue => $option)
        @php($option = is_array($option) ? $option : ['label' => $option])
        <label class="ad-rc">
            <input type="radio" name="{{ $name }}" value="{{ $optionValue }}" @checked((string) old($name, $value) === (string) $optionValue)>
            <b>{{ $option['label'] }}@if(! empty($option['badge'])) <x-admin.badge type="ok">{{ $option['badge'] }}</x-admin.badge>@endif</b>
            @if (! empty($option['help']))
                <span>{{ $option['help'] }}</span>
            @endif
        </label>
    @endforeach
</div>
