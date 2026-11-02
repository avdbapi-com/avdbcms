import {useEffect, useRef} from 'preact/hooks';
import {releaseSheetFocus} from './sheetFocus';

// For a dialog opened from inside a side sheet (watch-2): release() right before it opens, reArm() once it has closed
// (react-bootstrap's onExited). Unmounting while open re-arms too.
export function useSheetFocus() {
    const restore = useRef<null | (() => void)>(null);

    const release = (): void => {
        if (!restore.current) {
            restore.current = releaseSheetFocus();
        }
    };

    const reArm = (): void => {
        const done = restore.current;
        restore.current = null;
        done?.();
    };

    useEffect(() => () => reArm(), []);

    return {release, reArm};
}
