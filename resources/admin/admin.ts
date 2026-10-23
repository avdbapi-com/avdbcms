// The admin's script (resources/views/components/admin/shell.blade.php): the shell, toasts, confirm dialogs,
// the save bar, tooltips, the form controls, table selection and the preview monitor. The Ctrl+K search
// (palette.ts) loads on first use; the old views' Bootstrap, Alpine and enhanced selects come from
// modules/legacy.ts, which the shell adds only where a view needs them.
// No effects tier, no animation loops, no scroll listeners.

import {initConfirm} from './modules/confirm';
import {initControls} from './modules/controls';
import {initMonitors} from './modules/monitor';
import {initSavebar} from './modules/savebar';
import {initShell} from './modules/shell';
import {initTables} from './modules/table';
import {initToasts} from './modules/toast';
import {initTooltips} from './modules/tooltip';

initToasts();
initConfirm();
initShell();
initSavebar();
initTooltips();
initControls();
initTables();
initMonitors();

// The shell prints modules/legacy.ts as its own module script on pages that need it (it must run before
// DOMContentLoaded). This import is the fallback for a page that asked for it without that tag.
if (document.body.dataset.adLegacy === 'on' && !window.bootstrap) {
    void import('./modules/legacy');
}

// The Appearance pages' module (draft, preview, publish, gallery, history): the page links it and its stylesheet
// itself (resources/views/admin/appearance/*); this import is the fallback and puts it in the build manifest.
if (document.querySelector('[data-ad-appearance]')) {
    void import('./appearance/appearance');
}

// Tools > Updates (the one-click update's steps): linked by its page (resources/views/admin/updates/index.blade.php)
// the same way; this import is the fallback and puts it in the build manifest.
if (document.querySelector('[data-ad-updates]')) {
    void import('./updates/updates');
}

// Category pages show icons the owner picked from all of Font Awesome; other pages use the small subset. The shell
// links this stylesheet itself (so it is in the build manifest because of this import, which is then a no-op).
if (document.body.dataset.adIcons === 'all') {
    void import('./lib/fa-all.scss');
}
