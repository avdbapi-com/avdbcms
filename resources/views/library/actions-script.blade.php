{{-- The small actions of the channel, playlist and library pages (member design 6.10, 6.11), printed once per page:
     - [data-share-dialog="#id"]: the device's share sheet on touch screens that have one, else that share dialog;
     - [data-copy-text]: copy (the clipboard, or a selection when the browser gives no clipboard access);
     - row menus: [data-share-url] or a row's [data-share-row] (the site's quick share; a row shares its title link's
       video) and [data-watch-later="<url>"] (its labels on the item or on the list of rows) with
       data-watch-later-state="on|off" (the state now): it asks for the other state (state=on|off, never a blind toggle),
       then the item says what is true. In Watch later ([data-watch-later-row]) the row leaves the page, and the head line,
       Play all, "Remove watched" and, after the last row, the empty state follow at once;
     - [data-rows-more] in a [data-rows-root data-rows-url data-next-page]: "Show more" rows of a playlist page.
     Every text it shows comes from the page or the server (translated there); a refusal shows the server's own words
     (a view-only demo account, 6.18), else the page's generic sentence, in the site's one toast system.
     The script itself is in resources/js/member/library-pages.js (one cached file for these pages: R1-069). --}}
@include('library.pages-script')
