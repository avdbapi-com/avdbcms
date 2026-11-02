/*
 * The studio's scripts (member design 4.3, 5.7, 5.10, 5.11, 6.1-6.8), one file for every member back-office page: a Vite
 * input (minified, kept by the browser between pages), printed once per page by resources/views/layouts/user.blade.php
 * (users/partials/studio-script-tag.blade.php) where these scripts were inline (1.2.3 round 1b, R1R-01: 2 to 15 KB of
 * inline script in the layout part of every studio page, and My videos' 5 KB component in its content). A classic script
 * at the end of the page, as the inline ones were: it runs while the page is read, so its Alpine components are ready
 * when Alpine starts ('alpine:init'), and the layers and listeners are in place before the main script runs.
 *
 *  1. The member bars (every back-office page, layouts/user.blade.php): --mk-bars-h = the real height of the bar that
 *     shows.
 *  2. The studio parts a page asks for (the script tag's data-parts, from @include('users.partials.studio-scripts',
 *     ['studioScriptsParts' => [...]]); none: the page did not include it): core (always with the others), confirm,
 *     lists, readmore. What each part does is written above it (it was users/partials/studio-scripts.blade.php).
 *  3. The page components: mkVideoForm (Video details and the draft page), mkVideos (My videos).
 *
 * Every text it shows comes from the page (the script tag's data-copied / data-more / data-less, translated by the
 * server) or from the page's own JSON. Nothing here runs on a page without its elements.
 */
(function () {
    const script = document.currentScript;
    const config = (script && script.dataset) || {};
    const parts = String(config.parts || '').split(',').map((part) => part.trim()).filter(Boolean);
    const has = (part) => parts.includes(part);

    // 1. The member bars. --mk-bars-h = the real height of the member bar that shows: the save bar while the form has
    // unsaved changes, the phone bulk bar (fixed, 4px above the tab bar) while rows are ticked. Written on the layers
    // that read it (never on <body>: an inherited custom property changed there made the browser restyle the whole
    // document whenever a bar came or went), the ones printed later too (a toast stack, the Upload18 notices: a
    // MutationObserver on <body>'s own children). Without a measure (no bar, an older browser) the CSS formulas of
    // html[data-mk-bar] apply.
    (function () {
        if (window.__mkStudioBars) return;
        window.__mkStudioBars = true;
        const html = document.documentElement;
        const body = document.body;
        if (!body || !window.ResizeObserver || !window.MutationObserver) return;
        const LAYERS = '.mk-toasts, .back-to-top, .avd-daily-pill, [data-upload18-failed-notice], #video-ready-refresh-notice, .mk-bars-room';
        const observed = [];
        let room = '';
        const give = (layer) => {
            if (room) {
                layer.style.setProperty('--mk-bars-h', room);
            } else {
                layer.style.removeProperty('--mk-bars-h');
            }
        };
        const measure = () => {
            const kind = html.getAttribute('data-mk-bar') || '';
            let height = 0;
            const bar = kind === 'save' ? document.querySelector('.avd-mk .mk-savebar') : (kind === 'bulk' ? document.querySelector('.avd-mk .mk-bulkbar') : null);
            if (bar && bar.getClientRects().length) {
                if (kind === 'save' || getComputedStyle(bar).position === 'fixed') {
                    height = Math.ceil(bar.getBoundingClientRect().height) + (kind === 'bulk' ? 4 : 0);
                }
            }
            const next = height > 0 ? height + 'px' : '';
            if (next === room) return;
            room = next;
            document.querySelectorAll(LAYERS).forEach(give);
        };
        const resize = new ResizeObserver(measure);
        const watch = () => {
            document.querySelectorAll('.avd-mk .mk-savebar, .avd-mk .mk-bulkbar').forEach((bar) => {
                if (!observed.includes(bar)) {
                    observed.push(bar);
                    resize.observe(bar);
                }
            });
            measure();
        };
        // a bar that comes or goes is measured in the next frame, with the layout the frame makes anyway (the
        // ResizeObserver above reports it after that layout too), never by forcing a layout inside the tick or key that
        // changed it (INP)
        new MutationObserver(() => window.requestAnimationFrame(measure)).observe(html, {attributes: true, attributeFilter: ['data-mk-bar']});
        new MutationObserver((records) => {
            if (!room) return;
            records.forEach((record) => {
                record.addedNodes.forEach((node) => {
                    if (node.nodeType === 1 && node.matches(LAYERS)) give(node);
                });
            });
        }).observe(body, {childList: true});
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', watch);
        } else {
            watch();
        }
        window.addEventListener('resize', measure, {passive: true});
    })();

    // 1b. A member's own text field (a title, a description, a tagline, a button label: data-mk-dir) takes the direction
    // of what is typed in it (dir="auto": a Latin title in an Arabic page showed its end, 1.2.3 round 3, R3-053), and
    // the page's direction while it is empty (an empty dir="auto" field is laid out left to right: its caret and its
    // hint on the wrong side in Arabic). The page prints dir="auto" on a field that has a text; a reset is followed too.
    (function () {
        if (window.__mkAutoDir) return;
        window.__mkAutoDir = true;
        const follow = (field) => {
            if (String(field.value || '').trim() !== '') field.setAttribute('dir', 'auto');
            else field.removeAttribute('dir');
        };
        document.addEventListener('input', (event) => {
            const field = event.target;
            if (field && field.hasAttribute && field.hasAttribute('data-mk-dir')) follow(field);
        }, true);
        document.addEventListener('reset', (event) => {
            const form = event.target;
            if (form && form.querySelectorAll) window.setTimeout(() => form.querySelectorAll('[data-mk-dir]').forEach(follow), 0);
        }, true);
    })();

    // 1c. A studio list's toolbar (users/partials/filters.blade.php): its filters fold into one "Filters" button below
    // 960px of toolbar width (the kit's container query); from there they come onto the toolbar's row only when they
    // fit on it: one row, every select's default choice and the search hint whole, and 1rem to spare (a web font that
    // arrives later draws a little wider) (data-mk-inline; 1.2.3 round 3, R3-013 / ST-RV-04: whole labels need more than
    // 960px in French, Spanish, German, ... and in the wider looks, and a second toolbar row is never wanted). Folded
    // until measured, so the row keeps its height (nothing below moves); measured again when the toolbar's width changes,
    // when its parts change (a TomSelect taking a select's place: a site with many categories), when the web fonts
    // arrive and once the page has loaded.
    // The focus follows the fold both ways (ST-VF-01): a filter that has it and folds away (the toolbar got narrower)
    // hands it to "Filters"; "Filters" that has it and goes (wider: its filters came onto the row) hands it back to the
    // filter that gave it, else to the row's first filter, so the next Tab goes on through the filters (never from
    // <body>, past them, to the list). A dialog or a side sheet whose opener went that way while it was open gives the
    // focus to the same stand-in when it closes (2, core): the "Filters" sheet, a Date filter's Custom dates dialog.
    const handedBy = new WeakMap();
    const toolbarStandIn = (control) => {
        const bar = control && control.closest ? control.closest('form.mk-toolbar') : null;
        if (!bar) return null;
        const shown = (el) => !!el && el.isConnected && el.getClientRects().length > 0;
        if (control.classList.contains('mk-toolbar__more')) {
            const back = handedBy.get(bar);
            handedBy.delete(bar);
            // a TomSelect's own select is its 1px accessible copy: the TomSelect box next to it is the part on the row
            const filter = shown(back) && bar.contains(back) ? back : Array.from(bar.querySelectorAll('.mk-toolbar__filter')).find((part) => shown(part) && !part.classList.contains('tomselected'));
            const focusable = 'select, input:not([type="hidden"]), button, a[href], [tabindex]:not([tabindex="-1"])';
            return filter ? (filter.matches(focusable) ? filter : filter.querySelector(focusable)) : null;
        }
        const filter = control.closest('.mk-toolbar__filter');
        const more = bar.querySelector('.mk-toolbar__more');
        if (!filter || !shown(more)) return null;
        handedBy.set(bar, filter);
        return more;
    };
    (function () {
        if (window.__mkToolbars) return;
        window.__mkToolbars = true;
        const bars = Array.from(document.querySelectorAll('form.mk-toolbar')).filter((bar) => bar.querySelector('.mk-toolbar__filter'));
        if (!bars.length) return;
        const probe = document.createElement('span');
        probe.setAttribute('aria-hidden', 'true');
        probe.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;inline-size:auto;padding:0;border:0;margin:0;pointer-events:none';
        const FONT = ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'fontStretch', 'fontVariant', 'fontKerning', 'fontFeatureSettings', 'fontVariationSettings', 'letterSpacing', 'wordSpacing', 'textTransform'];
        // the text's width in the control's own font against the room inside the control (spare: the room it must leave;
        // a select sized to its label has exactly its room, the search field's hint must keep a pixel: any overflow is
        // drawn as an ellipsis)
        const cut = (control, text, spare) => {
            if (!text) return false;
            const style = getComputedStyle(control);
            FONT.forEach((name) => { probe.style[name] = style[name]; });
            probe.textContent = text;
            const room = control.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            return probe.getBoundingClientRect().width > room - spare;
        };
        const fits = (bar) => {
            // what takes room on the row (not the probe, a hidden input, or a select a TomSelect replaced: its own box is
            // the hidden-accessible 1px one; the TomSelect box next to it is a part)
            const parts = Array.from(bar.children).filter((part) => part !== probe && part.type !== 'hidden' && part.getClientRects().length && getComputedStyle(part).position !== 'absolute');
            if (!parts.length) return false;
            const first = parts[0].getBoundingClientRect();
            if (parts.some((part) => part.getBoundingClientRect().top >= first.bottom - 1)) return false;
            if (Array.from(bar.querySelectorAll(':scope > select')).some((select) => !select.classList.contains('tomselected') && select.selectedIndex === 0 && cut(select, select.options[0].text, -0.5))) return false;
            // the search grows into the room the filters leave: it must keep 1rem more than its starting width (the row
            // would wrap below it)
            const search = bar.querySelector(':scope > .mk-search');
            if (search) {
                const basis = parseFloat(getComputedStyle(search).flexBasis) || 0;
                const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
                if (search.getBoundingClientRect().width < basis + rem) return false;
            }
            const input = bar.querySelector('.mk-search input');
            return !(input && cut(input, input.placeholder, 1));
        };
        const widths = new WeakMap();
        const fit = (bar) => {
            widths.set(bar, bar.clientWidth);
            const active = document.activeElement;
            const had = active && active.closest && bar.contains(active) ? active.closest('.mk-toolbar__filter, .mk-toolbar__more') : null;
            bar.setAttribute('data-mk-inline', '');
            bar.appendChild(probe);
            const shown = bar.querySelector('.mk-toolbar__filter').getClientRects().length > 0;
            if (!shown || !fits(bar)) bar.removeAttribute('data-mk-inline');
            probe.remove();
            // the part that had the focus went with the fold (a filter: narrower) or the unfold ("Filters": wider): the
            // part that took its place gets the focus
            if (had && !had.getClientRects().length) {
                const next = toolbarStandIn(had);
                if (next) next.focus({preventScroll: true});
            }
        };
        const fitAll = () => bars.forEach(fit);
        fitAll();
        if (window.ResizeObserver) {
            const observer = new ResizeObserver((entries) => entries.forEach((entry) => {
                if (entry.target.clientWidth !== widths.get(entry.target)) fit(entry.target);
            }));
            bars.forEach((bar) => observer.observe(bar));
        }
        if (window.MutationObserver) {
            // a part added or taken away (not this measure's own probe): measured again before the next frame
            const childWatch = new MutationObserver((records) => {
                const changed = new Set();
                records.forEach((record) => {
                    if (Array.from(record.addedNodes).concat(Array.from(record.removedNodes)).some((node) => node !== probe)) changed.add(record.target);
                });
                changed.forEach(fit);
            });
            bars.forEach((bar) => childWatch.observe(bar, {childList: true}));
        }
        // "Filters" left by the member (Tab, a click elsewhere, its sheet opened): the filter that handed it the focus is
        // forgotten (the next unfold gives the focus to the row's first filter)
        bars.forEach((bar) => bar.addEventListener('focusout', (event) => {
            if (event.target && event.target.classList && event.target.classList.contains('mk-toolbar__more')) handedBy.delete(bar);
        }));
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(fitAll);
            if (document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', fitAll);
        }
        window.addEventListener('load', fitAll);
    })();

    // 2. The studio parts
    if (parts.length && !window.__mkStudioScripts) {
        window.__mkStudioScripts = true;

        // CORE
        // - One toast system (5.10, 4.4): the layout's (window.avdToast with its stack [data-avd-toasts], printed on
        //   every page; a toast asked for before the page's main script ran waits for it); tone 'success' (default),
        //   'error', 'info', 'warning'. window.avdMkToast for the pages' own scripts (Subscribers, the replies sheet). A
        //   toast flashed with the page (<x-mk.flash>'s <template data-mk-flash-toast>) is shown here only when the
        //   layout did not show it ([data-avd-toast-flash]).
        // - "Copy link" ([data-copy]) copies and says so in a toast (the clipboard API, else a hidden textarea).
        // - A confirmation dialog starts on its Cancel button ([data-mk-autofocus], 6.6); Escape closes it (Bootstrap).
        // - When a dialog or a side panel closes, focus goes back to what opened it (Bootstrap's relatedTarget, else the
        //   control last clicked or pressed), or to the row's menu button when that was an item of the (now closed) row
        //   menu. An opener that went away meanwhile (a toolbar's "Filters" or a filter: 1c) gives way to the part that
        //   took its place. Another dialog or panel opened meanwhile keeps the focus.
        // - A form that came back with errors (or a view-only refusal) moves the focus to its summary ([data-mk-focus]).
        const copied = config.copied || 'Link copied';

        const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

        // toast: the layout's one toast system (window.avdToast, from the page's main script: ready by DOMContentLoaded)
        const toast = (text, tone) => {
            const kind = tone === 'error' ? 'error' : (tone === 'info' || tone === 'warning' ? tone : 'success');
            const show = () => {
                if (typeof window.avdToast === 'function') window.avdToast(escapeHtml(text), kind);
            };
            if (typeof window.avdToast === 'function') {
                show();
            } else if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', show);
            } else {
                window.addEventListener('load', show);
            }
        };
        window.avdMkToast = toast;

        // copy
        const fallback = (text) => {
            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); } catch (e) { /* the address stays on screen to copy by hand */ }
            area.remove();
        };
        const copy = (text) => {
            const ok = () => { toast(copied); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(ok, () => { fallback(text); ok(); });
            } else {
                fallback(text);
                ok();
            }
        };
        document.addEventListener('click', (event) => {
            const target = event.target.closest ? event.target.closest('[data-copy]') : null;
            if (!target) return;
            event.preventDefault();
            copy(target.getAttribute('data-copy'));
        });

        // focus: back to the opener when a dialog or a side panel closes
        let lastControl = null;
        ['click', 'keydown'].forEach((type) => {
            document.addEventListener(type, (event) => {
                const control = event.target && event.target.closest ? event.target.closest('a[href], button, [role="menuitem"], [tabindex]:not([tabindex="-1"])') : null;
                if (control) lastControl = control;
            }, true);
        });
        const returnTarget = (opener) => {
            if (!opener || !opener.closest) return null;
            const menu = opener.closest('.dropdown-menu');
            if (menu) {
                const toggle = menu.parentElement ? menu.parentElement.querySelector('[data-bs-toggle="dropdown"]') : null;
                return toggle || opener;
            }
            return opener;
        };
        ['show.bs.modal', 'show.bs.offcanvas'].forEach((type) => {
            document.addEventListener(type, (event) => {
                const panel = event.target;
                const opener = event.relatedTarget || (lastControl && !panel.contains(lastControl) ? lastControl : null);
                panel.__mkReturn = returnTarget(opener);
            });
        });
        ['hidden.bs.modal', 'hidden.bs.offcanvas'].forEach((type) => {
            document.addEventListener(type, (event) => {
                let target = event.target.__mkReturn;
                event.target.__mkReturn = null;
                if (!target || !target.isConnected) return;
                if (document.querySelector('.modal.show, .offcanvas.show')) return;
                // an opener that went away while it was open (a toolbar's "Filters" whose filters came onto the row, a
                // filter folded into "Filters": 1c): the part that took its place
                if (!target.getClientRects().length) target = toolbarStandIn(target);
                if (!target || !target.getClientRects().length) return;
                target.focus({preventScroll: true});
            });
        });

        // a confirmation starts on its Cancel button
        document.addEventListener('shown.bs.modal', (event) => {
            const first = event.target.querySelector('[data-mk-autofocus]');
            if (first) first.focus();
        });

        // a form back with errors: focus on its summary; a toast flashed with the page
        const focusAlert = () => {
            const alert = document.querySelector('[data-mk-focus]');
            if (alert) alert.focus();
        };
        const showFlashToast = () => {
            const flash = document.querySelector('template[data-mk-flash-toast]');
            if (!flash || flash.__mkShown) return;
            flash.__mkShown = true;
            if (document.querySelector('[data-avd-toast-flash]')) return;
            const text = (flash.content ? flash.content.textContent : flash.textContent) || '';
            if (text.trim() !== '') toast(text.trim(), flash.getAttribute('data-type') || 'success');
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', focusAlert);
            document.addEventListener('DOMContentLoaded', showFlashToast);
        } else {
            focusAlert();
            showFlashToast();
        }

        // CONFIRM (also with LISTS, whose row data it reads): a confirmation opened from a row posts to the row's address
        // (data-action, else the list's "delete" template for data-mk-delete) and names the row (data-title, else the
        // row's [data-mk-name]).
        const cache = {};
        const json = (id) => {
            if (!id) return {};
            if (Object.prototype.hasOwnProperty.call(cache, id)) return cache[id];
            const el = document.getElementById(id);
            let value = {};
            if (el) {
                try { value = JSON.parse(el.textContent || '{}') || {}; } catch (e) { value = {}; }
            }
            cache[id] = value;
            return value;
        };
        const fill = (template, key) => (template ? String(template).split('__ID__').join(key) : '');
        const listOf = (el) => (el && el.closest ? el.closest('[data-mk-menu-data]') : null);
        const dataOf = (el) => {
            const list = listOf(el);
            return json(list ? list.getAttribute('data-mk-menu-data') : 'mk-video-rows');
        };
        const rowName = (el) => {
            const row = el && el.closest ? el.closest('li, tr, [data-mk-row]') : null;
            const name = row ? row.querySelector('[data-mk-name]') : null;
            if (!name) return null;
            const text = (name.textContent || '').replace(/\s+/g, ' ').trim();
            return text.length > 80 ? text.slice(0, 80).trim() + '…' : text;
        };

        if (has('confirm') || has('lists')) {
            document.addEventListener('show.bs.modal', (event) => {
                const from = event.relatedTarget;
                if (!from || !from.getAttribute) return;
                let action = from.getAttribute('data-action');
                let title = from.getAttribute('data-title');
                const key = from.getAttribute('data-mk-delete');
                if (key !== null) {
                    if (!action) action = fill((dataOf(from).u || {}).delete, key) || null;
                    if (title === null) title = rowName(from);
                }
                const form = event.target.querySelector('form[data-mk-action-from]');
                if (form && action) form.setAttribute('action', action);
                const slot = event.target.querySelector('[data-mk-title]');
                if (slot && title !== null) slot.textContent = title;
            });
        }

        // LISTS
        // - avdMkCount(runs, n): the counted words of StudioHtml::countWords() for n ([[from, text], ...], the text holding
        //   \u0001 where the number goes), the number in Western digits (avdMkNum); the formatter is made once.
        // - avdMkIds(form, ids): the ticked rows of a list as the ids[] of its bulk form, written when the form is
        //   submitted, never kept in step with every tick (INP).
        // - Row menus (lean rows, 4.3): a row carries only its "More actions" toggle (data-mk-menu="<row key>"). The list
        //   around it names the page's <template> (data-mk-menu-tpl, default mk-video-menu) and its JSON
        //   (data-mk-menu-data, default mk-video-rows). The .dropdown-menu is added right before Bootstrap needs it (the
        //   first click or key on the toggle: a capture listener runs before Bootstrap's own) and filled from the
        //   template when it opens, so 24 rows never carry 24 menus. JSON: {"u": {"<item>": "/profile/.../__ID__/..."},
        //   "s": ["<shared string>", ...], "r": {"<row key>": {"<item>": 1 | "<address>" | [<index in s>], ...}}}.
        //   An item of the template (data-k="<item>") stays when its row has it: 1 takes the address template, "copy"
        //   copies the row's "view" address (made absolute). The delete item and a row's own delete button
        //   (data-mk-delete="<key>") give the confirmation its address and title. No divider at an end, never two in a
        //   row. The menu of a row near the end of the content opens upward.
        if (has('lists')) {
            let numberFormat = null;

            // the number in the page's language with Western digits; the formatter is made once
            const num = (n) => {
                if (numberFormat === null) {
                    try {
                        numberFormat = new Intl.NumberFormat((document.documentElement.lang || 'en') + '-u-nu-latn');
                    } catch (e) {
                        numberFormat = false;
                    }
                }
                return numberFormat ? numberFormat.format(n) : String(n);
            };

            window.avdMkCount = (runs, n) => {
                if (!runs || !runs.length || !(n >= runs[0][0])) return '';
                let text = runs[0][1];
                for (let i = 1; i < runs.length && n >= runs[i][0]; i++) text = runs[i][1];
                return String(text).split('\u0001').join(num(n));
            };
            window.avdMkNum = num;

            window.avdMkIds = (form, ids) => {
                if (!form) return;
                form.querySelectorAll('input[data-mk-id]').forEach((input) => { input.remove(); });
                Array.prototype.forEach.call(ids || [], (id) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ids[]';
                    input.value = String(id);
                    input.setAttribute('data-mk-id', '');
                    form.appendChild(input);
                });
            };

            const resolve = (all, row, k, key) => {
                let value = row ? row[k] : null;
                if (Array.isArray(value)) value = (all.s || [])[value[0]];
                if (value === 1 || value === true) {
                    value = k === 'copy' ? resolve(all, row, 'view', key) : fill((all.u || {})[k], key);
                }
                return value || '';
            };

            // row menus
            const ensureMenu = (toggle) => {
                const holder = toggle.parentElement;
                if (!holder) return null;
                let menu = holder.querySelector(':scope > .dropdown-menu');
                if (!menu) {
                    menu = document.createElement('div');
                    menu.className = 'dropdown-menu dropdown-menu-end mk-menu';
                    menu.setAttribute('role', 'menu');
                    toggle.insertAdjacentElement('afterend', menu);
                }
                return menu;
            };
            ['click', 'keydown'].forEach((type) => {
                document.addEventListener(type, (event) => {
                    const toggle = event.target && event.target.closest ? event.target.closest('[data-mk-menu]') : null;
                    if (toggle) ensureMenu(toggle);
                }, true);
            });
            document.addEventListener('show.bs.dropdown', (event) => {
                const toggle = event.target;
                if (!toggle || !toggle.hasAttribute || !toggle.hasAttribute('data-mk-menu')) return;
                const menu = ensureMenu(toggle);
                if (!menu) return;
                const key = toggle.getAttribute('data-mk-menu');
                const list = listOf(toggle);
                const tpl = document.getElementById((list && list.getAttribute('data-mk-menu-tpl')) || 'mk-video-menu');
                const all = dataOf(toggle);
                const row = (all.r || {})[key];
                if (!tpl || !row) return;
                const frag = tpl.content.cloneNode(true);
                frag.querySelectorAll('[data-k]').forEach((item) => {
                    const k = item.getAttribute('data-k');
                    const value = resolve(all, row, k, key);
                    if (!value) { item.remove(); return; }
                    if (k === 'delete') {
                        item.setAttribute('data-mk-delete', key);
                        item.setAttribute('data-action', value);
                        const name = rowName(toggle);
                        if (name !== null) item.setAttribute('data-title', name);
                        return;
                    }
                    if (k === 'copy') {
                        let absolute = value;
                        try { absolute = new URL(value, document.baseURI).href; } catch (e) { /* kept relative */ }
                        item.setAttribute('data-copy', absolute);
                        return;
                    }
                    if (item.tagName === 'FORM') { item.setAttribute('action', value); return; }
                    if (item.tagName === 'A') item.setAttribute('href', value);
                });
                const nodes = Array.prototype.slice.call(frag.children);
                nodes.forEach((node, i) => {
                    if (node.tagName !== 'HR') return;
                    const before = nodes.slice(0, i).some((n) => n.parentNode && n.tagName !== 'HR');
                    const after = nodes.slice(i + 1).some((n) => n.parentNode && n.tagName !== 'HR');
                    if (!before || !after) node.remove();
                });
                // R1-026: where the toggle and the main column are is read before this handler writes anything (the page
                // is laid out), and the menu is measured (a forced layout) only when the room below the toggle could be
                // too small for it: no menu is taller than 80px a line + 32px.
                const holder = toggle.parentElement;
                const main = toggle.closest('[data-avd-main]') || document.getElementById('main-container');
                const at = main ? toggle.getBoundingClientRect() : null;
                const box = main ? main.getBoundingClientRect() : null;
                menu.replaceChildren(frag);
                holder.classList.remove('dropup');
                if (main) {
                    const below = box.bottom - at.bottom - 8;
                    if (below < menu.children.length * 80 + 32) {
                        menu.style.display = 'block';
                        menu.style.visibility = 'hidden';
                        const height = menu.offsetHeight;
                        menu.style.display = '';
                        menu.style.visibility = '';
                        if (height > below && at.top - height - 8 > Math.max(box.top, 0)) {
                            holder.classList.add('dropup');
                        }
                    }
                }
            });

            // 1.2.3 round 2 (R1-026): the FIRST menu a page opened laid its words and icons out for the first time (their
            // styles, the text and icon-font shaping: about 90 ms of layout at CPU x4 inside the click, more than RC1).
            // Once the page has loaded, an idle moment lays one row menu out out of sight and removes it in the same
            // task (hidden, aria-hidden, never focusable, no layout shift: the menu is absolute), and reads the rows'
            // data, so the first open costs what every later one does. Skipped when a menu is already open or built.
            const warm = () => {
                const toggle = document.querySelector('[data-mk-menu-tpl] [data-mk-menu]');
                const list = listOf(toggle);
                const tpl = list && document.getElementById(list.getAttribute('data-mk-menu-tpl') || 'mk-video-menu');
                const holder = toggle && toggle.parentElement;
                if (!tpl || !holder || holder.querySelector(':scope > .dropdown-menu') || document.querySelector('.dropdown-menu.show')) return;
                dataOf(toggle);
                const menu = document.createElement('div');
                menu.className = 'dropdown-menu dropdown-menu-end mk-menu';
                menu.setAttribute('aria-hidden', 'true');
                menu.inert = true;
                menu.style.cssText = 'display:block;visibility:hidden;pointer-events:none';
                menu.appendChild(tpl.content.cloneNode(true));
                holder.appendChild(menu);
                void menu.offsetHeight;
                menu.remove();
            };
            const warmLater = () => {
                if (window.requestIdleCallback) {
                    window.requestIdleCallback(warm, {timeout: 3000});
                } else {
                    setTimeout(warm, 1200);
                }
            };
            if (document.readyState === 'complete') {
                warmLater();
            } else {
                window.addEventListener('load', warmLater, {once: true});
            }
        }

        // READMORE: "Read more" under a text cut by its clamp (data-mk-clamp): the page measures every clamped text
        // (after the fonts, and again when the width changes), all texts first, then the buttons are added or removed,
        // so the page lays out once; the button (data-mk-more) opens and closes the text before it. An opened text keeps
        // its button ("Show less"). [text, its button, shown, opened, cut]
        if (has('readmore')) {
            const moreLabel = config.more || 'Read more';
            const lessLabel = config.less || 'Show less';
            const fitReadMore = () => {
                const states = Array.prototype.map.call(document.querySelectorAll('[data-mk-clamp]'), (text) => {
                    const next = text.nextElementSibling;
                    const button = next && next.hasAttribute('data-mk-more') ? next : null;
                    const shown = text.getClientRects().length > 0;
                    return [text, button, shown, !!button && button.getAttribute('aria-expanded') === 'true', shown && text.scrollHeight > text.clientHeight + 1];
                });
                states.forEach((state) => {
                    if (state[3]) return;
                    if (state[4] && !state[1]) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'mk-btn mk-btn--quiet mk-btn--sm mk-readmore';
                        button.setAttribute('aria-expanded', 'false');
                        button.setAttribute('data-mk-more', '');
                        button.innerHTML = '<span></span>';
                        button.firstChild.textContent = moreLabel;
                        state[0].insertAdjacentElement('afterend', button);
                    } else if (!state[4] && state[1] && state[2]) {
                        state[1].remove();
                    }
                });
            };
            document.addEventListener('click', (event) => {
                const more = event.target.closest ? event.target.closest('[data-mk-more]') : null;
                if (!more) return;
                const text = more.previousElementSibling;
                const open = more.getAttribute('aria-expanded') !== 'true';
                if (text) {
                    let clamp = text.getAttribute('data-mk-clamp-class');
                    if (!clamp) {
                        clamp = (String(text.className).match(/\bmk-clamp-\d\b/) || ['mk-clamp-3'])[0];
                        text.setAttribute('data-mk-clamp-class', clamp);
                    }
                    text.classList.toggle(clamp, !open);
                }
                more.setAttribute('aria-expanded', open ? 'true' : 'false');
                (more.querySelector('span') || more).textContent = open ? lessLabel : moreLabel;
            });
            let fitQueued = false;
            let fitWidth = window.innerWidth;
            const queueFit = () => {
                if (fitQueued) return;
                fitQueued = true;
                window.requestAnimationFrame(() => { fitQueued = false; fitReadMore(); });
            };
            if (document.querySelector('[data-mk-clamp]')) fitReadMore();
            document.addEventListener('DOMContentLoaded', queueFit);
            window.addEventListener('load', queueFit);
            if (document.fonts && document.fonts.ready) document.fonts.ready.then(queueFit);
            window.addEventListener('resize', () => {
                if (window.innerWidth === fitWidth) return;
                fitWidth = window.innerWidth;
                queueFit();
            }, {passive: true});
        }
    }

    // 3. The page components
    // R1-166 (the draft page while Upload18 processes it): the Upload18 completion check (the hands-off poll partial)
    // shows its failed notice by adding [data-upload18-failed-notice] to <body>; the head's status then says "Upload
    // failed" as well (its template is printed only for a draft still being processed).
    const failed = document.querySelector('template[data-mk-failed-status]');
    if (failed && window.MutationObserver && !window.__mkFailedWatch) {
        window.__mkFailedWatch = true;
        const watch = new MutationObserver(() => {
            if (!document.querySelector('body > [data-upload18-failed-notice]')) return;
            watch.disconnect();
            const slot = document.querySelector('[data-mk-head-status]');
            if (slot) slot.replaceChildren(failed.content.cloneNode(true));
        });
        watch.observe(document.body, {childList: true});
    }

    // The replies sheet of Comments (#mk-replies, users/comments/index.blade.php): "N replies" opens it and fetches the
    // replies as an HTML part (?part=1); its pages load inside the sheet. Without JavaScript the link opens the replies
    // page. The words of a failed load come from the sheet (data-failed).
    if (!window.__mkRepliesSheet) {
        window.__mkRepliesSheet = true;
        let current = 0;
        const load = (url) => {
            const box = document.getElementById('mk-replies-body');
            const loading = document.getElementById('mk-replies-loading');
            const sheet = document.getElementById('mk-replies');
            if (!box) return;
            const ticket = ++current;
            box.setAttribute('aria-busy', 'true');
            if (loading) box.replaceChildren(loading.content.cloneNode(true));
            // a background GET (App\Http\Middleware\BackgroundRequest: it never uses up the one-time message of a page the
            // member opens meanwhile, 1.2.3 round 2 with R2A-008)
            fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html', 'X-Background-Request': '1'}, credentials: 'same-origin'})
                .then((response) => { if (!response.ok) throw new Error(String(response.status)); return response.text(); })
                .then((html) => { if (ticket === current) box.innerHTML = html; })
                .catch(() => {
                    if (ticket !== current) return;
                    const body = document.createElement('div');
                    body.className = 'mk-sheet__body';
                    const note = document.createElement('p');
                    note.className = 'mk-muted';
                    note.textContent = (sheet && sheet.getAttribute('data-failed')) || 'The replies could not be loaded. Try again.';
                    body.appendChild(note);
                    box.replaceChildren(body);
                })
                .finally(() => { if (ticket === current) box.removeAttribute('aria-busy'); });
        };
        document.addEventListener('click', (event) => {
            if (!event.target.closest || !window.bootstrap) return;
            const link = event.target.closest('[data-mk-replies]');
            if (link) {
                const sheet = document.getElementById('mk-replies');
                if (!sheet) return;
                event.preventDefault();
                window.bootstrap.Offcanvas.getOrCreateInstance(sheet).show();
                const href = link.getAttribute('href') || '';
                load(href + (href.indexOf('?') === -1 ? '?' : '&') + 'part=1');
                return;
            }
            const page = event.target.closest('#mk-replies-body [data-mk-replies-pager] a[href]');
            if (page) {
                event.preventDefault();
                load(page.href);
            }
        });
    }

    if (window.__mkStudioComponents) return;
    window.__mkStudioComponents = true;

    // 4. Account settings (users/profile/edit.blade.php and its sections; inline scripts of every section's page until
    // 1.2.3 round 3, R3-086). The country list of the Profile section (its select's x-init): every code of the page's
    // window.avdCountryCodes ("ADAEAF...", the smallest form of the list) with its name in the page's language, sorted
    // by that name, the chosen one kept (also as the value a reset goes back to); it runs when Alpine starts, before
    // TomSelect reads the list.
    window.avdCountryOptions = window.avdCountryOptions || function (select) {
        if (!select || select.dataset.filled === '1') return;
        select.dataset.filled = '1';
        const codes = String(window.avdCountryCodes || '').match(/[A-Z]{2}/g) || [];
        const lang = document.documentElement.getAttribute('lang') || 'en';
        let names = null;
        let collator = null;
        try { names = new Intl.DisplayNames([lang, 'en'], {type: 'region'}); } catch (e) { /* the codes stay */ }
        try { collator = new Intl.Collator(lang); } catch (e) { /* the plain order */ }
        const current = select.value;
        const known = {};
        Array.prototype.forEach.call(select.options, (option) => { known[option.value] = option.textContent; });
        const rows = codes.map((code) => {
            let name = known[code] || '';
            if (!name && names) {
                try { name = names.of(code) || ''; } catch (e) { /* the code */ }
            }
            return [code, name || code];
        });
        rows.sort((a, b) => (collator ? collator.compare(a[1], b[1]) : (a[1] < b[1] ? -1 : (a[1] > b[1] ? 1 : 0))));
        const fragment = document.createDocumentFragment();
        rows.forEach((row) => {
            const option = document.createElement('option');
            option.value = row[0];
            option.textContent = row[1];
            option.defaultSelected = option.selected = row[0] === current;
            fragment.appendChild(option);
        });
        Array.prototype.slice.call(select.options).forEach((option) => { if (option.value !== '') option.remove(); });
        select.appendChild(fragment);
        select.value = current;
    };

    document.addEventListener('alpine:init', () => {
        // The page root of Account settings: `dirty` turns on when a field of a form marked data-mk-savebar changes (a
        // field marked data-mk-instant, a file input or a field outside every form never counts), `saving` while the form
        // is sent (no leave warning then; a page restored from the back-forward cache is not sending); html[data-mk-bar]
        // shows the save bar. After a refused save (`refused`) Cancel loads the section again (R1-032). On load, on phones
        // the strip first shows the open section, then the first field with an error is put on screen with its error by
        // window.avdRevealRefused (only a page that came back with one carries it), not after a view-only refusal. A
        // field's counter (the next element, "12 / 120") follows what is typed.
        window.Alpine.data('avdAccountSettings', (startDirty) => ({
            dirty: startDirty === true,
            refused: startDirty === true,
            saving: false,
            init() {
                const root = this.$el;
                window.Alpine.effect(() => { document.documentElement.dataset.mkBar = this.dirty ? 'save' : ''; });
                root.addEventListener('input', (event) => this.mark(event));
                root.addEventListener('change', (event) => this.mark(event));
                root.addEventListener('submit', () => { this.saving = true; });
                window.addEventListener('pageshow', (event) => { if (event.persisted) this.saving = false; });
                window.addEventListener('beforeunload', (event) => {
                    if (this.dirty && !this.saving) { event.preventDefault(); event.returnValue = ''; }
                });
                // A sheet the page opened by itself (the password sheets come back open after a refused password) gives
                // the keyboard focus to the button that opens it when it closes, never to <body> (R3-014's look-around).
                root.addEventListener('hidden.bs.modal', (event) => {
                    const active = document.activeElement;
                    if (active && active !== document.body && !event.target.contains(active)) return;
                    const opener = event.target.id ? root.querySelector('[data-bs-target="#' + event.target.id + '"]') : null;
                    if (opener && opener.getClientRects().length) opener.focus();
                });
                this.$nextTick(() => {
                    const current = root.querySelector('.mk-subnav [aria-current=page]');
                    // R3-050 (lane FE): the strip alone, to the nearest edge with its scroll-padding as scrollIntoView({inline:
                    // 'nearest'}) put it: scrollIntoView() also moved Chromium's keyboard start, so the first Tab went past
                    // 'Skip to content' and the header to the link after the open section.
                    const strip = current && window.matchMedia('(max-width: 991.98px)').matches ? current.closest('.mk-subnav') : null;
                    if (strip) {
                        const at = current.getBoundingClientRect(), box = strip.getBoundingClientRect(), css = window.getComputedStyle(strip);
                        const from = box.left + strip.clientLeft + (parseFloat(css.scrollPaddingLeft) || 0);
                        const to = box.left + strip.clientLeft + strip.clientWidth - (parseFloat(css.scrollPaddingRight) || 0);
                        const fits = at.width <= to - from;
                        let move = 0;
                        if (at.left < from && at.right > to) move = 0;
                        else if ((at.left < from && fits) || (at.right > to && !fits)) move = at.left - from;
                        else if ((at.left < from && !fits) || (at.right > to && fits)) move = at.right - to;
                        if (move) strip.scrollLeft += move;
                    }
                    const invalid = root.querySelector('[aria-invalid=true]');
                    if (invalid && window.avdRevealRefused && !root.querySelector('[data-view-only-refusal]')) window.avdRevealRefused(invalid);
                });
            },
            mark(event) {
                const field = event.target;
                const counter = field && event.type === 'input' ? field.nextElementSibling : null;
                if (counter && counter.classList.contains('mk-counter') && counter.firstElementChild) counter.firstElementChild.textContent = String(field.value.length);
                if (!field || !field.form || field.type === 'file' || field.closest('[data-mk-instant]') || !field.form.hasAttribute('data-mk-savebar')) return;
                this.dirty = true;
            },
            cancel(form) {
                if (this.refused) {
                    this.dirty = false;
                    window.location.reload();
                    return;
                }
                if (form) {
                    form.reset();
                    form.querySelectorAll('input[type=file]').forEach((input) => { input.value = ''; });
                }
                this.dirty = false;
                window.dispatchEvent(new CustomEvent('mk-form-reset'));
            },
        }));

        // The theme of this device (Account settings > Profile: Light, Dark, Device): the same memory as the switch in the
        // account panel (localStorage avd_manual_theme for the site's current look), applied at once; nothing is sent to
        // the server. Both switches tell each other their choice with the "theme-changed" event (detail.mode), so they
        // always show the same one (R1-029); the side menu's and the footer's switch send only the theme.
        window.Alpine.data('avdAccountTheme', () => {
            const root = document.documentElement;
            const context = () => root.dataset.avdThemePreset + ':' + root.dataset.avdThemeDefault;
            const stored = () => {
                try {
                    const mode = localStorage.getItem('avd_manual_theme');
                    if (localStorage.getItem('avd_manual_theme_context') === context() && (mode === 'light' || mode === 'dark' || mode === 'device')) return mode;
                } catch (e) { /* the page's own theme */ }
                return root.dataset.avdThemeDefault === 'auto' ? 'device' : (root.getAttribute('data-bs-theme') === 'light' ? 'light' : 'dark');
            };
            const apply = (theme, mode) => {
                root.setAttribute('data-bs-theme', theme);
                window.dispatchEvent(new CustomEvent('theme-changed', {detail: {theme, mode, id: 'account-settings'}}));
            };
            return {
                mode: stored(),
                sync(detail) {
                    if (!detail || detail.id === 'account-settings') return;
                    if (detail.mode === 'light' || detail.mode === 'dark' || detail.mode === 'device') {
                        this.mode = detail.mode;
                    } else if ((detail.theme === 'light' || detail.theme === 'dark') && detail.theme !== root.getAttribute('data-bs-theme')) {
                        this.mode = detail.theme;
                    }
                },
                choose(mode) {
                    this.mode = mode;
                    if (!(window.AVD_SETTINGS && window.AVD_SETTINGS.themeMemory === false)) {
                        try {
                            localStorage.setItem('avd_manual_theme', mode);
                            localStorage.setItem('avd_manual_theme_context', context());
                        } catch (e) { /* not remembered */ }
                    }
                    if (mode === 'device') {
                        const dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                        apply(dark ? 'dark' : 'light', mode);
                    } else {
                        apply(mode, mode);
                    }
                },
            };
        });

        // Comments (users/comments/index.blade.php): the ticked comments and their counted words (#mk-comment-labels).
        window.Alpine.data('mkComments', () => ({
            selected: [],
            labels: {},
            init() { try { this.labels = JSON.parse(document.getElementById('mk-comment-labels').textContent); } catch (e) { this.labels = {}; } },
            boxes() { return Array.from(this.$root.querySelectorAll('.mk-item__check input[data-row]')); },
            toggle(event) {
                const box = event.target;
                if (!box || !box.matches || !box.matches('input[data-row]')) return;
                const id = Number(box.value);
                this.selected = box.checked ? [...new Set([...this.selected, id])] : this.selected.filter((v) => v !== id);
            },
            all(on) { const ids = []; this.boxes().forEach((box) => { box.checked = on; if (on) ids.push(Number(box.value)); }); this.selected = ids; },
            word(key) { return window.avdMkCount ? window.avdMkCount(this.labels[key], this.selected.length) : ''; },
        }));

        // The video form (Video details and the draft page, users/videos/partials/form.blade.php): its state and the
        // schedule line helpers; the page root only passes its starting values. 1.2.3 round 3 (R3-086, the 4.3 page
        // content budget): what were attributes of every page is set up here once: html[data-mk-bar] (the save bar),
        // the leave warning, the form's input / change / submit listeners and the Title and Description counters; the
        // schedule help's sentences are printed once with their changing parts marked (data-o / data-d / data-g /
        // data-t), which a new date or time rewrites.
        window.Alpine.data('mkVideoForm', (start) => {
            start = start || {};
            return {
                dirty: !!start.dirty,
                // the draft page: its bar shows without unsaved changes (Publish), its leave warning does not
                draft: !!start.draft,
                sending: false,
                status: start.status,
                date: start.date,
                time: start.time,
                offset: start.offset,
                zone: start.zone,
                init() {
                    window.Alpine.effect(() => { document.documentElement.dataset.mkBar = (this.dirty || this.draft) ? 'save' : ''; });
                    window.addEventListener('beforeunload', (event) => {
                        if (this.dirty && !this.sending) { event.preventDefault(); event.returnValue = ''; }
                    });
                    const form = this.$root.querySelector('form.mk-edit');
                    if (!form) return;
                    form.addEventListener('input', (event) => this.mark(event));
                    form.addEventListener('change', (event) => this.mark(event));
                    form.addEventListener('submit', () => { this.sending = true; this.dirty = false; });
                },
                mark(event) {
                    if (!this.sending) this.dirty = true;
                    // a field's counter (the next element: "33 / 100"): the browser's own count, value.length
                    const field = event && event.target;
                    const counter = field && field.nextElementSibling;
                    if (counter && counter.classList.contains('mk-counter') && counter.firstElementChild) counter.firstElementChild.textContent = String(field.value.length);
                },
                zoneLine() {
                    try {
                        const at = new Date(this.date + 'T' + (this.time || '00:00') + ':00Z');
                        const part = new Intl.DateTimeFormat('en-US', {timeZone: this.zone, timeZoneName: 'shortOffset'}).formatToParts(at).find((p) => p.type === 'timeZoneName');
                        if (part) this.offset = part.value;
                    } catch (e) { /* the offset stays */ }
                    const help = this.$root.querySelector('.mk-schedule__zone');
                    if (!help) return;
                    // the sentences carry their own full stop (the member's language: "。" in Japanese, "।" in Hindi)
                    const write = (selector, text) => help.querySelectorAll(selector).forEach((el) => { el.textContent = text; });
                    write('[data-o]', this.offset);
                    write('[data-d]', this.day(false));
                    write('[data-g]', this.day(true));
                    write('[data-t]', (this.time || '') + ' ' + this.offset);
                },
                day(full) {
                    try {
                        // the calendar and the digits the page printed (Gregorian, Western digits: the Thai page said
                        // "2026" and turned to the Buddhist "2569" once the date was changed)
                        return new Intl.DateTimeFormat(document.documentElement.lang || undefined, full
                            ? {day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC', calendar: 'gregory', numberingSystem: 'latn'}
                            : {day: 'numeric', month: 'short', timeZone: 'UTC', calendar: 'gregory', numberingSystem: 'latn'}).format(new Date(this.date + 'T00:00:00Z'));
                    } catch (e) {
                        return this.date;
                    }
                },
            };
        });

        // My videos (users/videos/index.blade.php): the ticked rows, the bulk bar, the bulk dialog and sheet, the single
        // Delete dialog. Its data: #mk-video-rows (addresses, menu items, counts, words), #mk-video-labels (counted words).
        window.Alpine.data('mkVideos', () => ({
            selected: [],
            data: {},
            labels: {},
            current: {},
            bulk: {changeable: 0},
            rows: 0,
            visibility: 'public',
            init() {
                try { this.data = JSON.parse(document.getElementById('mk-video-rows').textContent) || {}; } catch (e) { this.data = {}; }
                try { this.labels = JSON.parse(document.getElementById('mk-video-labels').textContent) || {}; } catch (e) { this.labels = {}; }
                this.rows = this.boxes().length;
                // the dialogs' show events: plain listeners (R1-026): the single Delete dialog takes the row of the
                // control that opened it (the row's own Delete button or its menu's item: data-mk-delete); the bulk
                // dialog and the sheet are written now only if the ticked rows changed since the last idle write
                this.$root.addEventListener('show.bs.modal', (event) => {
                    if (event.target.id === 'mk-delete-video') this.pick(Number((event.relatedTarget && event.relatedTarget.getAttribute('data-mk-delete')) || 0));
                    if (event.target.id === 'mk-delete-videos' && this.$root._mkStale) this.fillAll();
                });
                this.$root.addEventListener('show.bs.offcanvas', (event) => {
                    if (event.target.id === 'mk-visibility-sheet' && this.$root._mkStale) this.fillAll();
                });
            },
            boxes() { return Array.from(this.$root.querySelectorAll('.mk-row__check input')); },
            toggle(event) {
                const box = event.target;
                if (!box || !box.matches || !box.matches('.mk-row__check input')) return;
                const id = Number(box.value);
                box.closest('.mk-row').classList.toggle('is-selected', box.checked);
                this.selected = box.checked ? [...new Set([...this.selected, id])] : this.selected.filter((v) => v !== id);
                this.later();
            },
            all(on) {
                const ids = [];
                this.boxes().forEach((box) => { box.checked = on; box.closest('.mk-row').classList.toggle('is-selected', on); if (on) ids.push(Number(box.value)); });
                this.selected = ids;
                this.later();
            },
            row(id) { return (this.data.r || {})[id] || {}; },
            info(id) {
                const li = this.$root.querySelector('.mk-row[data-id="' + Number(id) + '"]');
                const get = (selector) => (li ? li.querySelector(selector) : null);
                const text = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : '');
                const img = get('.mk-thumb img');
                const views = get('.mk-meta-views');
                // the row's one status line (the meta line on phones and in the compact list, the Visibility column in
                // the full table: users/videos/partials/row.blade.php)
                const chip = get('.mk-row__meta--status .mk-status');
                return {
                    id: Number(id),
                    title: text(get('.mk-row__title')),
                    thumb: img ? img.getAttribute('src') : '',
                    dur: text(get('.mk-thumb__dur')),
                    facts: (views ? text(views) + ' · ' : '') + (this.row(id).t || ''),
                    kind: chip ? ((chip.className.match(/mk-status--([a-z]+)/) || [])[1] || 'private') : 'private',
                    label: text(chip),
                };
            },
            say(group, n) { return ((this.data.w || {})[group] || {})[n] || ''; },
            sum(key) { return this.selected.reduce((total, id) => total + (this.row(id)[key] || 0), 0); },
            num(value) { return window.avdMkNum ? window.avdMkNum(value) : String(value); },
            count(key, n) { return window.avdMkCount ? window.avdMkCount(this.labels[key], n) : ''; },
            word(key) { return this.count(key, this.selected.length); },
            pick(id) {
                const r = this.row(id);
                this.current = Object.assign(this.info(id), {
                    comments: this.say('c', r.c || 0),
                    ratings: String((this.data.w || {}).and || '').replace(':likes', this.say('l', r.l || 0)).replace(':dislikes', this.say('d', r.d || 0)),
                    playlists: r.p ? this.say('p', r.p) : '',
                    changeable: !!r.x,
                    private: !!r.pv,
                    destroy: String((this.data.u || {}).delete || '').split('__ID__').join(Number(id)),
                });
            },
            changeable() { return this.selected.filter((id) => this.row(id).x).length; },
            // The bulk dialog's and the visibility sheet's content (titles, the first five videos, totals, counted words)
            // is written straight into their elements (not through Alpine's x-for and bindings) in an idle moment after
            // the tick's paint, so neither a tick nor opening the dialog makes it (R1-026: INP at CPU x4). A dialog that
            // opens before that idle moment writes it first. Only the sheet's button follows Alpine (the chosen
            // visibility and bulk.changeable).
            later() {
                const root = this.$root;
                root._mkStale = true;
                if (root._mkLater) return;
                root._mkLater = true;
                const run = () => { root._mkLater = false; if (root._mkStale) this.fillAll(); };
                requestAnimationFrame(() => setTimeout(() => (window.requestIdleCallback ? window.requestIdleCallback(run, {timeout: 600}) : run()), 0));
            },
            fillAll() {
                this.$root._mkStale = false;
                ['mk-delete-videos', 'mk-visibility-sheet'].forEach((id) => { const panel = document.getElementById(id); if (panel) this.fill(panel); });
            },
            fill(panel) {
                const n = this.selected.length;
                const changeable = this.changeable();
                const each = (key, run) => panel.querySelectorAll('[data-mk-bulk=' + key + ']').forEach(run);
                const put = (key, text) => each(key, (el) => { el.textContent = text; });
                const show = (key, text) => each(key, (el) => { el.hidden = text === ''; });
                this.bulk.changeable = changeable;
                put('title', this.word(panel.id === 'mk-visibility-sheet' ? 'sheet' : 'title'));
                const list = panel.querySelector('[data-mk-bulk=items]');
                const tpl = panel.querySelector('template[data-mk-bulk=item]');
                if (!list || !tpl) return;
                list.replaceChildren(...this.selected.slice(0, 5).map((id) => {
                    const item = this.info(id);
                    const li = tpl.content.firstElementChild.cloneNode(true);
                    const img = li.querySelector('img');
                    if (item.thumb) { img.src = item.thumb; img.hidden = false; li.querySelector('.mk-thumb__empty').hidden = true; }
                    li.querySelector('strong').textContent = item.title;
                    li.querySelector('.mk-muted').textContent = item.facts;
                    return li;
                }));
                const more = n > 5 ? this.count('more', n - 5) : '';
                put('more', more);
                show('more', more);
                put('c', this.num(this.sum('c')));
                put('l', this.num(this.sum('l')));
                put('d', this.num(this.sum('d')));
                put('p', this.num(this.sum('p')));
                put('remove', this.word('delete'));
                const toPrivate = changeable > 0 ? this.count('private', changeable) : '';
                put('private', toPrivate);
                show('private-btn', toPrivate);
            },
        }));
    });
})();
