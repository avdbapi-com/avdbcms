import {useEffect, useRef} from "preact/hooks";

type Props = {
    html: string,
    className?: string,
    ariaLabel?: string,
}

export function HtmlAdSlot({html, className = '', ariaLabel = 'Advertisement'}: Props) {
    const adContainerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!html || !adContainerRef.current) {
            return;
        }

        const scripts = adContainerRef.current.querySelectorAll('script');
        scripts.forEach((oldScript) => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode?.replaceChild(newScript, oldScript);
        });
    }, [html]);

    return (
        <div
            ref={adContainerRef}
            className={className}
            role="complementary"
            aria-label={ariaLabel}
            dangerouslySetInnerHTML={{__html: html}}
        />
    );
}
