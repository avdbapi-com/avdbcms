// The page lock of react-bootstrap's <Modal> dialogs without the layout thrash (R1-026; ./scrollLock.ts did the same for
// Bootstrap's own Modal and Offcanvas in perf-14).
//
// react-bootstrap's default manager (BootstrapModalManager) hides the page's scrollbar and pads the body, then adds the
// body's modal-open class, then reads each fixed or sticky bar's computed padding right after those writes and writes it
// back one bar at a time: every read forced a style recalculation of the whole page (the body's class changed), inside
// the click that opens the dialog (Save to playlist: 300-470 ms at CPU 4x, over the 200 ms budget). This manager keeps the
// same result through lockPageScroll / unlockPageScroll (every read before any write; nested locks count, so a dialog
// over a side panel keeps the page locked until the last one closes) and then marks the body as react-bootstrap does.
import ModalManager from '@restart/ui/ModalManager';
import {lockPageScroll, unlockPageScroll} from './scrollLock';

const OPEN_ATTRIBUTE = 'data-rr-ui-modal-open';

class CalmModalManager extends ModalManager {
    setContainerStyle(): void {
        lockPageScroll();

        const body = document.body;
        body.setAttribute(OPEN_ATTRIBUTE, '');
        body.classList.add('modal-open');
    }

    removeContainerStyle(): void {
        const body = document.body;
        body.classList.remove('modal-open');
        body.removeAttribute(OPEN_ATTRIBUTE);

        unlockPageScroll();
    }
}

let shared: CalmModalManager | null = null;

// One manager for every dialog that uses it (it keeps their stack: the top one closes on Escape).
export const calmModalManager = (): ModalManager => {
    shared ??= new CalmModalManager();

    return shared;
};
