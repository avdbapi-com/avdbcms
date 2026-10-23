// A card placeholder. The first ones of an early feed can show the first row's pictures (`thumb`) while the cards load:
// eagerly (the first two with fetchpriority=high) only when the page has no hero photo of its own (`eager`), else lazily,
// exactly as the server skeleton loads them.
// KEEP IN SYNC with the server skeleton of resources/views/components/videos-area.blade.php.
export function VideoSkeleton({thumb, eager = false, priority = false}: {thumb?: string; eager?: boolean; priority?: boolean}) {
    return (
        <div className="col">
            <div className="avd-video-card">
                {thumb ? (
                    <div className="avd-placeholder avd-placeholder-thumb avd-has-photo">
                        <img src={thumb} alt="" width={1280} height={720} loading={eager ? 'eager' : 'lazy'} decoding="async" {...(eager && priority ? {fetchpriority: 'high'} : {})}/>
                    </div>
                ) : (
                    <div className="avd-placeholder avd-placeholder-thumb"></div>
                )}
                <div className="avd-card-body">
                    <div className="avd-placeholder avd-placeholder-avatar"></div>
                    <div className="d-flex flex-column gap-2 pt-1">
                        <div className="avd-placeholder avd-placeholder-line w-95"></div>
                        <div className="avd-placeholder avd-placeholder-line w-75"></div>
                        <div className="avd-placeholder avd-placeholder-line w-50"></div>
                    </div>
                </div>
            </div>
        </div>
    )
}
