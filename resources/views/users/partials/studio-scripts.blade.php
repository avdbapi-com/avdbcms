{{-- Small shared behaviour of the studio pages (member design 5.7, 5.10, 5.11).

     1.2.3 round 1b (R1R-01): the script itself is resources/js/member/studio-pages.js, one cached file printed once per
     page by layouts/user.blade.php (users/partials/studio-script-tag.blade.php) instead of 4 to 12 KB of inline script
     in every studio page (member design 4.3). This partial only names the parts the page uses (pushed to the
     mk-studio-parts stack, which the script tag reads); what each part does is written below and in the script.

     1.2.3 round 1 (R1-069, R1-026): the script goes out without its comment lines and indentation (they stay here:
     App\Support\Studio\StudioHtml::js), and in parts: the CORE every studio page uses, CONFIRM (confirmations opened
     from a row: the Dashboard, Comments and its replies page, Playlists, Reports), LISTS (row menus, bulk ids, counted
     words: My videos, Comments, Playlists; it brings CONFIRM, whose row data it reads) and READMORE (clamped texts: the
     dashboard, Comments, Analytics). @include('users.partials.studio-scripts') prints them all; a page passes the parts
     it uses, ['studioScriptsParts' => ['lists']] (an empty list: the core only). Pass 2 (R1-069): CONFIRM left the core,
     so Video details, the draft page, Analytics, Subscribers and the playlist forms do not carry it.
     The number formatter is made once per page: avdMkCount() made a new Intl.NumberFormat on every call, and the
     bulk bars and dialogs call it for every tick (the largest part of a tick's script at CPU x4).

     CORE
     - One toast system (5.10, 4.4): the layout's (window.avdToast with its stack [data-avd-toasts], printed on every
       page; a toast asked for before the page's main script ran waits for it); tone 'success' (default), 'error',
       'info', 'warning'. window.avdMkToast for the pages' own scripts (Subscribers, the replies sheet). A toast flashed
       with the page (<x-mk.flash>'s <template data-mk-flash-toast>) is shown here only when the layout did not show it
       ([data-avd-toast-flash]). 1.2.3 round 1 (R1-069): the kit toast this script drew when the page had no toast
       system is gone (every page has one, and Alpine comes with the same main script).
     - "Copy link" ([data-copy]) copies and says so in a toast (the clipboard API, else a hidden textarea).
     - A confirmation dialog starts on its Cancel button ([data-mk-autofocus], 6.6); Escape closes it (Bootstrap).
     - When a dialog or a side panel closes, focus goes back to what opened it (Bootstrap's relatedTarget, else the
       control last clicked or pressed), or to the row's menu button when that was an item of the (now closed) row
       menu (section 8). Bootstrap does it only for an opener that is still visible: an item of a row menu or a panel
       opened by a script (the replies sheet) left the focus on <body>, or on an unrelated field in WebKit. Another
       dialog or panel opened meanwhile keeps the focus.
     - A form that came back with errors (or a view-only refusal) moves the focus to its summary ([data-mk-focus]).

     CONFIRM
     - A confirmation opened from a row posts to the row's address (data-action, else the list's "delete" template
       for data-mk-delete) and names the row (data-title, else the row's [data-mk-name]).

     LISTS
     - avdMkCount(runs, n): the counted words of StudioHtml::countWords() for n ([[from, text], ...], the text holding
       \u0001 where the number goes), the number in Western digits (avdMkNum).
     - avdMkIds(form, ids): the ticked rows of a list as the ids[] of its bulk form, written when the form is submitted
       (the submit event comes before the browser reads the form's fields), never kept in step with every tick: on
       "Select all" the 24 inputs Alpine made one by one (an x-for) cost more than the rest of the tick at CPU x4 (INP).
       Every sender of these forms is a submit button (form="..."), so the event always fires.
     - Row menus (lean rows, 4.3): a row carries only its "More actions" toggle (data-mk-menu="<row key>"). The list
       around it names the page's <template> (data-mk-menu-tpl, default mk-video-menu) and its JSON
       (data-mk-menu-data, default mk-video-rows). The .dropdown-menu is added right before Bootstrap needs it (the
       first click or key on the toggle: a capture listener runs before Bootstrap's own) and filled from the template
       when it opens, so 24 rows never carry 24 menus. The JSON:
         {"u": {"<item>": "/profile/.../__ID__/..."},   address templates (__ID__ = the row key)
          "s": ["<shared string>", ...],                  strings several rows share (a video's address)
          "r": {"<row key>": {"<item>": 1 | "<address>" | [<index in s>], ...}}}
       An item of the template (data-k="<item>") stays when its row has it: 1 takes the address template, "copy" copies
       the row's "view" address (made absolute). The delete item and a row's own delete button (data-mk-delete="<key>")
       give the confirmation its address (the "delete" template) and its title (the row's [data-mk-name] text). No
       divider at an end, never two in a row. The menu of a row near the end of the content opens upward: below it the
       main column ends (#main-container clips sideways and the footer follows), so a long menu would hang out of the
       page's own box; Bootstrap reads the parent's .dropup when it places the menu, right after the show event.
     - "Read more" under a text cut by its 3-line clamp (data-mk-clamp): the page measures every clamped text (after
       the fonts, and again when the width changes), all texts first, then the buttons are added or removed, so the
       page lays out once, and gives the ones that are really cut a "Read more" button, so a comment of 200 characters
       is never cut without a way to read it (comment_preview_length is only the server's first guess); the button
       (data-mk-more) opens and closes the text before it (its own clamp class: mk-clamp-2 on the dashboard, mk-clamp-3
       in Comments), no component per row. An opened text keeps its button ("Show less"). --}}
@php($studioParts = array_map('strval', (array) ($studioScriptsParts ?? ['lists', 'readmore'])))
@push('mk-studio-parts')
core,{{ implode(',', $studioParts) }},
@endpush
