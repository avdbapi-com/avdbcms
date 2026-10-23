import Hls from "hls.js";
import {useCallback, useEffect, useRef, useState} from "preact/hooks";
import {useTranslation} from "react-i18next";
import {HtmlAdSlot} from "./AdSlot";

type Props = {
    setAds?: (ads: boolean) => void
    onComplete?: () => void
}

type VideoAdCreativeProps = {
    source: string
    title: string
    onEnded: () => void
    onFailed: () => void
    clickLabel?: string
    onClick?: () => void
}

type PrerollAd = {
    src: string
    click: string
}

function isHlsSource(source: string): boolean {
    const normalized = source.split('?')[0].toLowerCase();
    return normalized.endsWith('.m3u8');
}

function parseMediaSources(value: unknown): string[] {
    if (Array.isArray(value)) {
        return value
            .map(source => String(source).trim())
            .filter(Boolean);
    }

    return String(value || '')
        .split(/[\r\n,]+/)
        .map(source => source.trim())
        .filter(Boolean);
}

function safeClickUrl(value: unknown): string {
    const url = String(value || '').trim();

    return /^https?:\/\//i.test(url) ? url : '';
}

function parsePrerollAds(config: any): PrerollAd[] {
    if (Array.isArray(config?.prerollAds) && config.prerollAds.length > 0) {
        return config.prerollAds
            .map((ad: any) => ({src: String(ad?.src || '').trim(), click: safeClickUrl(ad?.click)}))
            .filter((ad: PrerollAd) => ad.src !== '');
    }

    return parseMediaSources(config?.prerollMediaUrls || config?.prerollMediaUrl)
        .map(src => ({src, click: ''}));
}

function randomPrerollAd(config: any): PrerollAd | null {
    const ads = parsePrerollAds(config);

    if (ads.length === 0) {
        return null;
    }

    return ads[Math.floor(Math.random() * ads.length)];
}

function VideoAdCreative({source, title, onEnded, onFailed, clickLabel, onClick}: VideoAdCreativeProps) {
    const videoRef = useRef<HTMLVideoElement>(null);

    useEffect(() => {
        const video = videoRef.current;

        if (!video || !source) {
            return;
        }

        let hls: Hls | null = null;
        const play = () => {
            video.play().catch(() => undefined);
        };

        video.muted = true;
        video.playsInline = true;

        if (isHlsSource(source)) {
            if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = source;
                video.addEventListener('loadedmetadata', play, {once: true});
            } else if (Hls.isSupported()) {
                hls = new Hls({
                    enableWorker: true,
                    lowLatencyMode: false,
                    capLevelToPlayerSize: false,
                    startLevel: -1,
                    abrEwmaDefaultEstimate: 8000000,
                });

                hls.on(Hls.Events.MEDIA_ATTACHED, () => {
                    hls?.loadSource(source);
                });

                hls.on(Hls.Events.MANIFEST_PARSED, play);
                hls.on(Hls.Events.ERROR, (_event, data) => {
                    if (data.fatal) {
                        onFailed();
                    }
                });

                hls.attachMedia(video);
            } else {
                onFailed();
            }
        } else {
            video.src = source;
            video.addEventListener('loadedmetadata', play, {once: true});
        }

        return () => {
            video.removeEventListener('loadedmetadata', play);
            hls?.destroy();
            video.pause();
            video.removeAttribute('src');
            video.load();
        };
    }, [source, onFailed]);

    const clickable = typeof onClick === 'function';

    return (
        <div
            className={`avd-preroll-ad__video-shell${clickable ? ' avd-preroll-ad__video-shell--clickable' : ''}`}
            role={clickable ? 'link' : undefined}
            tabIndex={clickable ? 0 : undefined}
            aria-label={clickable ? clickLabel : undefined}
            onClick={clickable ? onClick : undefined}
            onKeyDown={clickable ? (event: KeyboardEvent) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onClick?.();
                }
            } : undefined}
        >
            <video
                ref={videoRef}
                className="avd-preroll-ad__video"
                title={title}
                autoPlay
                muted
                playsInline
                preload="auto"
                onEnded={onEnded}
                onError={onFailed}
            />
        </div>
    );
}

export function Ad ({setAds, onComplete} : Props) {

    const adConfig = (window as any).AD_CONFIG || {};
    const configuredDuration = Number(adConfig.prerollDuration || 5);
    const duration = Number.isFinite(configuredDuration) ? Math.max(1, Math.min(60, configuredDuration)) : 5;
    const skipAfter = Math.min(3, Math.max(1, duration - 1));
    const adCode = adConfig.prerollCode || '';
    const [prerollAd] = useState<PrerollAd | null>(() => randomPrerollAd(adConfig));
    const mediaUrl = prerollAd?.src || '';
    const clickUrl = prerollAd?.click || '';

    const [counter, setCounter] = useState<number>(duration);
    const [mediaFailed, setMediaFailed] = useState<boolean>(false);

    const { t } = useTranslation();

    const finishAd = useCallback(() => {
        setAds?.(false);
        onComplete?.();
    }, [setAds, onComplete]);

    const handleMediaFailed = useCallback(() => {
        setMediaFailed(true);
    }, []);

    const openAdvertiser = useCallback(() => {
        if (clickUrl) {
            window.open(clickUrl, '_blank', 'noopener,noreferrer');
        }
    }, [clickUrl]);

    useEffect(() => {
        setMediaFailed(false);
    }, [mediaUrl]);

    useEffect(() => {
        if(counter <= 0) {
            finishAd();
            return;
        }

        const timer = window.setTimeout(() => setCounter(current => current - 1), 1000);
        return () => window.clearTimeout(timer);
    }, [counter, finishAd]);

    const elapsed = duration - counter;
    const canSkip = elapsed >= skipAfter;
    const skipWait = Math.max(0, skipAfter - elapsed);
    const skipLabel = canSkip ? t('Skip ad') : `${t('Skip in')} ${skipWait}s`;
    const hasPlayableMedia = mediaUrl !== '' && !mediaFailed;

    return (
        <div className={`avd-preroll-ad avd-preroll-ad--preroll ${hasPlayableMedia ? 'avd-preroll-ad--video-media' : 'avd-preroll-ad--html-media'}`}>
            {hasPlayableMedia ? (
                <VideoAdCreative
                    source={mediaUrl}
                    title={t('Advertisement')}
                    onEnded={finishAd}
                    onFailed={handleMediaFailed}
                    clickLabel={t('Visit advertiser')}
                    onClick={clickUrl ? openAdvertiser : undefined}
                />
            ) : adCode ? (
                <HtmlAdSlot html={adCode} className="ad-slot ad-slot-preroll avd-preroll-ad__creative" ariaLabel={t('Advertisement')} />
            ) : (
                <div className="avd-preroll-ad__fallback">
                    <h3>{t('Your Ad Could Be Here')}</h3>
                    <p>{t('Do you want to display your ad before each video ?')}</p>
                    <a className="btn btn-primary btn-sm" href="/contact">{t('Contact us today')}</a>
                </div>
            )}
            <div className="avd-preroll-ad__badge">
                <span>{t('Ad')}</span>
                {counter > 0 && <strong>{counter}</strong>}
            </div>
            {hasPlayableMedia && clickUrl && (
                <a
                    className="avd-preroll-ad__cta"
                    href={clickUrl}
                    target="_blank"
                    rel="noopener noreferrer sponsored nofollow"
                >
                    <span>{t('Visit advertiser')}</span>
                    <i className="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                </a>
            )}
            <button
                type="button"
                className="avd-preroll-ad__skip"
                disabled={!canSkip}
                onClick={finishAd}
            >
                {skipLabel}
            </button>
        </div>

    )
}
