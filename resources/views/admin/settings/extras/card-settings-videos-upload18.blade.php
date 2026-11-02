{{--
    Settings > Videos & uploads > Upload18 connection: what the saved key may do, as Upload18's GET /keyinfo says
    (App\Support\Upload18\KeyInfo::forCard(), never showing the key). The page never waits for Upload18 once a key was
    checked: it shows the last answer and asks again after the page was sent (about once a day); only a key never checked
    before is asked while the page is made (4 seconds at most), so a newly saved key shows its check at once. A warning
    when it is a master key, can edit or delete, cannot upload or read, is an upload token, or is refused; a short
    "checked" line for a restricted Upload + Read key. Nothing when no key is saved or Upload18 could not be asked.
--}}
@php
    $u18Service = app(\App\Services\Upload18Service::class);
    $u18Info = $u18Service->hasKey() ? \App\Support\Upload18\KeyInfo::forCard($u18Service) : null;
    $u18Problems = $u18Info?->problems() ?? [];
    $u18Texts = [
        'invalid' => __('Upload18 does not accept this key. Member uploads stop until you paste a working key.'),
        'master' => __('This is your Upload18 master key. Create a restricted key with Upload + Read for this site and paste it here instead.'),
        'token' => __('This is a short-lived upload token, not a key. Paste a restricted key with Upload + Read.'),
        'edit_delete' => __('This key can also edit or delete your Upload18 videos. Use a restricted key with only Upload + Read for this site.'),
        'no_upload' => __('This key cannot upload videos. Turn on Upload for it in Upload18 > API > Keys & security.'),
        'no_read' => __('This key cannot read your video list, so the site cannot see when an upload is ready. Turn on Read for it in Upload18 > API > Keys & security.'),
    ];
@endphp
@if ($u18Problems !== [])
    <x-admin.callout type="warn" :title="__('Check your Upload18 key')" data-upload18-key-warning>{{ implode(' ', array_filter(array_map(fn (string $problem): string => $u18Texts[$problem] ?? '', $u18Problems))) }}</x-admin.callout>
@elseif ($u18Info?->isRecommended())
    <x-admin.callout type="ok" data-upload18-key-ok>{{ __("Key checked: a restricted key with Upload + Read. Members' browsers only get short-lived upload tokens.") }}</x-admin.callout>
@endif
