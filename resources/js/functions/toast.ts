// The error toast of the site's components (comments, replies, uploads of pictures, searches, mutations): the kit
// toast of the one toast system (resources/js/layout/toasts.ts, watch-47), as text. It used to fill the red Bootstrap
// toast at the top end of the page (#toast, gone from the layout).
import {showToast} from '../layout/toasts';

export function show(message: string) {
    showToast(String(message ?? ''), {type: 'error'});
}
