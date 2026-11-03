<?php

namespace App\Http\Controllers;

use App\Actions\Video\ShowVideoAction;
use App\Enums\VideoStatus;
use App\Models\Thumbnail;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\HiddenRouteModels;
use App\Support\LinkProof;
use App\Support\SendFile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class VideoController extends Controller
{
    /** The only types a stored thumbnail is answered with (thumbnailContentType()). */
    public const THUMBNAIL_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    public function show (string $slug, string $uuid, ShowVideoAction $showVideoAction, SeoService $seo) : View|RedirectResponse|Response {

        // Under a custom video_url_pattern a canonical URL can also end in '-' + 10 digits that are not a video id
        // (e.g. {id}-{slug} with a numeric slug tail), so an exact canonical match is tried before the id lookup.
        $video = $seo->resolveVideoPath(request()->path());

        if (!$video || (!$seo->isCanonicalVideoRequest($video) && (string) $video->uuid !== $uuid)) {
            // '{slug}-{uuid}' link: the default pattern, or one published before the pattern was changed
            $video = Video::query()->with(['user', 'category'])->where('uuid', $uuid)->first() ?? $video;
        }

        if (!$video) {
            abort(404);
        }

        return $this->showVideo($video, $showVideoAction, $seo);
    }

    public function seoShow(string $seoVideoPath, ShowVideoAction $showVideoAction, SeoService $seo): View|RedirectResponse|Response
    {
        $video = $seo->resolveVideoPath($seoVideoPath);

        if (!$video) {
            abort(404);
        }

        return $this->showVideo($video, $showVideoAction, $seo);
    }

    private function showVideo(Video $video, ShowVideoAction $showVideoAction, SeoService $seo): View|RedirectResponse|Response
    {
        // Another member's video that is not out (private, a draft, planned for later, still uploading) answers exactly as
        // a link that does not exist, as on the apps' addresses (round 3 R2R-07: asked for JSON, the watch page said
        // "This video is private" against an empty message for a made-up link). A removed video keeps its 410.
        abort_unless(HiddenRouteModels::isOutFor($video, auth()->user()), 404);

        Gate::authorize('show', $video);

        if (!$seo->isCanonicalVideoRequest($video)) {
            // locale_url(): an old '/vi/video/...' link lands on the Vietnamese canonical page, not the default-language one
            return redirect()->to(locale_url($seo->videoUrl($video, request()->query())), 301);
        }

        // 1.2.3 round 3 (R3-017 and the decision of 29 Oct): the page opens in every language, as the lists show the video
        // there, but an AVDBAPI import is indexable only in English, the default language and the languages of its
        // translations (App\Support\VideoLanguages): its hreflang links name only those (the layout reads them), and its
        // page in another language answers "noindex, follow" (the header below; the layout's robots meta says the same)
        $translatedIn = \App\Support\VideoLanguages::languagesOf($video); // one query, for an AVDBAPI import only

        // The hreflang links name the pages that are their own canonical (VideoLanguages::canonicalIn(), the rule of the
        // sitemaps): an import's indexable pages; any other video's default-language page only (1.2.3 refresh,
        // CLF-SEO-01: its page in every language names the default-language page, which the links offered as 18 pages).
        request()->attributes->set(\App\Support\VideoLanguages::HREFLANG, \App\Support\VideoLanguages::canonicalIn($video, array_keys(config('languages', [])), $translatedIn));

        $robots = \App\Support\VideoLanguages::robots($video, app()->getLocale(), $translatedIn);

        if ($robots !== null) {
            request()->attributes->set(\App\Support\VideoLanguages::ROBOTS, $robots);
        }

        // Another member's unlisted video opened by its link: this browser may save it (round 3 R2R-06, LinkProof).
        LinkProof::rememberVideo($video);

        $view = view('videos.show', $showVideoAction->data($video));

        if (auth()->guest() && !session()->has('errors') && !session()->has('status')) {
            $response = response($view)
                ->header('Cache-Control', 'public, max-age=120, s-maxage=600, stale-while-revalidate=1200')
                ->header('Cloudflare-CDN-Cache-Control', 'max-age=600')
                ->header('CDN-Cache-Control', 'max-age=600')
                ->header('Vary', 'Accept-Encoding, Cookie');

            return $robots !== null ? $response->header('X-Robots-Tag', $robots) : $response;
        }

        return $robots !== null ? response($view)->header('X-Robots-Tag', $robots) : $view;
    }


    public function file(Video $video, Request $request): SymfonyResponse
    {
        $path = Video::VIDEO_FOLDER . '/' . $video->file;
        $absolutePath = Storage::disk('local')->path($path);

        if (!file_exists($absolutePath)) {
            abort(404);
        }

        // Route large-file streaming through X-Accel-Redirect / X-Sendfile /
        // X-LiteSpeed-Location whenever the web server supports it. Falls
        // back to a regular BinaryFileResponse on unknown hosts / Windows.
        $response = SendFile::stream(
            $absolutePath,
            $request,
            ['Content-Type' => Video::MIMETYPE],
            Storage::disk('local')->path('')
        );

        // Never kept by a shared cache (a CDN in front of the site): a private video is its owner's, and a public one can
        // become private. Every answer used to carry the session cookie, which kept it private; the file no longer writes
        // the session (round 3 SB-X1, SB-V1-01). The browser still keeps what it downloaded; the answers the web server
        // completes (X-Accel-Redirect, X-Sendfile) were private already.
        if ($response->headers->hasCacheControlDirective('public')) {
            $response->setPrivate();
        }

        return $response;
    }

    public function thumbnail (Video $video): Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        if (!$video->thumbnail || empty($video->thumbnail->file)) {
            return $this->placeholderThumbnailResponse();
        }

        $path = Video::THUMBNAIL_FOLDER.'/'.$video->thumbnail->file;

        return $this->serveThumbnailFile($path, $video);
    }

    public function thumbnails (Video $video, Thumbnail $thumbnail): Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        if ($thumbnail->video_id !== $video->id) {
            abort(404);
        }

        if (empty($thumbnail->file)) {
            return $this->placeholderThumbnailResponse();
        }

        $path = Video::THUMBNAIL_FOLDER.'/'.$thumbnail->file;

        return $this->serveThumbnailFile($path, $video);
    }

    private function serveThumbnailFile(string $storagePath, ?Video $video): SymfonyResponse
    {
        $disk = Storage::disk('local');
        $absolutePath = $disk->path($storagePath);

        if (!$disk->exists($storagePath) || !file_exists($absolutePath)) {
            return $this->placeholderThumbnailResponse();
        }

        $request = request();
        $lastModified = filemtime($absolutePath);
        $etag = sprintf('"%s"', md5($absolutePath . $lastModified));

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304)->withHeaders([
                'ETag' => $etag,
                ...$this->thumbnailCacheHeaders($video),
            ]);
        }

        $ifModifiedSince = $request->headers->get('If-Modified-Since');
        if ($ifModifiedSince && strtotime($ifModifiedSince) >= $lastModified) {
            return response('', 304)->withHeaders([
                'ETag' => $etag,
                ...$this->thumbnailCacheHeaders($video),
            ]);
        }

        return response()->file($absolutePath, [
            'Content-Type' => self::thumbnailContentType((string) ($disk->mimeType($storagePath) ?: '')),
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
            ...$this->thumbnailCacheHeaders($video),
        ]);
    }

    /**
     * A stored thumbnail is only ever answered as a raster picture (security plan S20): the type is read from the file's
     * content, and anything that is not one of THUMBNAIL_TYPES (HTML or script behind a JPEG marker, an SVG) is answered
     * as image/jpeg with nosniff, so a browser shows at most a broken picture and never runs it as a page.
     */
    public static function thumbnailContentType(string $detected): string
    {
        $type = strtolower(trim(explode(';', $detected, 2)[0]));

        return in_array($type, self::THUMBNAIL_TYPES, true) ? $type : 'image/jpeg';
    }

    private function placeholderThumbnailResponse(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return response()->file(public_path('images/placeholders/no-image.svg'), [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function thumbnailCacheHeaders(?Video $video): array
    {
        if ($video?->is_public && !$video->trashed()) {
            return ['Cache-Control' => 'public, max-age=604800, immutable'];
        }

        return ['Cache-Control' => 'private, no-store'];
    }


    public function embed (Video $video): View
    {
        // Check admin setting: allow_embedding
        if (!config('site.allow_embedding', true)) {
            return view('videos.embed.disabled');
        }

        // Another member's unlisted video opened by its link: this browser may save it (round 3 R2R-06, LinkProof).
        LinkProof::rememberVideo($video);

        return view(match ($video->real_status) {
            VideoStatus::PUBLIC, VideoStatus::UNLISTED => 'videos.embed.public',
            VideoStatus::PRIVATE => 'videos.embed.private',
            VideoStatus::BANNED  => 'videos.embed.banned',
            default              => 'videos.embed.missing'
        },[
            'video' => $video
        ]);
    }

    public function random(SeoService $seo): RedirectResponse
    {
        $video = Video::query()
            ->active()
            ->forLocale()
            ->inRandomOrder()
            ->first()
            ?? Video::query()->active()->inRandomOrder()->first()
            ?? Video::query()->inRandomOrder()->first();

        if (! $video) {
            return redirect()->to(route('pages.home'))->with('error', __('No videos available.'));
        }

        $url = function_exists('locale_url')
            ? locale_url($seo->videoUrl($video))
            : $seo->videoUrl($video);

        return redirect()->to($url);
    }
}
