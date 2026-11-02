/*
 * The scripts of the member channel, library and playlist pages (member design 6.10, 6.11), one file for all of them:
 * a Vite input (minified, and kept by the browser between pages), printed by resources/views/library/pages-script.blade.php
 * where these pages used to carry them inline (R1-069: about 31 KB of every channel page and 14 KB of History). A classic
 * script at the end of the page, as the inline ones were: it runs while the page is read, so its Alpine components are
 * ready when Alpine starts ('alpine:init'). Each part does nothing on a page without its elements.
 *  1. the small actions (resources/views/library/actions-script.blade.php): share, copy, Watch later, Save, Show more;
 *  2. the channel page's tabs (resources/views/users/show.blade.php, x-data="mkChannel");
 *  3. the History page (resources/views/pages/history.blade.php, x-data="mkHistory").
 * Every text it shows comes from the page or the server (translated there).
 */

// 1. The small actions of the channel, playlist and library pages
(function () {
    const esc = (text) => String(text || '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (text, kind) => { if (typeof window.avdToast === 'function' && text) window.avdToast(esc(text), kind || 'success'); };
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const fallbackCopy = (text, show) => {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.cssText = 'position:fixed;inset-block-start:-100px;opacity:0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); show(); } catch (e) { /* the address stays on screen to copy by hand */ }
        area.remove();
    };
    const copy = (text, done) => {
        // "Address copied": the status shows empty, then its words go in, because a live region reads out
        // what is added to it (a status that only stops being hidden is not read by every screen reader);
        // a second copy starts it again
        const show = () => {
            if (!done) return;
            const words = done.getAttribute('data-copy-words') || done.textContent.trim();
            done.setAttribute('data-copy-words', words);
            clearTimeout(done.mkCopyTimer);
            done.textContent = '';
            done.hidden = false;
            setTimeout(() => { done.textContent = words; }, 60);
            done.mkCopyTimer = setTimeout(() => { done.hidden = true; }, 2500);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(show, () => fallbackCopy(text, show));
        } else {
            fallbackCopy(text, show);
        }
    };
    // the share dialog's service links (components/library/share.blade.php) are filled when it opens
    const shareHref = (to, url, title) => {
        const query = (params) => new URLSearchParams(params).toString();
        switch (to) {
            case 'x': return 'https://twitter.com/intent/tweet?' + query({url, text: title});
            case 'facebook': return 'https://www.facebook.com/sharer/sharer.php?' + query({u: url});
            case 'whatsapp': return 'https://wa.me/?' + query({text: title + ' ' + url});
            case 'telegram': return 'https://t.me/share/url?' + query({url, text: title});
            case 'email': return 'mailto:?subject=' + encodeURIComponent(title) + '&body=' + encodeURIComponent(url);
            default: return '#';
        }
    };
    const fillShare = (dialog) => {
        const url = dialog.getAttribute('data-share-url') || window.location.href;
        const title = dialog.getAttribute('data-share-title') || document.title;
        dialog.querySelectorAll('a[data-share-to]').forEach((link) => { link.href = shareHref(link.getAttribute('data-share-to'), url, title); });
    };
    document.addEventListener('show.bs.modal', (event) => {
        if (event.target instanceof Element && event.target.matches('.mk-share-dialog')) fillShare(event.target);
    });
    const failed = (root) => (root && root.getAttribute('data-message-error')) || document.querySelector('[data-message-error]')?.getAttribute('data-message-error') || '';
    // the server's message of a refused or failed JSON request, else '' (the caller's generic sentence)
    const reason = async (response) => {
        try { return String((await response.json())?.message || ''); } catch (e) { return ''; }
    };
    // an error that carries the server's words (a network failure carries the browser's, never shown)
    const refusal = async (response) => {
        const error = new Error(await reason(response));
        error.server = true;
        return error;
    };
    const said = (error, root) => (error && error.server && error.message) || failed(root);
    const moreRows = async (button) => {
        const root = button.closest('[data-rows-root]');
        const page = root?.getAttribute('data-next-page');
        if (!root || !page || button.getAttribute('aria-busy') === 'true') return;
        button.setAttribute('aria-busy', 'true');
        try {
            const url = new URL(root.getAttribute('data-rows-url'), window.location.href);
            url.searchParams.set('part', 'rows');
            url.searchParams.set('page', page);
            // the rows on screen: the next ones follow them, also after rows were taken out of the list
            url.searchParams.set('shown', String(root.querySelectorAll('[data-rows] [data-playlist-row]').length));
            // the list ad keeps its one place in the list: none in the next rows once it is on the page
            if (root.querySelector('[data-rows] .ad-slot')) url.searchParams.set('ad', 'shown');
            const response = await fetch(url.toString(), {credentials: 'include', headers: {'Accept': 'application/json', 'X-Background-Request': '1', 'X-Locale': window.LANG || 'en'}});
            if (!response.ok) throw await refusal(response);
            const data = await response.json();
            root.querySelector('[data-rows]')?.insertAdjacentHTML('beforeend', data.html || '');
            root.setAttribute('data-next-page', data.next_page ? String(data.next_page) : '');
            const wrap = root.querySelector('[data-rows-more-wrap]');
            if (wrap) wrap.hidden = !data.next_page;
        } catch (error) {
            toast(said(error, root), 'danger');
        } finally {
            button.removeAttribute('aria-busy');
        }
    };
    // Play all starts at the first row left that can be played; it goes when no such row is left
    const playAllFirst = (root) => {
        const playAll = root.querySelector('[data-play-all]');
        if (!playAll) return;
        const left = root.querySelector('[data-rows] .mk-lrow:not(.mk-lrow--gone) .mk-lrow__title');
        if (left) { playAll.setAttribute('href', left.getAttribute('href')); } else { playAll.remove(); }
    };
    // Watch later after a row left it: the head line and "Remove watched" as the server counts them now, Play
    // all on the first row left, and the page's empty state after the last one (before, the head kept "2 videos"
    // and Play all pointed at a removed video until a reload)
    const watchLaterPage = (root, data) => {
        if (!root) return;
        const desc = root.querySelector('[data-rows-head] .mk-head__desc');
        if (desc && typeof data.summary === 'string') desc.textContent = data.summary;
        const rows = root.querySelector('[data-rows]');
        const removeWatched = root.querySelector('[data-remove-watched-open]');
        if (removeWatched && typeof data.watched === 'number') {
            if (data.watched < 1) {
                removeWatched.remove();
            } else if (data.watched_texts) {
                const dialog = document.getElementById('mkRemoveWatched');
                const text = dialog?.querySelector('[data-remove-watched-text]');
                const confirm = dialog?.querySelector('button[type="submit"] span');
                if (text) text.textContent = data.watched_texts.text || text.textContent;
                if (confirm) confirm.textContent = data.watched_texts.confirm || confirm.textContent;
            }
        }
        if (rows && !rows.querySelector('[data-playlist-row]')) {
            // the rows on screen are gone but the list still has some (later pages, or videos saved in
            // another tab since this page loaded): bring them in (the server counts from the rows on screen)
            const more = root.querySelector('[data-rows-more]');
            if (typeof data.count === 'number' && data.count > 0 && more) {
                if (!root.getAttribute('data-next-page')) root.setAttribute('data-next-page', '1');
                // Play all follows the rows that come in (it is not taken away meanwhile)
                moreRows(more).then(() => playAllFirst(root));
                return;
            }
            const empty = root.querySelector('template[data-rows-empty]');
            rows.remove();
            root.querySelector('[data-rows-more-wrap]')?.remove();
            if (empty) empty.replaceWith(empty.content.cloneNode(true));
            root.querySelector('[data-rows-head] .mk-head__actions')?.remove();
            return;
        }
        playAllFirst(root);
    };
    const watchLater = async (button) => {
        const url = button.getAttribute('data-watch-later');
        if (!url || button.disabled) return;
        const wanted = button.getAttribute('data-watch-later-state') === 'on' ? 'off' : 'on';
        const row = button.closest('[data-playlist-row]');
        const root = button.closest('[data-rows-root]');
        button.disabled = true;
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'include',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf()},
                body: JSON.stringify({state: wanted}),
            });
            if (!response.ok) throw await refusal(response);
            const data = await response.json().catch(() => ({}));
            const now = data.status === 'added' ? 'on' : (data.status === 'removed' ? 'off' : '');
            if (now === '') throw new Error('');
            // the site's own Watch later marks on cards follow (window.AVD_USER.watchLaterIds)
            const id = Number(row?.getAttribute('data-playlist-row') || 0);
            if (id && window.AVD_USER && Array.isArray(window.AVD_USER.watchLaterIds)) {
                const ids = window.AVD_USER.watchLaterIds.filter((saved) => saved !== id);
                if (now === 'on') ids.push(id);
                window.AVD_USER.watchLaterIds = ids;
                if (typeof window.updateWatchLaterCardBadges === 'function') window.updateWatchLaterCardBadges();
            }
            if (button.hasAttribute('data-watch-later-row') && now === 'off') {
                row?.remove();
                watchLaterPage(root, data);
            } else {
                button.setAttribute('data-watch-later-state', now);
                const label = button.querySelector('span');
                // the item's two labels: on the item, or once on the list of rows (library/playlist-rows, R1-069)
                const words = button.closest('[data-label-on]') || button;
                if (label) label.textContent = words.getAttribute(now === 'on' ? 'data-label-on' : 'data-label-off') || label.textContent;
                const icon = button.querySelector('i');
                if (icon) icon.className = now === 'on' ? 'fa-solid fa-xmark' : 'fa-regular fa-clock';
                button.disabled = false;
            }
            toast(data.message || '', 'success');
        } catch (error) {
            button.disabled = false;
            toast(said(error, root), 'danger');
        }
    };
    // a playlist page's Save / Saved (it toggles the member's saved playlists)
    const savePlaylist = async (button) => {
        if (button.getAttribute('aria-busy') === 'true') return;
        const root = button.closest('[data-message-error]');
        button.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(button.getAttribute('data-favorite-url'), {method: 'POST', credentials: 'include', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf()}});
            if (!response.ok) throw await refusal(response);
            const saved = button.getAttribute('aria-pressed') !== 'true';
            button.setAttribute('aria-pressed', saved ? 'true' : 'false');
            const label = button.querySelector('span');
            if (label) label.textContent = button.getAttribute(saved ? 'data-label-on' : 'data-label-off') || label.textContent;
            const icon = button.querySelector('i');
            if (icon) icon.className = (saved ? 'fa-solid' : 'fa-regular') + ' fa-bookmark';
        } catch (error) {
            toast(said(error, root), 'danger');
        } finally {
            button.removeAttribute('aria-busy');
        }
    };
    // a row's Share (library/playlist-rows: data-share-row, R1-069): the row's video as the server prints its address
    // ($video->route): the title link's address without the list it was opened from ("?list=<uuid>", the only query a
    // row adds), with this page's origin when the link is site-relative (the server shortened only links to this very
    // site); the link's text is the title
    const rowShare = (button) => {
        const link = button.closest('.mk-lrow')?.querySelector('a.mk-lrow__title');
        const href = link ? link.getAttribute('href') : '';
        if (!href) return null;
        const bare = href.replace(/\?list=[^#]*/, '');
        return {url: bare.startsWith('/') && !bare.startsWith('//') ? window.location.origin + bare : bare, title: link.textContent.trim()};
    };
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;
        // a guest's Subscribe / Save: the site's sign-in prompt (the link to the sign-in page without it)
        const prompt = target.closest('[data-auth-prompt]');
        if (prompt && typeof window.avdShowAuthPrompt === 'function') {
            event.preventDefault();
            // its words: on the element, or on the holder it names (data-auth-prompt="<id>": printed once
            // per page, outside the page roots and their content budget)
            const words = document.getElementById(prompt.getAttribute('data-auth-prompt') || '') || prompt;
            window.avdShowAuthPrompt({
                title: words.getAttribute('data-auth-title') || undefined,
                message: words.getAttribute('data-auth-message') || undefined,
                icon: words.getAttribute('data-auth-icon') || undefined,
            });
            return;
        }
        const opener = target.closest('[data-share-dialog]');
        if (opener) {
            const dialog = document.querySelector(opener.getAttribute('data-share-dialog'));
            if (!dialog) return;
            const url = dialog.getAttribute('data-share-url');
            const title = dialog.getAttribute('data-share-title') || document.title;
            if (navigator.share && window.matchMedia('(pointer: coarse)').matches) {
                navigator.share({title, url}).catch(() => undefined);
            } else if (window.bootstrap) {
                fillShare(dialog);
                window.bootstrap.Modal.getOrCreateInstance(dialog).show();
            }
            return;
        }
        const copyButton = target.closest('[data-copy-text]');
        if (copyButton) {
            // the "Address copied" status: in the button's own row (the About tab's address), else in the
            // dialog body or the panel the button sits in (the Share dialog). Before, the About tab
            // looked in its details list, which holds no status, so nothing showed.
            const done = copyButton.parentElement?.querySelector('[data-copy-done]')
                || copyButton.closest('.mk-sheet__body, .mk-panel__body')?.querySelector('[data-copy-done]');
            copy(copyButton.getAttribute('data-copy-text'), done);
            return;
        }
        const later = target.closest('[data-watch-later]');
        if (later) { watchLater(later); return; }
        const favorite = target.closest('button[data-favorite-url]');
        if (favorite) { savePlaylist(favorite); return; }
        const more = target.closest('[data-rows-more]');
        if (more) { moreRows(more); return; }
        const shareRow = target.closest('button[data-share-url], button[data-share-row]');
        if (shareRow && typeof window.avdQuickShare === 'function') {
            const shared = shareRow.hasAttribute('data-share-url') ? {url: shareRow.getAttribute('data-share-url'), title: shareRow.getAttribute('data-share-title') || ''} : rowShare(shareRow);
            if (shared) window.avdQuickShare(shared.url, shared.title);
        }
    });
})();

// 2. The channel page
// Every method reads the page root as this.$root: called from a button's @click (the sort, "Show more", a tab),
// Alpine's this.$el is that button, and the lists were looked up in it (the sort and "Show more" did nothing).
// Channel tabs: each tab is a link with its own address
// (#videos ...), so a tab can be shared, Back returns to the previous tab and a reload keeps it; "See all" opens
// its tab and brings the tabs to the top. Videos, Playlists and About load from this page's address in the
// page's language (?part=videos|playlists|about) the first time they are opened; a failed load says so with
// Try again, and a click on the tab tries again too.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('mkChannel', () => ({
        tabs: ['home'],
        tab: 'home',
        sort: 'latest',
        next: {videos: '', playlists: '', about: ''},
        loaded: {videos: false, playlists: false, about: false},
        busy: {videos: false, playlists: false, about: false},
        failed: '',
        failedText: '',
        init() {
            this.$watch('failed', () => this.showFailed());
            this.$watch('tab', (name) => { this.markTabs(name); this.showFailed(); });
            this.$watch('sort', (sort) => this.markSort(sort));
            try { this.tabs = JSON.parse(this.$root.dataset.tabs || '["home"]'); } catch (e) { this.tabs = ['home']; }
            this.skeletons();
            this.stick();
            this.band();
            this.fromHash(false);
            window.addEventListener('hashchange', () => this.fromHash(true));
        },
        // The open tab: is-active and aria-current="page" on its link only (the page prints Home as the open one)
        markTabs(name) {
            if (!this.$refs.tabs) return;
            this.$refs.tabs.querySelectorAll('a.mk-tab').forEach((link) => {
                const open = link.getAttribute('href') === '#' + name;
                link.classList.toggle('is-active', open);
                if (open) { link.setAttribute('aria-current', 'page'); } else { link.removeAttribute('aria-current'); }
            });
        },
        // The Videos tab's sort: one click handler for its three buttons, the chosen one pressed
        pickSort(event) {
            const button = event.target instanceof Element ? event.target.closest('[data-sort]') : null;
            if (button) this.setSort(button.getAttribute('data-sort'));
        },
        markSort(sort) {
            this.$root.querySelectorAll('.mk-seg [data-sort]').forEach((button) => {
                const on = button.getAttribute('data-sort') === sort;
                button.classList.toggle('is-active', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        },
        // The skeleton cards of the Videos and Playlists tabs: data-skel of them, drawn once from the one template
        // after the page root (#mkChannelSkel), so the root carries no copy of them (its content budget)
        skeletons() {
            const card = document.getElementById('mkChannelSkel')?.content?.firstElementChild;
            if (!card) return;
            this.$root.querySelectorAll('[data-skel]:not([data-skel-done])').forEach((box) => {
                const count = Math.max(0, Math.min(8, parseInt(box.getAttribute('data-skel') || '0', 10) || 0));
                for (let n = 0; n < count; n++) box.appendChild(card.cloneNode(true));
                box.setAttribute('data-skel-done', '');
            });
        },
        // the page's generic error sentence (kept with the failure template, outside the page root)
        errorText() {
            return String(document.getElementById('mkChannelFailed')?.getAttribute('data-message-error') || '');
        },
        // The tab strip sticks right under the site header as it is printed (its height differs per look and
        // header style), at the top of the screen when the header scrolls away.
        stick() {
            const header = document.querySelector('[data-avd-header]');
            const root = this.$root;
            const measure = () => {
                let top = 0;
                if (header) {
                    const style = getComputedStyle(header);
                    if (style.position === 'sticky' || style.position === 'fixed') {
                        top = Math.max(0, Math.round(header.getBoundingClientRect().height + (parseFloat(style.top) || 0)));
                    }
                }
                root.style.setProperty('--mk-channel-stick', top + 'px');
            };
            measure();
            if (header && typeof ResizeObserver === 'function') {
                new ResizeObserver(measure).observe(header);
            }
        },
        // A menu of the band (the ⋯ menu, the Subscribed bell menu) opens over the tab strip and the content below
        // (the strip used to cover its last item): while one is open (.dropdown-menu.show, Bootstrap's and
        // react-bootstrap's alike) the band rises over the strip
        band() {
            const band = this.$root.querySelector('.mk-channel__band');
            if (!band || typeof MutationObserver !== 'function') return;
            const sync = () => {
                const open = !!band.querySelector('.dropdown-menu.show');
                if (band.classList.contains('is-menu-open') !== open) band.classList.toggle('is-menu-open', open);
            };
            new MutationObserver(sync).observe(band, {subtree: true, childList: true, attributes: true, attributeFilter: ['class']});
        },
        fromHash(scroll) {
            let wanted = '';
            // a hand-typed address with a broken escape ("#%") opens Home instead of stopping the page
            try { wanted = decodeURIComponent((window.location.hash || '').slice(1)); } catch (e) { wanted = ''; }
            const known = this.tabs.includes(wanted);
            this.open(known ? wanted : 'home', scroll && known);
        },
        open(name, scroll) {
            this.tab = name;
            if ((name === 'videos' || name === 'playlists' || name === 'about') && !this.loaded[name]) {
                this.load(name, true);
            }
            if (scroll) {
                this.$nextTick(() => this.toTabs());
            }
        },
        // The place of the strip in the page's flow: right under the band (the strip itself may be stuck, so its
        // own position says nothing)
        stripTop() {
            const strip = this.$refs.tabs;
            const band = this.$root.querySelector('.mk-channel__band');
            if (band) {
                const gap = parseFloat(getComputedStyle(strip.parentElement).rowGap) || 0;
                return band.getBoundingClientRect().bottom + window.scrollY + gap;
            }
            return strip.getBoundingClientRect().top + window.scrollY;
        },
        toTabs() {
            const strip = this.$refs.tabs;
            if (!strip) return;
            const stickTop = parseFloat(getComputedStyle(strip).top) || 0;
            const flowTop = this.stripTop();
            const target = Math.max(0, Math.round(flowTop - stickTop));
            // the opened tab starts under the stuck strip (the page was scrolled past it), or the strip is low on
            // the screen: bring the strip to its stuck place, so the tab starts right under it
            if (window.scrollY > target + 1 || flowTop - window.scrollY > window.innerHeight * 0.6) {
                // at once (the site's smooth scrolling would animate it, and a scroll still under way could
                // carry the page past the place)
                window.scrollTo({top: target, behavior: 'instant'});
            }
        },
        setSort(sort) {
            if (sort === this.sort || this.busy.videos) return;
            this.sort = sort;
            this.next.videos = '';
            this.load('videos', true);
        },
        more(part) {
            if (this.next[part]) this.load(part, false);
        },
        retry() {
            if (this.failed) this.load(this.failed, true);
        },
        // the message under the tab strip while the open tab is the one that failed
        showFailed() {
            let box = this.$root.querySelector('.mk-channel__failed');
            const show = this.failed !== '' && this.failed === this.tab;
            if (!box && show) {
                const template = document.getElementById('mkChannelFailed');
                if (!template) return;
                box = template.content.firstElementChild.cloneNode(true);
                box.querySelector('[data-failed-retry]').addEventListener('click', () => this.retry());
                this.$refs.tabs.after(box);
            }
            if (!box) return;
            box.querySelector('[data-failed-text]').textContent = this.failedText;
            box.hidden = !show;
        },
        // a click on the tab that is open but could not load tries again (its address does not change, so no
        // hashchange follows)
        retryActive(event) {
            const link = event.target instanceof Element ? event.target.closest('a.mk-tab') : null;
            const name = link ? (link.getAttribute('href') || '').slice(1) : '';
            if (name !== '' && name === this.tab && this.failed === name) this.load(name, true);
        },
        async load(part, reset) {
            if (this.busy[part]) return;
            this.busy[part] = true;
            this.skeletons();
            const root = this.$root;
            const list = root.querySelector('[data-part-list="' + part + '"]');
            if (!list) { this.busy[part] = false; return; }
            const url = new URL(root.dataset.partUrl, window.location.href);
            url.searchParams.set('part', part);
            if (part === 'videos') {
                url.searchParams.set('sort', this.sort);
                url.searchParams.set('shown', reset ? '0' : String(list.querySelectorAll('.mk-card').length));
            }
            if (!reset && this.next[part]) url.searchParams.set('cursor', this.next[part]);
            // trying again: the skeleton shows while it loads, the message comes back only if it fails again
            if (this.failed === part) this.failed = '';
            try {
                const response = await fetch(url.toString(), {credentials: 'include', headers: {'Accept': 'application/json', 'X-Background-Request': '1', 'X-Locale': window.LANG || 'en'}});
                let page = null;
                try { page = await response.json(); } catch (e) { page = null; }
                if (!response.ok || !page) {
                    const refused = new Error(String((page && page.message) || ''));
                    refused.server = true;
                    throw refused;
                }
                if (reset) { list.innerHTML = page.html || ''; } else { list.insertAdjacentHTML('beforeend', page.html || ''); }
                this.next[part] = page.next_cursor || '';
                this.loaded[part] = true;
                if (this.failed === part) { this.failed = ''; }
            } catch (error) {
                // the server's own words when it gave some (a refusal), else the generic sentence
                const text = (error && error.server && error.message) || this.errorText();
                if (!this.loaded[part] || reset) {
                    this.failed = part;
                    this.failedText = text;
                } else if (typeof window.avdToast === 'function') {
                    window.avdToast(text.replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c])), 'danger');
                }
            } finally {
                this.busy[part] = false;
            }
        },
    }));
});

// 3. History
// History page behaviour: remove a row, a day or everything, pause, and "Show older". The
// rows, the dialogs and every text are server-rendered (translated); this only calls /api/me/history and moves
// rows.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('mkHistory', () => ({
        next: '',
        busy: false,
        day: null,
        init() {
            const root = this.$el;
            this.next = root.dataset.next || '';
            const onClick = (event) => {
                const target = event.target instanceof Element ? event.target : null;
                if (!target) return;
                const remove = target.closest('[data-history-remove]');
                const removeDay = target.closest('[data-history-remove-day]');
                // the row's id: on its Remove item (as 1.2.3 RC1 printed it) or on the row (R1-069: once per row)
                if (remove) { this.removeRow(remove.getAttribute('data-history-remove') || remove.closest('[data-history-row]')?.getAttribute('data-history-row') || '', remove); }
                else if (removeDay) { this.askDay(removeDay); }
                else if (target.closest('[data-history-clear-all-open]')) { this.askAll(); }
                else if (target.closest('[data-history-pause-instead]')) { this.pause(true, target.closest('button')); }
                else if (target.closest('[data-history-pause]')) { this.pause(root.dataset.paused !== '1', target.closest('button')); }
                else if (target.closest('[data-history-confirm]')) {
                    const confirm = target.closest('[data-history-confirm]');
                    if (this.day) { this.clearDay(confirm); } else { this.clearAll(confirm); }
                }
                else if (target.closest('[data-history-more]')) { this.more(target.closest('button')); }
            };
            root.addEventListener('click', onClick);
            // a confirmation printed outside the page root gets the same handling; inside it (as the page prints it),
            // the root's listener already has its clicks
            const dialog = this.dialog();
            if (dialog && !root.contains(dialog)) dialog.addEventListener('click', onClick);
            // R1-069 (round 3): a row's menu is printed once, as the page's template; a row's button gets its copy the
            // first time it is used (a click, or a key: Enter, Space and the arrows open it). Bootstrap's own dropdown
            // handlers are delegated from the document in the capture phase, so the copy is put in on the window in the
            // capture phase, before them: they find the menu next to its button, as it was printed
            const rowMenu = root.querySelector('template[data-history-row-menu]');
            const giveMenu = (event) => {
                const toggle = event.target instanceof Element ? event.target.closest('.mk-lrow__menu > [data-bs-toggle="dropdown"]') : null;
                if (!toggle || !root.contains(toggle) || !rowMenu || !rowMenu.content.firstElementChild || toggle.parentElement.querySelector('.dropdown-menu')) return;
                toggle.after(rowMenu.content.firstElementChild.cloneNode(true));
            };
            window.addEventListener('click', giveMenu, true);
            window.addEventListener('keydown', giveMenu, true);
        },
        // the generic sentence of a request that got no answer: the layout's (#avd-i18n wlFailed, the same words; R1-069:
        // the page root no longer prints its own copy), else a copy a page root still carries
        errorText() {
            let words = this.$el.dataset.messageError || '';
            try {
                words = words || String(JSON.parse(document.getElementById('avd-i18n')?.textContent || '{}')?.t?.wlFailed || '');
            } catch (e) { /* no layout words: the server's own message or nothing */ }
            return words;
        },
        headers(json) {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const headers = {'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Locale': window.LANG || 'en'};
            if (json) headers['Content-Type'] = 'application/json';
            return headers;
        },
        async send(url, method, body) {
            const headers = this.headers(!!body);
            // a page's own background read leaves the session alone (App\Http\Middleware\BackgroundRequest)
            if (method === 'GET') headers['X-Background-Request'] = '1';
            let response;
            try {
                response = await fetch(url, {method, credentials: 'include', headers, body: body ? JSON.stringify(body) : undefined});
            } catch (e) {
                // no answer at all: the page's own sentence, never the browser's "Failed to fetch"
                throw new Error(this.errorText());
            }
            if (!response.ok) {
                // the server's own words first (a view-only demo account's refusal), else the generic text
                let message = '';
                try { message = (await response.json())?.message || ''; } catch (e) { message = ''; }
                throw new Error(message || this.errorText());
            }
            return response.status === 204 ? null : response.json();
        },
        toast(text, kind = 'success') {
            // the site's one toast system (window.avdToast takes HTML: the text is escaped first)
            const safe = String(text || '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
            if (typeof window.avdToast === 'function') window.avdToast(safe, kind === 'error' ? 'danger' : 'success');
        },
        dialog() {
            return document.getElementById('mkHistoryConfirm');
        },
        // the dialog as printed asks to clear everything; a day's Remove puts its own question in, and askAll()
        // puts the printed one back
        ask(title, text, confirm, icon, gentle) {
            const dialog = this.dialog();
            if (!dialog) return;
            const parts = {
                title: dialog.querySelector('.mk-sheet__title'),
                text: dialog.querySelector('[data-history-confirm-text]'),
                confirm: dialog.querySelector('[data-history-confirm] span'),
                icon: dialog.querySelector('[data-history-confirm] i'),
                gentle: dialog.querySelector('[data-history-pause-instead]'),
            };
            if (!this.printed) {
                this.printed = {title: parts.title.textContent, text: parts.text.textContent, confirm: parts.confirm.textContent};
            }
            parts.title.textContent = title ?? this.printed.title;
            parts.text.textContent = text ?? this.printed.text;
            parts.confirm.textContent = confirm ?? this.printed.confirm;
            parts.icon.className = 'fa-solid fa-' + icon;
            if (parts.gentle) parts.gentle.hidden = !gentle;
            window.bootstrap.Modal.getOrCreateInstance(dialog).show();
        },
        askAll() {
            this.day = null;
            this.ask(null, null, null, 'trash-can', this.$el.dataset.paused === '0');
        },
        // what is left after a removal: the "Clear all history?" sentence counts it (it used to count the
        // removed rows too)
        remaining(answer) {
            if (!answer || typeof answer.clear_all_text !== 'string') return;
            if (!this.printed) {
                const text = this.dialog()?.querySelector('[data-history-confirm-text]');
                if (text) text.textContent = answer.clear_all_text;
            } else {
                this.printed.text = answer.clear_all_text;
            }
        },
        settle() {
            const list = this.$el.querySelector('[data-history-list]');
            list.querySelectorAll('[data-history-day]').forEach((section) => { if (!section.querySelector('[data-history-row]')) section.remove(); });
            const empty = !list.querySelector('[data-history-row]');
            // the last row on screen went but older ones exist: bring them in; the
            // empty state shows only when there is nothing older either
            if (empty && this.next) { this.more(null); return; }
            this.$el.querySelector('[data-history-empty]').hidden = !empty;
            if (empty) {
                this.$el.querySelector('[data-history-clear-all-open]')?.setAttribute('hidden', '');
            }
        },
        async removeRow(id, button) {
            const row = this.$el.querySelector('[data-history-row="' + CSS.escape(id) + '"]');
            if (!row || button.disabled) return;
            button.disabled = true;
            try {
                const answer = await this.send(this.$el.dataset.api + '/' + encodeURIComponent(id) + '?count=1', 'DELETE');
                row.remove();
                this.remaining(answer);
                this.settle();
                this.toast(this.$el.dataset.messageRemoved);
            } catch (error) {
                button.disabled = false;
                this.toast(error.message, 'error');
            }
        },
        askDay(button) {
            // the day: its group's (R1-069: once per day; a button printed with its own date still gives it)
            const section = button.closest('[data-history-day]');
            const day = button.getAttribute('data-history-remove-day') || section?.getAttribute('data-history-day') || '';
            // never a day's question without its day: the dialog's confirm would then clear everything
            if (!day) return;
            this.day = day;
            // the words of a day's removal are the dialog's (data-remove-day-text, data-remove-day-confirm); its question
            // is the button's own (today, yesterday) or the dialog's template with the day's name, as the server
            // makes it (WatchHistory::removeDayQuestion: ":day", ":Day", ":DAY")
            const data = this.dialog()?.dataset || {};
            const name = (section?.querySelector('.mk-day__title')?.textContent || '').trim();
            const question = button.getAttribute('data-day-question') || (data.removeDayQuestion && name
                ? data.removeDayQuestion.replace(/:day|:Day|:DAY/g, (token) => token === ':DAY' ? name.toUpperCase() : (token === ':Day' ? name.charAt(0).toUpperCase() + name.slice(1) : name))
                : null);
            this.ask(question, data.removeDayText, data.removeDayConfirm, 'xmark', false);
        },
        async clearDay(button) {
            if (!this.day || this.busy) return;
            this.busy = true;
            button.setAttribute('aria-busy', 'true');
            let done = false;
            try {
                const answer = await this.send(this.$el.dataset.api + '/clear-day?count=1', 'POST', {date: this.day});
                this.$el.querySelector('[data-history-day="' + CSS.escape(this.day) + '"]')?.remove();
                this.day = null;
                window.bootstrap.Modal.getOrCreateInstance(this.dialog()).hide();
                this.remaining(answer);
                done = true;
                this.toast(this.$el.dataset.messageRemoved);
            } catch (error) {
                this.toast(error.message, 'error');
            } finally {
                this.busy = false;
                button.removeAttribute('aria-busy');
            }
            // after the lock is released: settle() may load the older rows (called while busy, it left the
            // page with no rows, no empty state and only "Show older")
            if (done) this.settle();
        },
        async clearAll(button) {
            if (this.busy) return;
            this.busy = true;
            button.setAttribute('aria-busy', 'true');
            let done = false;
            try {
                await this.send(this.$el.dataset.api + '/clear-all', 'POST');
                this.next = '';
                this.$el.querySelector('[data-history-list]').replaceChildren();
                this.$el.querySelector('[data-history-more-wrap]').hidden = true;
                window.bootstrap.Modal.getOrCreateInstance(this.dialog()).hide();
                done = true;
                this.toast(this.$el.dataset.messageCleared);
            } catch (error) {
                this.toast(error.message, 'error');
            } finally {
                this.busy = false;
                button.removeAttribute('aria-busy');
            }
            if (done) this.settle();
        },
        async pause(paused, button) {
            const url = this.$el.dataset.pauseUrl;
            if (!url || this.busy) return;
            this.busy = true;
            button?.setAttribute('aria-busy', 'true');
            try {
                const answer = await this.send(url, 'POST', {paused});
                const now = !!(answer && answer.paused);
                this.$el.dataset.paused = now ? '1' : '0';
                const toggle = this.$el.querySelector('[data-history-pause]');
                if (toggle) {
                    // the button shows one label and keeps the other in data-label-other (R1-069: each label once in
                    // the page): they change places when the state does
                    const label = toggle.querySelector('span');
                    const other = toggle.getAttribute('data-label-other');
                    if ((toggle.getAttribute('aria-pressed') === 'true') !== now && label && other !== null) {
                        toggle.setAttribute('data-label-other', label.textContent);
                        label.textContent = other;
                    }
                    toggle.setAttribute('aria-pressed', now ? 'true' : 'false');
                    toggle.querySelector('i').className = 'fa-solid fa-' + (now ? 'play' : 'pause');
                }
                const note = this.$el.querySelector('[data-history-paused-note]');
                if (note) note.hidden = !now;
                const instead = this.dialog()?.querySelector('[data-history-pause-instead]');
                if (instead && now) instead.hidden = true;
                if (button && button.matches('[data-history-pause-instead]')) {
                    window.bootstrap.Modal.getOrCreateInstance(this.dialog()).hide();
                }
                this.toast(answer?.message || '');
            } catch (error) {
                this.toast(error.message, 'error');
            } finally {
                this.busy = false;
                button?.removeAttribute('aria-busy');
            }
        },
        async more(button) {
            if (!this.next || this.busy) return;
            this.busy = true;
            button?.setAttribute('aria-busy', 'true');
            let loaded = false;
            try {
                const params = new URLSearchParams({format: 'html', cursor: this.next});
                if (this.$el.dataset.q) params.set('q', this.$el.dataset.q);
                const page = await this.send(this.$el.dataset.api + '?' + params.toString(), 'GET');
                const list = this.$el.querySelector('[data-history-list]');
                const holder = document.createElement('div');
                holder.innerHTML = page.html || '';
                holder.querySelectorAll('[data-history-day]').forEach((section) => {
                    const same = list.querySelector('[data-history-day="' + CSS.escape(section.getAttribute('data-history-day')) + '"]');
                    if (same) { same.querySelector('.mk-lrows').append(...section.querySelectorAll('[data-history-row]')); }
                    else { list.append(section); }
                });
                this.next = page.next_cursor || '';
                this.$el.querySelector('[data-history-more-wrap]').hidden = !this.next;
                loaded = true;
            } catch (error) {
                this.toast(error.message, 'error');
            } finally {
                this.busy = false;
                button?.removeAttribute('aria-busy');
            }
            // a page that brought no row (every row of it removed meanwhile) moves on to the next one
            if (loaded) this.settle();
        },
    }));
});
