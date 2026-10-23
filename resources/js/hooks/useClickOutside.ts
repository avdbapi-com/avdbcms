import {useEffect, useRef} from "preact/hooks";
import {RefObject} from "preact";

export function useClickOutside<T extends HTMLElement> (ref: RefObject<T>, callback: () => void) {
    const callbackRef = useRef(callback);

    useEffect(() => {
        callbackRef.current = callback;
    }, [callback]);

    useEffect(() => {
        const clickCallback = (event: MouseEvent) => {
            if (ref.current && event.target instanceof Node && !ref.current.contains(event.target)) {
                callbackRef.current()
            }
        }

        document.addEventListener('click', clickCallback)
        return function cleanup () {
            document.removeEventListener('click', clickCallback)
        }
    }, [ref])
}
