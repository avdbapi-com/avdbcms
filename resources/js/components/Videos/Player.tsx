import {useEffect, useRef, useState} from "preact/hooks";
import {viewVideo, player} from "@/api/avdbclip";
import {QueryClient, QueryClientProvider, useMutation, useQuery} from "@tanstack/react-query";
import {Ad} from "@/components/Videos/Ad";
import {Loader, ApiError} from "@/components/Commons";
import {TargetedEvent} from "react";
import {clsx} from "clsx";
import {useTranslation} from "react-i18next";

type Props = {
    video_uuid: string,
}

const VIEW_COUNT_DURATION: number = 1;

type MediaStatus = {
    key: string,
    ready: boolean,
    error: boolean,
};

function mediaType(file: string | null | undefined): string {
    const path = (file || '').split(/[?#]/, 1)[0].toLowerCase();

    if (path.endsWith('.webm')) return 'video/webm';
    if (path.endsWith('.m3u8')) return 'application/vnd.apple.mpegurl';
    if (path.endsWith('.ogv') || path.endsWith('.ogg')) return 'video/ogg';

    // Uploaded files without a public extension are served as MP4 by the API.
    return 'video/mp4';
}

export function Main ({video_uuid} : Props) {

    const {t} = useTranslation();
    const videoElement = useRef<HTMLVideoElement>(null);
    const timeCode = new URLSearchParams(window.location.search).get('t') ?? false;
    const watchedTime = useRef(0);
    const lastTime = useRef<number | null>(null);

    const {
        data: video,
        isLoading,
        isError,
        refetch,
        error,
    } = useQuery({
        queryKey: ['video.player', video_uuid],
        queryFn: () => player(video_uuid),
    });

    const [showAd, setShowAds] = useState<boolean|null>(null);
    const [mediaAttempt, setMediaAttempt] = useState<number>(0);
    const [mediaStatus, setMediaStatus] = useState<MediaStatus>({
        key: '',
        ready: false,
        error: false,
    });

    // Keep readiness tied to the media source rather than the ad phase. The
    // native element stays mounted underneath a preroll, so finishing an ad
    // must not remount it or flash the loading state for one frame.
    const mediaKey = `${video_uuid}:${video?.file ?? ''}:${mediaAttempt}`;
    const isReady = mediaStatus.key === mediaKey && mediaStatus.ready;
    const mediaError = mediaStatus.key === mediaKey && mediaStatus.error;

    const markMediaReady = (event?: TargetedEvent<HTMLVideoElement>) => {
        const media = event?.currentTarget ?? videoElement.current;

        // loadedmetadata only guarantees dimensions. Wait for the first
        // decoded frame (loadeddata/canplay) before revealing the element so
        // fast scrolling cannot expose a black frame before playback is ready.
        if (!media || media.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) {
            return;
        }

        setMediaStatus((current) => current.key === mediaKey && current.ready
            ? current
            : {key: mediaKey, ready: true, error: false});
    };

    const handleMediaError = () => {
        setMediaStatus((current) => current.key === mediaKey && current.error
            ? current
            : {key: mediaKey, ready: false, error: true});
    };

    // Preact does not consistently dispatch a synthetic `onError` from a
    // nested <source> element on every mobile browser. Bind native listeners
    // to both the media element and its source so a failed request cannot
    // leave the player hidden behind an endless loader.
    useEffect(() => {
        const media = videoElement.current;
        const source = media?.querySelector('source');
        if (!media) return;

        const onError = () => handleMediaError();
        media.addEventListener('error', onError);
        source?.addEventListener('error', onError);

        return () => {
            media.removeEventListener('error', onError);
            source?.removeEventListener('error', onError);
        };
    }, [mediaKey, mediaAttempt]);

    const retryMedia = () => {
        setMediaStatus({key: mediaKey, ready: false, error: false});
        setMediaAttempt((attempt) => attempt + 1);
    };

    const {mutate: view} = useMutation({
        mutationFn: () => viewVideo(video_uuid),
        mutationKey: ['videos.view', video_uuid],
    });

    const hasViewed = useRef(false);

    const handleLoadedMetadata = (event: TargetedEvent<HTMLVideoElement>) => {
        const media = event.currentTarget;

        if (timeCode) {
            const parsedTime = parseInt(timeCode, 10);
            if (Number.isFinite(parsedTime) && parsedTime >= 0) {
                media.currentTime = parsedTime;
            }
        }

    };

    const handleTimeUpdate = (event: TargetedEvent<HTMLVideoElement>) => {

        const media = event.currentTarget;

        if (media.paused || media.seeking) {
            lastTime.current = null;
            return;
        }

        const current = media.currentTime;

        if (lastTime.current !== null) {
            const delta = current - lastTime.current;

            if (delta > 0 && delta < 1) {
                watchedTime.current += delta;
            }
        }

        lastTime.current = current;

        if(watchedTime.current > VIEW_COUNT_DURATION && !hasViewed.current) {
             hasViewed.current = true;
             view();
         }
    };

    useEffect(() => {
        watchedTime.current = 0;
        lastTime.current = null;
        hasViewed.current = false;
        setShowAds(null);
        setMediaAttempt(0);
        setMediaStatus({key: '', ready: false, error: false});
    }, [video_uuid]);

    useEffect(() => {
        if (video) {
            const adConfig = (window as any).AD_CONFIG || {};
            const prerollEnabled = adConfig.prerollEnabled === true;
            setShowAds(video.show_ad && prerollEnabled);

            if (video.is_upload18 && (!video.show_ad || !prerollEnabled) && !hasViewed.current) {
                hasViewed.current = true;
                view();
            }
        }
    }, [video]);

    useEffect(() => {
        if (video?.is_upload18 && showAd === false && !hasViewed.current) {
            hasViewed.current = true;
            view();
        }
    }, [showAd]);

    const hasUpload18Source = Boolean(video?.is_upload18 && video.embed_url);
    const hasNativeSource = Boolean(video && !video.is_upload18 && video.file);
    const sourceUnavailable = Boolean(video && !hasUpload18Source && !hasNativeSource);
    const playerState = isError
        ? 'error'
        : !video || isLoading || showAd === null
            ? 'loading'
            : showAd
                ? 'ad'
                : mediaError || sourceUnavailable
                    ? 'unavailable'
                    : isReady || hasUpload18Source
                        ? 'ready'
                        : 'buffering';

    return (
        <div
            className="avd-player-stage position-relative h-100 w-100 bg-dark"
            data-player-state={playerState}
            aria-busy={playerState === 'loading' || playerState === 'buffering'}
        >
            {hasUpload18Source && (
                <div
                    className="avd-player-media-layer position-absolute top-0 start-0 h-100 w-100"
                    aria-hidden={showAd !== false}
                    style={{
                        // Keep the provider mounted during preroll so its
                        // playback session is not torn down when the ad ends.
                        visibility: showAd === false ? 'visible' : 'hidden',
                        opacity: showAd === false ? 1 : 0,
                        pointerEvents: showAd === false ? 'auto' : 'none',
                    }}
                >
                    <iframe
                        src={video?.embed_url ?? undefined}
                        title={t('Video player')}
                        width="100%"
                        height="100%"
                        frameBorder="0"
                        allowFullScreen
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen"
                        referrerPolicy="strict-origin-when-cross-origin"
                        loading="eager"
                        className="w-100 h-100 border-0"
                    />
                </div>
            )}

            {hasNativeSource && (
                <div
                    className="avd-player-media-layer position-absolute top-0 start-0 h-100 w-100"
                    aria-busy={!isReady && !mediaError}
                    aria-hidden={!isReady || showAd !== false}
                    style={{
                        visibility: isReady && showAd === false ? 'visible' : 'hidden',
                        opacity: isReady && showAd === false ? 1 : 0,
                        pointerEvents: isReady && showAd === false ? 'auto' : 'none',
                    }}
                >
                    <video
                        key={mediaKey}
                        ref={videoElement}
                        onTimeUpdate={handleTimeUpdate}
                        onLoadedMetadata={handleLoadedMetadata}
                        onLoadedData={markMediaReady}
                        onCanPlay={markMediaReady}
                        onError={handleMediaError}
                        controls={true}
                        tabIndex={isReady && showAd === false ? 0 : -1}
                        className={clsx("w-100 border border-1 rounded h-100", 'avd-player-native-media')}
                        controlsList="nodownload"
                        poster={video?.thumbnail}
                        onContextMenu={(e: any) => e.preventDefault()}
                        playsInline
                        preload="metadata"
                    >
                        <source src={video?.file ?? undefined} type={mediaType(video?.file)} onError={handleMediaError}/>
                    </video>
                </div>
            )}

            {video && showAd && (
                <div className="avd-player-ad-layer position-absolute top-0 start-0 h-100 w-100" style={{zIndex: 20}}>
                    <Ad setAds={setShowAds} />
                </div>
            )}

            {isError && (
                <div className="position-absolute top-0 start-0 d-flex justify-content-center align-items-center h-100 w-100 bg-dark">
                    <ApiError error={error} refetch={refetch}/>
                </div>
            )}

            {!isError && playerState === 'loading' && (
                <div className="avd-player-status-layer position-absolute top-0 start-0 d-flex justify-content-center align-items-center h-100 w-100 bg-dark" style={{zIndex: 10}}>
                    <Loader/>
                </div>
            )}

            {!isError && video && showAd === false && hasNativeSource && !isReady && !mediaError && (
                <div className="avd-player-status-layer position-absolute top-0 start-0 d-flex justify-content-center align-items-center h-100 w-100 bg-dark" style={{zIndex: 10}}>
                    <Loader/>
                </div>
            )}

            {!isError && video && showAd === false && mediaError && (
                <div className="avd-player-status-layer position-absolute top-0 start-0 d-flex flex-column justify-content-center align-items-center h-100 w-100 bg-dark text-white text-center px-4" style={{zIndex: 15}} role="alert">
                    <div className="fw-bold">{t('Video source is not available')}</div>
                    <button type="button" className="btn btn-primary btn-sm mt-3 px-4" onClick={retryMedia}>
                        {t('Try again')}
                    </button>
                </div>
            )}

            {!isError && video && showAd === false && sourceUnavailable && (
                <div className="avd-player-status-layer position-absolute top-0 start-0 d-flex justify-content-center align-items-center h-100 w-100 bg-dark text-white" style={{zIndex: 15}}>
                    <div className="text-center px-4 fw-bold">{t('Video source is not available')}</div>
                </div>
            )}
        </div>
    )
}

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: false,
        }
    }
});

export function Player(props: Props) {
    return (
        <QueryClientProvider client={queryClient}>
            <Main {...props} />
        </QueryClientProvider>
    )
}
