{{--
    The "This is a demo" window of a view-only demo account (App\Support\ViewOnlyDemoWindow). App\Http\Middleware\ViewOnlyDemo
    puts it right after the "View-only demo" note, on a view-only account's pages only: nobody else loads it.
    One window for every refusal: a page that came back from one ($open: its kind, opened at once), a request of the
    page's scripts that was refused (403 with X-View-Only-Demo: 1; X-View-Only-Demo-Refusal says closed or change; every
    script of the site asks through fetch() or XMLHttpRequest, both are watched), a closed page opened by a link (it
    went back with the refusal).
    A native <dialog> shown as a modal: the page behind is inert, Escape and OK close it (a click outside it too), Tab
    stays in it, focus moves to OK and back: after a script's refusal to what had the focus when the refused request
    started, else the control the account used last (within 15 s: a page may disable or hide its button while it waits),
    without a scroll; after a page came back from a refusal to the same page, to the control that sent it away (a
    form's button, a link: remembered for a minute in this tab), scrolled into view; a control that is gone from view
    (an item of a menu that closed, the button of a confirm dialog) gives way to the one that opened its menu
    (aria-controls), else to the controls used just before it; else the page's main region. Keys
    and clicks are its own while it is open: Escape closes it and only it (a window of the page under it, a react-bootstrap
    one listening on the document too, stays open), the page's shortcuts and "click outside" handlers get nothing.
    It sits above every other layer, and a second refusal while it is open opens no second window. Its look is the
    site's own dialog: $admin, the admin's .ad-dialog; otherwise the member kit's .mk-dialog (it follows the look's
    "Pop-up window style"; a bottom sheet on phones), in every look and both modes; logical properties only (Arabic
    mirrors). <html> carries data-view-only-demo on such a page (ViewOnlyDemoWindow::PAGE_ATTRIBUTE): page scripts can
    tell that this account's changes are refused.
    The window is the one answer: while it is open the site's toasts stay quiet (data-view-only-demo-open on <html>),
    and the page's own notes of the refusal give way to it once it has opened ([data-view-only-refusal], hidden under
    html.avd-demo-refused): the marked "Not saved" notes, and any part of the page whose whole text is the refusal's
    sentence ($said: the sentence this page came back with; a script's refusal brings its own in its answer). Only a
    text that is the sentence itself is looked at, so a big page costs nothing; a page that came back from a refusal is
    watched while it is read (its notes give way before they are shown), and after a script's refusal the page is
    watched for 4 seconds (what its script writes into its own message).
    $change / $closed: ViewOnlyDemoWindow::payload() of each kind (title, body, button).
--}}
@php
    $demoId = \App\Support\ViewOnlyDemoWindow::ID;
    $demoBody = $open === \App\Http\Middleware\ViewOnlyDemo::REFUSED_CLOSED ? $closed['body'] : $change['body'];
@endphp
<style>
dialog.avd-demo-window:not([open]){display:none}
dialog.avd-demo-window.is-kit{position:fixed;inset:0;inline-size:100%;block-size:100%;max-inline-size:none;max-block-size:none;margin:0;padding:0;border:0;background:none;color:var(--avd-text,inherit);overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;--bs-modal-margin:.5rem}
@media (min-width:576px){dialog.avd-demo-window.is-kit{--bs-modal-margin:1.75rem}}
dialog.avd-demo-window.is-kit::backdrop{background:rgba(4,7,12,.55)}
dialog.avd-demo-window.is-kit .modal-dialog{pointer-events:none}
dialog.avd-demo-window.is-kit .modal-content{pointer-events:auto}
dialog.avd-demo-window.ad-dialog{width:min(440px,calc(100vw - 32px))}
dialog.avd-demo-window.ad-dialog::backdrop{background:rgba(16,20,28,.44);background:var(--overlay,rgba(16,20,28,.44))}
html.avd-demo-refused [data-view-only-refusal]{display:none!important}
[data-avd-demo-focus]:focus{outline:none}
</style>
<dialog id="{{ $demoId }}" @class(['avd-demo-window', 'ad-dialog' => $admin, 'is-kit' => ! $admin]) role="dialog" aria-modal="true"
        aria-labelledby="{{ $demoId }}-title" aria-describedby="{{ $demoId }}-body"
        data-change="{{ $change['body'] }}" data-closed="{{ $closed['body'] }}"@if ($said !== []) data-said="{{ json_encode($said, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"@endif @if ($open) data-open="{{ $open }}"@endif>
@if ($admin)
    <div class="ad-dialog-b">
        <span class="ad-sym is-accent" aria-hidden="true"><svg class="ad-i" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>
        <div class="ad-dialog-text">
            <h2 id="{{ $demoId }}-title">{{ $change['title'] }}</h2>
            <p id="{{ $demoId }}-body">{{ $demoBody }}</p>
        </div>
    </div>
    <div class="ad-dialog-f">
        <button type="button" class="ad-btn is-primary" data-avd-demo-ok autofocus>{{ $change['button'] }}</button>
    </div>
@else
    <div class="modal-dialog modal-dialog-centered mk-dialog mk-prompt avd-mk">
        <div class="modal-content" data-avd-demo-box>
            <div class="mk-sheet__head">
                <span class="mk-sheet__grip mk-prompt__grip" aria-hidden="true"></span>
                <h2 class="mk-sheet__title" id="{{ $demoId }}-title">{{ $change['title'] }}</h2>
            </div>
            <div class="mk-sheet__body">
                <div class="mk-empty mk-empty--compact" style="padding:0">
                    <span class="mk-empty__icon" aria-hidden="true"><i class="fa-solid fa-eye"></i></span>
                    <p class="mk-empty__text" id="{{ $demoId }}-body">{{ $demoBody }}</p>
                </div>
            </div>
            <div class="mk-sheet__foot">
                <button type="button" class="mk-btn mk-btn--primary" data-avd-demo-ok autofocus><span>{{ $change['button'] }}</span></button>
            </div>
        </div>
    </div>
@endif
</dialog>
<script>
(function (d, w) {
    var win = d.getElementById('{{ $demoId }}');
    if (!win || win.getAttribute('data-ready')) return;
    win.setAttribute('data-ready', '1');
    var root = d.documentElement;
    var OPEN = '{{ \App\Support\ViewOnlyDemoWindow::OPEN_ATTRIBUTE }}';
    root.setAttribute('{{ \App\Support\ViewOnlyDemoWindow::PAGE_ATTRIBUTE }}', '');
    var text = d.getElementById('{{ $demoId }}-body');
    var ok = win.querySelector('[data-avd-demo-ok]');
    var box = win.querySelector('[data-avd-demo-box]');
    var can = typeof win.showModal === 'function';
    var back = null;
    var cameBack = null;
    var lastTarget = null;
    var lastAt = 0;
    var said = [];
    var echoes = null;
    var echoesUntil = 0;
    var escapeUp = false;
    var STORE = 'avd-demo-trigger';
    try { said = JSON.parse(win.getAttribute('data-said') || '[]'); } catch (e) {}

    {{-- The controls used last (a few, newest last): when the one that started the refused request is gone (an item of a
        menu that closed, the button of a confirm dialog that closed), the focus goes back along them. --}}
    var used = [];
    var openedAt = 0;
    function remember(el) {
        if (el && el.nodeType === 1 && !win.contains(el)) {
            lastTarget = el;
            lastAt = Date.now();
            if (!used.length || used[used.length - 1].el !== el) used.push({el: el, at: lastAt});
            if (used.length > 8) used.shift();
        }
    }
    {{-- While it is open the focus stays in it, also when the page's own start-up moves it. --}}
    function keepFocus() {
        if (win.open && ok && !win.contains(d.activeElement)) focusOn(ok);
    }
    d.addEventListener('focusin', function (event) {
        if (win.open && !win.contains(event.target)) {
            keepFocus();
            return;
        }
        remember(event.target);
    }, true);
    d.addEventListener('DOMContentLoaded', function () { setTimeout(keepFocus, 0); });
    w.addEventListener('load', function () { setTimeout(keepFocus, 0); });
    {{-- A page script that focuses one of its own (inert) parts takes the focus off it in WebKit: back at once. --}}
    win.addEventListener('focusout', function (event) {
        if (win.open && (!event.relatedTarget || !win.contains(event.relatedTarget))) {
            if (w.Promise) w.Promise.resolve().then(keepFocus); else setTimeout(keepFocus, 0);
        }
    });
    d.addEventListener('pointerdown', function (event) {
        var t = event.target;
        remember(t && t.closest ? (t.closest('a[href], button, input, select, textarea, [tabindex], [role="button"]') || t) : t);
    }, true);

    {{-- Keys are its own while it is open: Escape closes it, and only it (a window of the page under it, such as a
        react-bootstrap one listening on the document, stays open; its own Escape keyup goes nowhere either), and no key
        reaches the page's shortcuts. Tab, Enter and Space still do what they do in it (move the focus, press OK). --}}
    function keys(event) {
        var escape = event.key === 'Escape' || event.key === 'Esc';
        if (escape && event.type === 'keyup' && escapeUp) {
            escapeUp = false;
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        if (event.type === 'keydown') escapeUp = false;
        if (!win.open) return;
        if (escape) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (event.type === 'keydown') {
                escapeUp = true;
                close();
            }
            return;
        }
        event.stopPropagation();
    }
    w.addEventListener('keydown', keys, true);
    w.addEventListener('keypress', keys, true);
    w.addEventListener('keyup', keys, true);

    function focusOn(el, scroll) {
        try {
            if (scroll) el.focus(); else el.focus({preventScroll: true});
        } catch (e) {
            try { el.focus(); } catch (e2) {}
        }
        return d.activeElement === el;
    }

    function plain(value) {
        return String(value || '').replace(/\s+/g, ' ').replace(/^ | $/g, '');
    }

    function usable(el) {
        return !!el && el !== d.body && el !== root && el.nodeType === 1 && d.contains(el) && !win.contains(el);
    }

    {{-- A trigger that is gone from view (an item of a menu that closed when it was chosen, the button of a sheet the
        page came back without): the control that opened that menu or sheet, the one whose aria-controls names it (an
        admin list row's "..." button) or whose Bootstrap data-bs-target (a link's #href) points at it (Account settings'
        "Delete account…" and "Sign out all other devices": R3-055), a visible one first. --}}
    function controllerOf(el) {
        for (var node = el; node && node.nodeType === 1 && node !== d.body; node = node.parentNode) {
            if (node.id) {
                var by = d.querySelectorAll('[aria-controls], [data-bs-target], a[data-bs-toggle][href^="#"]');
                var unseen = null;
                for (var i = 0; i < by.length; i++) {
                    var to = by[i].getAttribute('data-bs-target') || (by[i].hasAttribute('data-bs-toggle') ? by[i].getAttribute('href') : '');
                    if (by[i] !== el && !node.contains(by[i]) && ((' ' + by[i].getAttribute('aria-controls') + ' ').indexOf(' ' + node.id + ' ') >= 0 || to === '#' + node.id)) {
                        if (by[i].getClientRects().length) return by[i];
                        unseen = unseen || by[i];
                    }
                }
                if (unseen) return unseen;
            }
        }
        return null;
    }

    {{-- The page's main region, when no control can take the focus back (its placeholder tabindex goes with the focus). --}}
    function focusMain() {
        var main = d.getElementById('ad-main') || d.querySelector('[data-avd-main]') || d.querySelector('main, [role="main"]');
        if (!usable(main)) return;
        if (!main.hasAttribute('tabindex')) {
            main.setAttribute('tabindex', '-1');
            main.setAttribute('data-avd-demo-focus', '');
            main.addEventListener('blur', function off() {
                main.removeEventListener('blur', off);
                if (main.hasAttribute('data-avd-demo-focus')) {
                    main.removeAttribute('data-avd-demo-focus');
                    main.removeAttribute('tabindex');
                }
            });
        }
        focusOn(main);
    }

    {{-- The control that sends the page away (a form's button, a link) is remembered for a minute, in this tab only:
        when a refusal brings the account back to this same page, closing the window gives the focus back to it. --}}
    function here() {
        return location.pathname + location.search;
    }
    function signature(el) {
        return [el.tagName, el.id, el.getAttribute('name'), el.getAttribute('value'), el.getAttribute('href'), el.getAttribute('formaction'),
            el.form && el.form.getAttribute ? el.form.getAttribute('action') : '', plain(el.textContent).slice(0, 80)].join('|');
    }
    function alike(tag, sig) {
        var all = d.getElementsByTagName(tag);
        var out = [];
        for (var i = 0; i < all.length; i++) {
            if (!win.contains(all[i]) && signature(all[i]) === sig) out.push(all[i]);
        }
        return out;
    }
    function rememberTrigger(el) {
        if (!el || el.nodeType !== 1 || win.contains(el)) return;
        try {
            var sig = signature(el);
            w.sessionStorage.setItem(STORE, JSON.stringify({p: here(), g: el.tagName, s: sig, n: alike(el.tagName, sig).indexOf(el), t: Date.now()}));
        } catch (e) {}
    }
    d.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.querySelector) return;
        rememberTrigger(event.submitter || (form.contains(d.activeElement) && d.activeElement !== d.body ? d.activeElement : null)
            || form.querySelector('button:not([type]), button[type="submit"], input[type="submit"], input[type="image"]'));
    }, true);
    d.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (link) rememberTrigger(link);
    }, true);
    function takeTrigger() {
        var saved = null;
        try {
            saved = JSON.parse(w.sessionStorage.getItem(STORE) || 'null');
            w.sessionStorage.removeItem(STORE);
        } catch (e) {}
        return saved && saved.g && saved.p === here() && Date.now() - saved.t < 60000 ? saved : null;
    }
    function findTrigger(saved) {
        var list = saved ? alike(saved.g, saved.s) : [];
        return list[saved ? saved.n : -1] || (list.length === 1 ? list[0] : null);
    }

    function open(kind, trigger) {
        if (!can) return false;
        if (win.open) return true;
        kind = kind === 'closed' ? 'closed' : 'change';
        var words = win.getAttribute('data-' + kind);
        if (text && words) text.textContent = words;
        var recent = Date.now() - lastAt < 15000 ? lastTarget : null;
        back = usable(trigger) ? trigger : (usable(d.activeElement) ? d.activeElement : (usable(recent) ? recent : null));
        openedAt = Date.now();
        root.setAttribute(OPEN, kind);
        try {
            win.showModal();
        } catch (e) {
            root.removeAttribute(OPEN);
            return false;
        }
        root.classList.add('avd-demo-refused');
        if (ok && d.activeElement !== ok) {
            try { ok.focus(); } catch (e) {}
        }
        return true;
    }

    function close() {
        if (win.open) win.close();
    }

    win.addEventListener('close', function () {
        root.removeAttribute(OPEN);
        var to = back;
        var scroll = false;
        back = null;
        if (!to && cameBack) {
            to = findTrigger(cameBack);
            scroll = true;
        }
        cameBack = null;
        {{-- the control itself, else the one that opened its menu; then the same for the controls used just before it --}}
        var tried = [];
        var candidates = [to];
        for (var i = used.length - 1; i >= 0; i--) {
            if (used[i].at <= openedAt && openedAt - used[i].at < 15000) candidates.push(used[i].el);
        }
        for (var j = 0; j < candidates.length; j++) {
            var el = candidates[j];
            if (!usable(el) || tried.indexOf(el) >= 0) continue;
            tried.push(el);
            if (typeof el.focus === 'function' && focusOn(el, scroll)) return;
            var opener = controllerOf(el);
            if (usable(opener) && tried.indexOf(opener) < 0) {
                tried.push(opener);
                if (focusOn(opener, scroll)) return;
            }
        }
        focusMain();
    });
    if (ok) ok.addEventListener('click', close);
    {{-- Clicks are its own too: a click on it, or outside it on its backdrop (which closes it), reaches no "click
        outside" handler of the page under it. --}}
    win.addEventListener('click', function (event) {
        event.stopPropagation();
        if (event.target === win || (box && !box.contains(event.target))) close();
    });
    ['pointerdown', 'mousedown', 'touchstart'].forEach(function (type) {
        win.addEventListener(type, function (event) { event.stopPropagation(); });
    });

    function giveWay(node) {
        if (!node || said.length === 0) return;
        if (node.nodeType === 3) {
            if (said.indexOf(plain(node.nodeValue)) < 0) return;
            node = node.parentNode;
        } else if (node.nodeType !== 1 || (node.textContent || '').length > 800) {
            return;
        }
        var el = node;
        if (!el || el.nodeType !== 1 || el === d.body || el === root || win.contains(el) || el.contains(win)) return;
        var words = plain(el.textContent);
        if (said.indexOf(words) < 0) return;
        while (el.parentNode && el.parentNode.nodeType === 1 && el.parentNode !== d.body && !el.parentNode.contains(win)
            && (el.parentNode.textContent || '').length <= 800 && plain(el.parentNode.textContent) === words) {
            el = el.parentNode;
        }
        el.setAttribute('data-view-only-refusal', '');
    }

    function sweep(from) {
        if (!from || !d.createTreeWalker || said.length === 0) return;
        var walker = d.createTreeWalker(from, 4, null);
        var node;
        while ((node = walker.nextNode())) giveWay(node);
    }

    function watchEchoes() {
        echoesUntil = Date.now() + 4000;
        if (echoes || !w.MutationObserver || !d.body) return;
        echoes = new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                if (records[i].type === 'characterData') {
                    giveWay(records[i].target);
                } else {
                    for (var j = 0; j < records[i].addedNodes.length; j++) {
                        var added = records[i].addedNodes[j];
                        if (added.nodeType === 1) sweep(added); else giveWay(added);
                    }
                }
            }
            if (Date.now() > echoesUntil) {
                echoes.disconnect();
                echoes = null;
            }
        });
        echoes.observe(d.body, {childList: true, characterData: true, subtree: true});
    }

    function learn(sentence) {
        sentence = plain(sentence);
        if (sentence !== '' && said.indexOf(sentence) < 0) {
            said.push(sentence);
            sweep(d.body);
        }
    }

    function refusal(status, header) {
        if (status !== 403 || header('X-View-Only-Demo') !== '1') return '';
        return header('X-View-Only-Demo-Refusal') === 'closed' ? 'closed' : 'change';
    }

    function refused(kind, trigger) {
        watchEchoes();
        open(kind, trigger);
    }

    if (typeof w.fetch === 'function') {
        var send = w.fetch;
        w.fetch = function () {
            var trigger = d.activeElement;
            return send.apply(w, arguments).then(function (answer) {
                try {
                    var kind = answer ? refusal(answer.status, function (name) { return answer.headers.get(name); }) : '';
                    if (kind) {
                        refused(kind, trigger);
                        answer.clone().json().then(function (data) { learn(data && data.message); }, function () {});
                    }
                } catch (e) {}
                return answer;
            });
        };
    }

    if (w.XMLHttpRequest && w.XMLHttpRequest.prototype && w.XMLHttpRequest.prototype.open) {
        var start = w.XMLHttpRequest.prototype.open;
        w.XMLHttpRequest.prototype.open = function () {
            var request = this;
            request.avdDemoTrigger = d.activeElement;
            if (!request.avdDemoWatched) {
                request.avdDemoWatched = true;
                request.addEventListener('readystatechange', function () {
                    if (request.readyState !== 4) return;
                    try {
                        var kind = refusal(request.status, function (name) { return request.getResponseHeader(name); });
                        if (kind) {
                            refused(kind, request.avdDemoTrigger);
                            if (request.responseType === '' || request.responseType === 'text') learn(JSON.parse(request.responseText || '{}').message);
                        }
                    } catch (e) {}
                });
            }
            return start.apply(request, arguments);
        };
    }

    w.avdDemoWindow = {open: open, close: close};

    var first = win.getAttribute('data-open');
    if (first && can && open(first)) {
        cameBack = takeTrigger();
        if (d.readyState === 'loading') {
            watchEchoes();
            d.addEventListener('DOMContentLoaded', function () {
                sweep(d.body);
                echoesUntil = Date.now() + 1000;
            });
        } else {
            sweep(d.body);
        }
    }
})(document, window);
</script>
