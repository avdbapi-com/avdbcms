// Settings pages (resources/views/admin/settings/page.blade.php): nothing saves by itself. Changed fields are
// counted in the save bar; Save (or Ctrl/Cmd+S) sends only those fields to PUT /admin/settings/save, after asking
// about the risky ones; leaving with unsaved changes asks first. Money > Payment setup also has the payment keys
// form (.env): typed keys count as changes too, the same bar saves them, and they go only with the admin's password
// (its field shows while a key is changed). Served as a cached file by SettingsPageController::script(); texts and
// addresses come from the form's data-config attribute.
(function () {
    'use strict';
    var form = document.querySelector('[data-settings-form]');
    if (!form) return;
    var config = JSON.parse(form.getAttribute('data-config') || '{}');
    var T = config.text || {};
    var bar = form.querySelector('[data-savebar]');
    var saveBtn = bar.querySelector('[data-save]');
    var discardBtn = bar.querySelector('[data-discard]');
    var rows = Array.prototype.slice.call(form.querySelectorAll('[data-key]'));
    var token = (form.querySelector('input[name=_token]') || {}).value || '';
    var keysForm = document.querySelector('form[data-payment-keys]');
    var keyRows = keysForm ? Array.prototype.slice.call(keysForm.querySelectorAll('[data-payment-key]')) : [];
    var keyPassword = keysForm ? keysForm.querySelector('[data-key-password]') : null;
    var keyPasswordRow = keyPassword ? keyPassword.querySelector('.ad-field') : null;
    var keyPasswordInput = keyPassword ? keyPassword.querySelector('input[name=password]') : null;
    var dirty = false;
    var saving = false;

    function each(list, fn) { Array.prototype.forEach.call(list, fn); }

    function control(row) {
        return row.querySelector('textarea[name], select[name], input[name]:not([type=hidden]):not([type=radio]):not([type=checkbox])');
    }

    // A size (stored in bytes) is a number and its unit: the row's value is "500 MB", or '' when the number is empty.
    function sizeParts(row) {
        return { number: row.querySelector('input[type=number]'), unit: row.querySelector('[data-size-unit]') };
    }

    function valueOf(row) {
        if (row.dataset.type === 'bool') {
            var box = row.querySelector('input[type=checkbox]');
            return box && box.checked ? '1' : '0';
        }
        if (row.hasAttribute('data-size')) {
            var size = sizeParts(row);
            var number = size.number ? size.number.value.trim() : '';
            return number === '' ? '' : number + ' ' + (size.unit ? size.unit.value : 'MB');
        }
        var radio = row.querySelector('input[type=radio]:checked');
        if (radio) return radio.value;
        var el = control(row);
        return el ? el.value : '';
    }

    function setValue(row, value) {
        if (row.dataset.type === 'bool') {
            row.querySelector('input[type=checkbox]').checked = value === '1';
            return;
        }
        if (row.hasAttribute('data-size')) {
            var size = sizeParts(row);
            var parts = /^(.*?)\s*(MB|GB)?$/i.exec(String(value).trim()) || ['', '', ''];
            if (size.number) size.number.value = parts[1];
            if (size.unit && parts[2]) size.unit.value = parts[2].toUpperCase();
            return;
        }
        var radios = row.querySelectorAll('input[type=radio]');
        if (radios.length) {
            each(radios, function (r) { r.checked = r.value === value; });
            return;
        }
        var el = control(row);
        if (el) el.value = value;
    }

    // "Default: Download  Reset" under a field whose value is not the default (the page sends it only for those).
    function defaultLine(row, differs) {
        var line = row.querySelector('[data-default-line]');
        if (!line && differs) {
            line = document.createElement('div');
            line.className = 'ad-field-meta';
            line.setAttribute('data-default-line', '');
            var reset = document.createElement('button');
            reset.type = 'button';
            reset.className = 'ad-link';
            reset.setAttribute('data-reset', '');
            reset.textContent = T.reset;
            line.appendChild(document.createElement('span'));
            line.appendChild(document.createTextNode(' '));
            line.appendChild(reset);
            var error = row.querySelector(':scope > [data-error]');
            row.insertBefore(line, error);
        }
        if (!line) return;
        line.hidden = !differs;
        var text = line.querySelector('span');
        if (differs && !text.textContent) text.textContent = T.defaultIs.replace(':value', defaultLabel(row));
    }

    // "Default: Download" - the label of the default choice, or the default text itself (shortened).
    function defaultLabel(row) {
        var value = row.dataset.default;
        var choice = Array.prototype.find.call(row.querySelectorAll('option, input[type=radio]'), function (el) { return el.value === value; });
        if (choice) {
            var label = choice.tagName === 'OPTION' ? choice : (choice.closest('label').querySelector('b') || choice.closest('label'));
            return label.textContent.trim();
        }
        return value.length > 60 ? value.slice(0, 60) + '...' : value;
    }

    function isDirty(row) {
        if (row.dataset.lazy) return false;
        if (row.dataset.writeOnly) return row.dataset.clear === '1' || valueOf(row) !== '';
        return valueOf(row) !== row.dataset.initial;
    }

    // Payment keys: a row is changed when a key was typed, Remove was ticked or the currency / mode changed.
    function keyValue(el) { return el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value; }
    function keyFields(row) { return row.querySelectorAll('input[name], select[name]'); }
    function isKeyDirty(row) {
        return Array.prototype.some.call(keyFields(row), function (el) { return keyValue(el) !== el.dataset.initial; });
    }

    function refresh() {
        var count = 0;
        rows.forEach(function (row) {
            var changed = isDirty(row);
            row.classList.toggle('is-changed', changed);
            if (changed) count++;
            if (row.hasAttribute('data-meta')) defaultLine(row, valueOf(row) !== row.dataset.default);
            var sw = row.querySelector('.ad-switch');
            if (sw) sw.classList.toggle('is-on', valueOf(row) === '1');
            each(row.querySelectorAll('.ad-seg label, .ad-rc'), function (label) {
                var r = label.querySelector('input[type=radio]');
                label.classList.toggle(label.classList.contains('ad-rc') ? 'is-on' : 'is-active', !!(r && r.checked));
            });
        });
        var keysChanged = false;
        keyRows.forEach(function (row) {
            var changed = isKeyDirty(row);
            row.classList.toggle('is-changed', changed);
            if (changed) count++;
            keysChanged = keysChanged || changed;
            var status = row.querySelector('[data-key-status]');
            var clear = row.querySelector('[data-key-clear] input');
            if (status && clear) status.textContent = clear.checked ? T.secretRemove : status.dataset.savedText;
        });
        if (keyPassword) keyPassword.hidden = !keysChanged;
        dirty = count > 0;
        bar.hidden = !dirty;
        bar.querySelector('[data-changes]').textContent = count === 1 ? T.one : T.many.replace(':count', count);
    }

    function toast(text, type) {
        if (typeof window.adminToast === 'function') {
            window.adminToast(text, { type: type === 'error' ? 'error' : 'ok' });
            return;
        }
        var box = document.getElementById('settings-toast');
        if (!box) {
            box = document.createElement('div');
            box.id = 'settings-toast';
            box.setAttribute('role', 'status');
            box.style.cssText = 'position:fixed;inset-inline-end:24px;bottom:96px;z-index:70;max-width:420px';
            document.body.appendChild(box);
        }
        var item = document.createElement('div');
        item.className = 'ad-toast' + (type === 'error' ? ' is-error' : '');
        item.textContent = text;
        box.appendChild(item);
        if (type !== 'error') setTimeout(function () { item.remove(); }, 4000);
        else item.addEventListener('click', function () { item.remove(); });
    }

    function request(url, method, body) {
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) { return { res: res, data: data }; });
        });
    }

    // Ask before a risky change. Cancel has the focus; Esc and the backdrop cancel.
    function confirmDialog(row) {
        var text = row.dataset.confirm;
        var cut = text.search(/[?？؟]/);
        var title = cut > 0 ? text.slice(0, cut + 1) : text;
        var body = cut > 0 ? text.slice(cut + 1).trim() : '';
        if (typeof window.adminConfirm === 'function') {
            return window.adminConfirm({ title: title, body: body, confirm: row.dataset.confirmAction || title, cancel: T.cancel, tone: 'danger' });
        }
        return new Promise(function (resolve) {
            var opener = document.activeElement;
            var scrim = document.createElement('div');
            scrim.className = 'ad-scrim';
            var dlg = document.createElement('div');
            dlg.className = 'ad-dialog';
            dlg.setAttribute('role', 'alertdialog');
            dlg.setAttribute('aria-modal', 'true');
            dlg.setAttribute('aria-labelledby', 'settings-confirm-title');
            dlg.setAttribute('aria-describedby', 'settings-confirm-body');
            dlg.innerHTML = '<div class="ad-dialog-b"><div><h2 id="settings-confirm-title"></h2><p id="settings-confirm-body"></p></div></div>'
                + '<div class="ad-dialog-f"><button type="button" class="ad-btn" data-no></button><button type="button" class="ad-btn is-danger-solid" data-yes></button></div>';
            dlg.querySelector('h2').textContent = title;
            dlg.querySelector('p').textContent = body;
            dlg.querySelector('[data-no]').textContent = T.cancel;
            dlg.querySelector('[data-yes]').textContent = row.dataset.confirmAction || title;
            document.body.appendChild(scrim);
            document.body.appendChild(dlg);
            var buttons = dlg.querySelectorAll('button');
            function close(answer) {
                scrim.remove();
                dlg.remove();
                document.removeEventListener('keydown', onKey, true);
                if (opener && opener.focus) opener.focus();
                resolve(answer);
            }
            function onKey(e) {
                if (e.key === 'Escape') { e.preventDefault(); close(false); }
                if (e.key === 'Tab') {
                    e.preventDefault();
                    (document.activeElement === buttons[0] ? buttons[1] : buttons[0]).focus();
                }
            }
            document.addEventListener('keydown', onKey, true);
            scrim.addEventListener('click', function () { close(false); });
            buttons[0].addEventListener('click', function () { close(false); });
            buttons[1].addEventListener('click', function () { close(true); });
            buttons[0].focus();
        });
    }

    function needsConfirm(row) {
        var now = valueOf(row);
        var was = row.dataset.initial;
        switch (row.dataset.confirmWhen) {
            case 'on': return now === '1' && was !== '1';
            case 'off': return now === '0' && was !== '0';
            case 'filled': return now.trim() !== '' && now !== was;
            default: return now !== was;
        }
    }

    function showError(row, messages, anchor) {
        var box = row.querySelector('[data-error]');
        row.classList.toggle('is-error', !!messages);
        if (messages && !box) {
            box = document.createElement('div');
            box.className = 'ad-error';
            box.setAttribute('role', 'alert');
            box.setAttribute('data-error', '');
            (anchor || row).appendChild(box);
        }
        if (box) {
            box.hidden = !messages;
            box.textContent = messages ? messages.join(' ') : '';
        }
    }

    function showErrors(errors) {
        var first = null;
        rows.forEach(function (row) {
            var messages = errors[row.dataset.group + '.' + row.dataset.key];
            showError(row, messages, row.querySelector('.ad-toggle-text'));
            if (messages && !first) first = row;
        });
        if (first) reveal(first);
    }

    function showKeyErrors(errors) {
        var first = null;
        keyRows.forEach(function (row) {
            var messages = errors[row.dataset.paymentKey];
            showError(row, messages);
            if (messages && !first) first = row;
        });
        if (keyPasswordRow) {
            showError(keyPasswordRow, errors.password);
            if (errors.password && !first) first = keyPasswordRow;
        }
        if (first) reveal(first);
    }

    function reveal(row) {
        var parent = row.parentElement;
        while (parent && parent !== document.body) {
            if (parent.tagName === 'DETAILS') parent.open = true;
            parent = parent.parentElement;
        }
        row.scrollIntoView({ block: 'center' });
        var el = control(row) || row.querySelector('input, select');
        if (el) el.focus({ preventScroll: true });
    }

    // Passwords and keys: "Saved" with Replace / Remove, or "Not set" with an empty field.
    function secretState(row, saved) {
        row.dataset.saved = saved ? '1' : '0';
        delete row.dataset.clear;
        setValue(row, '');
        var status = row.querySelector('[data-secret-status]');
        if (status) {
            status.textContent = saved ? T.secretSaved : T.secretNotSet;
            status.className = 'ad-badge' + (saved ? ' is-ok' : '');
        }
        var input = row.querySelector('[data-secret-input]');
        if (input) input.hidden = saved;
        each(row.querySelectorAll('[data-secret-replace], [data-secret-clear]'), function (button) { button.hidden = !saved; });
        var revealText = row.querySelector('[data-reveal-text]');
        if (revealText) {
            revealText.textContent = saved ? T.revealSaved : T.revealNone;
            row.querySelector('[data-reveal-open]').hidden = !saved;
            row.querySelector('[data-reveal-form]').hidden = true;
            row.querySelector('[data-reveal-result]').hidden = true;
        }
    }

    // Payment keys: "Saved - ends in 4242" with Replace / Remove, or "Not set" with an empty field. The ending is
    // isolated so a right-to-left page keeps its characters in order.
    function keyState(row, saved, end) {
        each(keyFields(row), function (el) {
            if (el.type === 'password') {
                el.value = '';
                el.placeholder = saved ? T.keyReplace : '';
            }
            if (el.type === 'checkbox') el.checked = false;
            el.dataset.initial = keyValue(el);
        });
        var status = row.querySelector('[data-key-status]');
        if (status) {
            status.dataset.saved = saved ? '1' : '0';
            status.dataset.savedText = !saved ? T.secretNotSet : (end ? T.keyEnds.replace(':end', '\u2066' + end + '\u2069') : T.secretSaved);
            status.textContent = status.dataset.savedText;
            status.className = 'ad-badge' + (saved ? ' is-ok' : '');
            keyInput(row, saved);
        }
        var clear = row.querySelector('[data-key-clear]');
        if (clear) clear.hidden = !saved;
    }

    // A saved key's field opens with Replace (the page prints it open, for browsers without this script).
    function keyInput(row, saved) {
        var input = row.querySelector('[data-key-input]');
        var replace = row.querySelector('[data-key-replace]');
        if (input) input.hidden = saved;
        if (replace) replace.hidden = !saved;
    }

    function busy(on) {
        saving = on;
        saveBtn.disabled = on;
        saveBtn.textContent = on ? T.saving : T.save;
    }

    function save() {
        if (saving) return;
        var changed = rows.filter(isDirty);
        var keys = keyRows.filter(isKeyDirty);
        if (!changed.length && !keys.length) return;
        // Payment keys go only with the admin's password: ask before anything is sent, so nothing is half saved.
        if (keys.length && keyPasswordInput && keyPasswordInput.value === '') {
            showError(keyPasswordRow, [T.keyPassword]);
            reveal(keyPasswordRow);
            return;
        }
        var asks = changed.filter(function (row) { return row.dataset.confirm && needsConfirm(row); });
        var chain = Promise.resolve(true);
        asks.forEach(function (row) {
            chain = chain.then(function (ok) { return ok ? confirmDialog(row) : false; });
        });
        chain.then(function (ok) {
            if (!ok) return;
            busy(true);
            // Keys first: when the password is refused, the switches next to them stay unsaved too.
            (keys.length ? sendKeys(keys) : Promise.resolve(true))
                .then(function (done) { return done && changed.length ? send(changed) : done; })
                .then(function () { busy(false); refresh(); });
        });
    }

    function send(changed) {
        var body = { groups: {}, fields: {}, clear: {} };
        changed.forEach(function (row) {
            var g = row.dataset.group, k = row.dataset.key;
            (body.fields[g] = body.fields[g] || []).push(k);
            if (row.dataset.clear === '1') (body.clear[g] = body.clear[g] || []).push(k);
            else (body.groups[g] = body.groups[g] || {})[k] = valueOf(row);
        });
        return request(form.action, 'PUT', body).then(function (r) {
            if (r.res.ok && r.data.ok) {
                var values = r.data.values || {};
                changed.forEach(function (row) {
                    var g = row.dataset.group, k = row.dataset.key;
                    if (row.dataset.writeOnly) {
                        secretState(row, row.dataset.clear !== '1');
                        return;
                    }
                    if (values[g] && Object.prototype.hasOwnProperty.call(values[g], k)) setValue(row, String(values[g][k]));
                    row.dataset.initial = valueOf(row);
                    var picture = row.querySelector('[data-upload-preview]');
                    if (picture) picture.dataset.initial = picture.getAttribute('src') || '';
                    var savedNow = row.querySelector('[data-saved-now]');
                    if (savedNow) {
                        var labels = JSON.parse(savedNow.dataset.labels || '{}');
                        savedNow.textContent = T.savedNow.replace(':value', labels[row.dataset.initial] || row.dataset.initial);
                    }
                });
                showErrors({});
                toast([r.data.message || T.saved].concat(r.data.notes || []).join(' '));
                return true;
            }
            if (r.res.status === 422) showErrors(r.data.errors || {});
            toast(r.data.message || T.failed, 'error');
            return false;
        }, function () {
            toast(T.offline, 'error');
            return false;
        });
    }

    function sendKeys(changed) {
        var body = { clear: [], password: keyPasswordInput ? keyPasswordInput.value : '' };
        changed.forEach(function (row) {
            each(keyFields(row), function (el) {
                if (el.type === 'checkbox') {
                    if (el.checked) body.clear.push(row.dataset.paymentKey);
                } else if (el.value !== '') {
                    body[row.dataset.paymentKey] = el.value;
                }
            });
        });
        // The password is used for this one request and never kept in the page.
        if (keyPasswordInput) keyPasswordInput.value = '';
        return request(keysForm.action, 'PUT', body).then(function (r) {
            if (r.res.ok && r.data.ok) {
                var status = r.data.status || {};
                var ends = r.data.ends || {};
                changed.forEach(function (row) {
                    var key = row.dataset.paymentKey;
                    if (row.querySelector('[data-key-status]')) keyState(row, !!status[key], ends[key]);
                    else keyState(row, true);
                });
                showKeyErrors({});
                toast(r.data.message || T.saved);
                return true;
            }
            if (r.res.status === 422 || r.res.status === 429) showKeyErrors(r.data.errors || {});
            toast(r.data.message || T.failed, 'error');
            return false;
        }, function () {
            toast(T.offline, 'error');
            return false;
        });
    }

    // A picture is stored as soon as it is chosen; its address goes into the field and is used after Save.
    function upload(row, input) {
        if (!input.files.length || !config.upload) return;
        var body = new FormData();
        body.append('key', row.dataset.key);
        body.append('file', input.files[0]);
        showError(row, null);
        var preview = row.querySelector('[data-upload-preview]');
        row.setAttribute('aria-busy', 'true');
        fetch(config.upload, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) { return { res: res, data: data }; });
        }).then(function (r) {
            input.value = '';
            if (r.res.ok && r.data.ok) {
                setValue(row, r.data.value);
                preview.src = r.data.preview;
                preview.hidden = false;
                refresh();
                return;
            }
            var errors = r.data.errors || {};
            showError(row, errors.file || errors.key || [r.data.message || T.failed]);
        }, function () {
            toast(T.offline, 'error');
        }).then(function () { row.removeAttribute('aria-busy'); });
    }

    form.addEventListener('input', refresh);
    form.addEventListener('change', function (e) {
        var file = e.target.closest('[data-upload-file]');
        if (file) {
            upload(file.closest('[data-key]'), file);
            return;
        }
        var more = e.target.closest('[data-more-select]');
        if (more) {
            var radio = more.closest('.ad-rc').querySelector('[data-more-radio]');
            radio.value = more.value;
            radio.checked = true;
        }
        refresh();
    });
    form.addEventListener('submit', function (e) { e.preventDefault(); save(); });
    form.addEventListener('click', function (e) {
        var row = e.target.closest('[data-key]');
        if (e.target.closest('[data-reset]')) {
            setValue(row, row.dataset.default);
            refresh();
        } else if (e.target.closest('[data-secret-replace]')) {
            row.querySelector('[data-secret-input]').hidden = false;
            control(row).focus();
        } else if (e.target.closest('[data-secret-clear]')) {
            row.dataset.clear = '1';
            row.querySelector('[data-secret-status]').textContent = T.secretRemove;
            refresh();
        } else if (e.target.closest('[data-reveal-open]')) {
            row.querySelector('[data-reveal-form]').hidden = false;
            row.querySelector('[data-reveal-form] input').focus();
        } else if (e.target.closest('[data-reveal-submit]')) {
            revealSecret(row);
        } else if (e.target.closest('[data-copy]')) {
            copy(row.querySelector('[data-reveal-result] input'));
        }
    });
    form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.closest('[data-reveal-form]')) {
            e.preventDefault();
            revealSecret(e.target.closest('[data-key]'));
        }
    });
    discardBtn.addEventListener('click', function () {
        rows.forEach(function (row) {
            if (row.dataset.writeOnly) secretState(row, row.dataset.saved === '1');
            else setValue(row, row.dataset.initial);
            var preview = row.querySelector('[data-upload-preview]');
            if (preview) {
                preview.src = preview.dataset.initial;
                preview.hidden = !preview.dataset.initial;
            }
        });
        keyRows.forEach(function (row) {
            each(keyFields(row), function (el) {
                if (el.type === 'checkbox') el.checked = el.dataset.initial === '1';
                else el.value = el.dataset.initial;
            });
            var status = row.querySelector('[data-key-status]');
            if (status) keyInput(row, status.dataset.saved === '1');
        });
        if (keyPasswordInput) keyPasswordInput.value = '';
        showErrors({});
        showKeyErrors({});
        refresh();
    });
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            save();
        }
    });
    window.addEventListener('beforeunload', function (e) {
        if (dirty && !saving) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    if (keysForm) {
        keysForm.addEventListener('input', refresh);
        keysForm.addEventListener('change', refresh);
        // Enter in a key field saves with the bar, like everywhere else on the page.
        keysForm.addEventListener('submit', function (e) { e.preventDefault(); save(); });
        var keysSubmit = keysForm.querySelector('[data-keys-submit]');
        if (keysSubmit) keysSubmit.hidden = true;
        keyRows.forEach(function (row) {
            each(keyFields(row), function (el) { el.dataset.initial = keyValue(el); });
            var status = row.querySelector('[data-key-status]');
            if (status) keyInput(row, status.dataset.saved === '1');
        });
        keysForm.addEventListener('click', function (e) {
            var replace = e.target.closest('[data-key-replace]');
            if (!replace) return;
            var row = replace.closest('[data-payment-key]');
            keyInput(row, false);
            row.querySelector('input[type=password]').focus();
        });
    }

    function revealSecret(row) {
        var input = row.querySelector('[data-reveal-form] input');
        request(config.reveal, 'POST', { password: input.value }).then(function (r) {
            var data = r.data;
            input.value = '';
            if (!data.ok) { toast(data.message || T.wrongPassword, 'error'); return; }
            row.querySelector('[data-reveal-form]').hidden = true;
            if (data.link) {
                var result = row.querySelector('[data-reveal-result]');
                result.hidden = false;
                result.querySelector('input').value = data.link;
            }
            row.querySelector('[data-reveal-text]').textContent = data.message;
        }).catch(function () { toast(T.offline, 'error'); });
    }

    function copy(input) {
        var done = function () { toast(T.copied); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(done);
        } else {
            input.select();
            document.execCommand('copy');
            done();
        }
    }

    // Folded parts stay as the admin left them; a search link (#setting_key) opens and highlights its field.
    each(form.querySelectorAll('details[data-remember]'), function (d) {
        try {
            if (localStorage.getItem('ad-open:' + d.dataset.remember) === '1') d.open = true;
            d.addEventListener('toggle', function () { localStorage.setItem('ad-open:' + d.dataset.remember, d.open ? '1' : '0'); });
        } catch (err) { /* storage blocked: folds simply start closed */ }
    });
    var target = location.hash ? document.getElementById(location.hash.slice(1) + '-row') : null;
    if (target) {
        reveal(target);
        target.classList.add('is-highlight');
        setTimeout(function () { target.classList.remove('is-highlight'); }, 2500);
    }

    // The script is running: the plain Save row becomes the bar that appears only while something changed.
    bar.classList.remove('is-static');
    discardBtn.hidden = false;

    // What the page loaded with is the "saved" state (the form has autocomplete=off, so the browser does not
    // restore edits from an earlier visit). Long code boxes arrive in one request after the page.
    rows.forEach(function (row) {
        row.dataset.initial = valueOf(row);
        if (row.hasAttribute('data-default-same')) row.dataset.default = row.dataset.initial;
        var preview = row.querySelector('[data-upload-preview]');
        if (preview) preview.dataset.initial = preview.getAttribute('src') || '';
    });
    var lazy = rows.filter(function (row) { return row.dataset.lazy; });
    if (lazy.length && config.values) {
        var keys = lazy.map(function (row) { return row.dataset.key; }).join(',');
        fetch(config.values + '?keys=' + encodeURIComponent(keys), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.ok ? res.json() : Promise.reject(res.status); })
            .then(function (data) {
                lazy.forEach(function (row) {
                    var el = control(row);
                    el.value = (data.values || {})[row.dataset.key] || '';
                    el.readOnly = false;
                    el.removeAttribute('placeholder');
                    row.dataset.initial = el.value;
                    delete row.dataset.lazy;
                });
                refresh();
            })
            .catch(function () { toast(T.offline, 'error'); });
    }
    refresh();
})();
