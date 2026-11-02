{{-- One row of the notifications page (users/notifications/index.blade.php): written without indentation, so a page of
     rows carries its content, not Blade's whitespace (member design 4.3 page-content budget). The row = the link
     (avatar with its type badge in the same grid cell, or the type icon; the sentence with the names and titles in bold;
     the time, with a screen-reader "Unread" on unread rows; the video's picture) + its "..." button. The menu itself is
     printed ONCE for the page (index.blade.php), and moved next to the button that opens it (a copy of
     the menu in every row was half of a row's bytes). Addresses are written from the site's root path; a row with a
     link carries its id once, in the link's address (/profile/notifications/<id>/click: the page's script reads it
     there), a row without one in data-avd-notification. $row: a row of User\NotificationController::index();
     $kindIcons, $kindBadge, $rowMenuLabel, $rowUnread, $rowOrigin: the page's maps, words and address parts. --}}
@php
    $rowUrl = $row['url'] ? route('user.notifications.click', ['notification' => $row['id']], false) : null;
    $rowKind = (string) ($row['kind'] ?? 'other');
    $rowIcon = $kindIcons[$rowKind] ?? 'fa-bell';
    $rowBadge = (string) ($kindBadge[$rowKind] ?? '');
    $rowParts = is_array($row['parts'] ?? null) && $row['parts'] !== [] ? $row['parts'] : [['t' => (string) $row['message'], 'b' => false]];
    $rowTag = $rowUrl ? 'a' : 'div';
    $rowRead = (bool) $row['is_read'];
    $rowAvatar = (string) ($row['avatar'] ?? '');
    if ($rowOrigin !== '' && str_starts_with($rowAvatar, $rowOrigin.'/')) {
        $rowAvatar = substr($rowAvatar, strlen($rowOrigin));
    }
@endphp
<div @class(['mk-notif', 'is-unread' => ! $rowRead]) @unless($rowUrl) data-avd-notification="{{ $row['id'] }}" @endunless>
<{{ $rowTag }} class="mk-notif__link" @if($rowUrl) href="{{ $rowUrl }}" @endif>
@if($rowAvatar !== '')
<img class="mk-avatar" src="{{ $rowAvatar }}" alt="" width="40" height="40" loading="lazy"><i @class(['mk-notif__type fa-solid', $rowIcon, 'mk-notif__type--'.$rowBadge => $rowBadge !== '']) aria-hidden="true"></i>
@else
<i class="mk-notif__kind fa-solid {{ $rowIcon }}" aria-hidden="true"></i>
@endif
<span class="mk-notif__text">
@if(! empty($row['title']))
<strong class="mk-notif__title">{{ $row['title'] }}</strong>
@endif
<span>{!! collect($rowParts)->map(fn ($part) => ($part['b'] ?? false) ? '<strong>'.e((string) $part['t']).'</strong>' : e((string) $part['t']))->implode('') !!}</span>
<time datetime="{{ $row['iso'] }}">{{ $row['when'] }}</time>@unless($rowRead)<span class="mk-sr">, {{ $rowUnread }}</span>@endunless
</span>
@if(! empty($row['thumb']))
<img class="mk-notif__thumb" src="{{ $row['thumb'] }}" alt="" width="160" height="90" loading="lazy">
@endif
</{{ $rowTag }}>
<button type="button" class="mk-icon-btn mk-icon-btn--muted mk-notif__more" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ $rowMenuLabel }}" title="{{ $rowMenuLabel }}"></button>
</div>
