// A confirmation opened from inside the watch page's comments sheet (a Bootstrap offcanvas, #comments-offcanvas) is a
// react-bootstrap Modal that lives in <body>. Both kept focus inside themselves: the sheet's focus trap pulled focus
// back from the dialog, the dialog's pulled it back from the sheet, about 2,700 times a second on Chromium (watch-2;
// Playwright could not click "Delete" for 45 s on WebKit). While a dialog of this kind is open, the open sheets' traps
// are released; when it closes they are armed again (only for a sheet that is still open) and focus goes back where it
// was.
import {Offcanvas} from 'bootstrap';

type Trap = {activate?: () => void; deactivate?: () => void};

export const releaseSheetFocus = (): (() => void) => {
    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const released: Array<{sheet: HTMLElement; trap: Trap}> = [];

    document.querySelectorAll<HTMLElement>('.offcanvas.show, .offcanvas.showing').forEach((sheet) => {
        // Bootstrap 5.3 keeps the trap on the instance (_focustrap); without it there is nothing to release.
        const trap = (Offcanvas.getInstance(sheet) as unknown as {_focustrap?: Trap} | null)?._focustrap;

        if (trap && typeof trap.deactivate === 'function' && typeof trap.activate === 'function') {
            trap.deactivate();
            released.push({sheet, trap});
        }
    });

    let restored = false;

    return () => {
        if (restored) {
            return;
        }

        restored = true;
        released.forEach(({sheet, trap}) => {
            if (sheet.isConnected && sheet.classList.contains('show')) {
                trap.activate?.();
            }
        });

        if (previous && previous.isConnected) {
            previous.focus({preventScroll: true});
        }
    };
};
