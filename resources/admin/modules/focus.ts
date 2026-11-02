// R2R-02 (WCAG 2.2 2.4.11 / 2.4.12): in the admin too, a control the keyboard moves to is never left under a bar that
// stays on screen: the top bar, a table's sticky heading cells, the toast stack and every bar at the bottom of the window
// the toasts keep clear of (./toast.ts BOTTOM_BARS: the save bars, the language editor's save row, the Appearance
// publish bar, the view-only note). Keyboard only (a mouse or a touch changes nothing). The measuring and scrolling are
// the public pages' (resources/js/layout/focusClear.ts, a module without imports of its own: nothing else of the public
// bundle comes along).
import {installFocusClear} from '../../js/layout/focusClear';
import {BOTTOM_BARS} from './toast';

export const initFocusClear = (): void => {
    installFocusClear({bars: `.ad-top, .ad-table th, .ad-toasts, ${BOTTOM_BARS}`});
};
