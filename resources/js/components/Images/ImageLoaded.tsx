import {useCallback, useEffect, useRef, useState} from 'preact/hooks';
import {clsx} from "clsx";
import {ImgHTMLAttributes} from "react";
import {useTranslation} from "react-i18next";

type Props = {
    source: string,
    title?: string,
    hover?: boolean,
    class?: string,
    className?: string,
} & ImgHTMLAttributes<HTMLImageElement>;

export default function ImageLoaded ({source, title = '', hover = false, ...props}: Props) {
    const {t} = useTranslation();
    const fallbackSource = '/images/placeholders/no-image.svg';
    const resolvedSource = source || fallbackSource;
    const isFallbackSource = (value: string) => value === fallbackSource || value.endsWith(fallbackSource);
    const [loading, setLoading] = useState<boolean>(() => !isFallbackSource(resolvedSource));
    const [currentSource, setCurrentSource] = useState<string>(resolvedSource);
    const imgRef = useRef<HTMLImageElement>(null);
    const {
        class: legacyClass,
        className,
        style,
        loading: nativeLoading,
        onLoad,
        onError,
        ...imgProps
    } = props;

    useEffect(() => {
        setLoading(!isFallbackSource(resolvedSource));
        setCurrentSource(resolvedSource);
    }, [resolvedSource]);

    // Ref callback: additional safety net — fires the instant the <img>
    // is placed into the DOM. Catches any remaining edge cases.
    const imgRefCallback = useCallback((node: HTMLImageElement | null) => {
        imgRef.current = node;
        if (node && node.complete && node.naturalWidth > 0) {
            setLoading(false);
        }
    }, []);

    // Belt-and-suspenders: deferred check for extremely fast cache hits
    // that resolve between the ref callback and the first paint.
    useEffect(() => {
        const img = imgRef.current;
        if (!img || !loading) return;

        if (img.complete && img.naturalWidth > 0) {
            setLoading(false);
            return;
        }

        let cancelled = false;
        const rafId = requestAnimationFrame(() => {
            if (!cancelled && img.complete && img.naturalWidth > 0) {
                setLoading(false);
            }
        });

        return () => {
            cancelled = true;
            cancelAnimationFrame(rafId);
        };
    }, [currentSource, loading]);

    const isFallback = isFallbackSource(currentSource);
    const showSkeleton = loading && !isFallback;

    return (
        <div className={clsx(legacyClass, className, 'avd-image-loaded', hover && !loading && 'hover')} style={style}>
            {showSkeleton && <div className="avd-placeholder avd-image-skeleton" aria-hidden="true"></div>}
            {isFallback ? (
                <div className="avd-no-image-fallback" role="img" aria-label={title || t('No image')}>
                    <i className="fa-regular fa-image" aria-hidden="true"></i>
                    <span>{t('No Image')}</span>
                </div>
            ) : (
                <img
                    {...imgProps}
                    ref={imgRefCallback}
                    className={clsx('avd-image-loaded-img', showSkeleton && 'avd-image-loading')}
                    src={currentSource}
                    alt={title}
                    loading={nativeLoading ?? 'lazy'}
                    decoding="async"
                    onLoad={(event) => {
                        setLoading(false);
                        onLoad?.(event);
                    }}
                    onError={(event) => {
                        onError?.(event);

                        if (currentSource !== fallbackSource) {
                            setCurrentSource(fallbackSource);
                            setLoading(false);
                            return;
                        }

                        setLoading(false);
                    }}
                />
            )}
        </div>
    )
}
