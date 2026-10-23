import {useState, KeyboardEvent} from "react";

type Options = {
    length: number | undefined,
    onSelect: (index: number) => void,
    onDefaultSelect?: () => void
}
export function useKeyboardNavigate (options: Options) {

    const {
        length,
        onSelect,
        onDefaultSelect = () => null
    } = options;

    const [index, setIndex] = useState<number | null>(null);

    const resetIndex = () => setIndex(null);

    const navigate = (e: KeyboardEvent<HTMLInputElement>) => {

        // Enter also confirms composed text on mobile and CJK keyboards.
        const nativeEvent = (e as unknown as {nativeEvent?: {isComposing?: boolean}}).nativeEvent;
        if (e.isComposing || nativeEvent?.isComposing || e.keyCode === 229) return;

        if (['ArrowDown', 'ArrowUp', 'Enter'].includes(e.key)) {
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (length === undefined || length === 0) {
                        setIndex(null);
                        return
                    }
                    setIndex(i => {
                        if (i === null) return 0
                        else if (length - 1 === i) return null
                        else return i + 1
                    })
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (length === undefined || length === 0) {
                        setIndex(null);
                        return
                    }
                    setIndex(i => {
                        if (i === null) return length - 1
                        else if (i === 0) return null
                        else return i - 1
                    })
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (length === undefined || length === 0) {
                        setIndex(null);
                        onDefaultSelect();
                        return
                    }
                    if (index === null) {
                        onDefaultSelect();
                    } else {
                        onSelect(index);
                    }
                    break;
            }
        }
    }

    return {index, navigate, resetIndex}
}
