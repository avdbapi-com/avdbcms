<?php

/*
|--------------------------------------------------------------------------
| Member pages (member design 4.1a, 5.13; added by the member scaffold, 9.1 step 2)
|--------------------------------------------------------------------------
|
| components/layout.blade.php reads this file:
|
| - 'routes': the route-name patterns (Str::is) of the member pages: the studio and account (user.*), the sign-in
|   pages, the library pages, the channel page (user.show), /premium and the contact page. On these routes the layout
|   prints the member CSS entry (resources/sass/member.scss) for guests too; a signed-in visitor gets it on every page.
|   Never on the admin's pages (type="admin" layouts and admin.* routes).
|
| - 'marker': whether these routes print html[data-avd-member], the marker that switches on the member chrome rules
|   (the calm header and menus, the studio icon rail, the room for the studio tab bar and the member bars, the "Back to
|   top" and daily-pill places: resources/sass/member/_chrome.scss, _studio.scss, _kit.scss, _shared.scss). They restyle
|   the site's own header, menus and fixed controls; with the marker off (false) none of them applies, so nothing a
|   visitor, member or admin sees changes. It is on (true): every member page carries the marker.
*/

return [
    'marker' => true,

    'routes' => [
        'user.*',
        'login',
        'logout',
        'registration',
        'password.*',
        'verification.*',
        'phone.*',
        'pages.history',
        'pages.library',
        'pages.premium',
        'custom-playlist.*',
        'playlist.*',
        'subscription.*',
        'premium.*',
        'user.show',
        'contact.show',
    ],
];
