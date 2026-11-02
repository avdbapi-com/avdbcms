<?php

namespace App\Actions\Video;

use App\Enums\VideoStatus;
use App\Http\Requests\Video\UpdateVideoRequest;
use App\Models\Playlist;
use App\Models\Video;
use App\Services\SeoService;
use App\Services\ThumbnailService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UpdateVideoAction
{
    public function execute(UpdateVideoRequest $request, Video $video): void
    {
        // ValidatedInput::except() answers a plain array
        $validated = $request->safe()->except(['playlists']);
        $validated = array_merge($validated, [
            'slug' => $request->string('title')->slug(),
            'scheduled_at' => $this->determineScheduledAt($request),
            'published_at' => $this->determinePublishedAt($request, $video),
        ]);

        $video->update($validated);

        // The playlists field is part of the form (Video details prints it, or its check-box list, or the marker when the
        // member has no playlist yet): only then is the member's choice applied. Another client that does not send it
        // leaves the playlists as they are.
        if ($request->has('playlists') || $request->has('playlists_present')) {
            $this->syncOwnPlaylists($video, $request->array('playlists'));
        }

        ThumbnailService::save($request);

        app(SeoService::class)->submitVideoToIndexNow($video->fresh(['user', 'category']));
    }

    private function determineScheduledAt(UpdateVideoRequest $request): ?string
    {
        return match($request->integer('status')) {
            VideoStatus::PLANNED->value => $request->date('scheduled_at'),
            default => null,
        };
    }

    private function determinePublishedAt(UpdateVideoRequest $request, Video $video): ?string
    {
        // Published date is the first date that video become public, this data never be updated after first publication

        if ($video->published_at?->isPast()) {
            return $video->published_at;
        }

        return match($request->integer('status')) {
            VideoStatus::PUBLIC->value => now(),
            VideoStatus::PLANNED->value => $request->date('scheduled_at'),
            default => null,
        };
    }

    /**
     * studio-3 (data loss): saving a video changes only the member's OWN playlists (the ones Video details offers: their
     * deletable playlists). The video is added at the end of a newly chosen playlist and taken out of an unchosen one of
     * theirs; every other playlist that holds it (another member's Watch later, Liked videos or playlists, the member's
     * own Watch later) is never touched. Before, sync() on the whole relation removed the video from all of them.
     *
     * @param  array<int, mixed>  $playlistIds
     */
    private function syncOwnPlaylists(Video $video, array $playlistIds): void
    {
        $own = Playlist::query()
            ->where('user_id', Auth::id())
            ->where('is_deletable', true)
            ->withMax('videos as last_position', 'playlist_has_videos.position')
            ->get()
            ->keyBy('id');

        if ($own->isEmpty()) {
            return;
        }

        $wanted = collect($playlistIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $own->has($id))
            ->unique()
            ->values();

        $current = DB::table('playlist_has_videos')
            ->where('video_id', $video->id)
            ->whereIn('playlist_id', $own->keys()->all())
            ->pluck('playlist_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $add = $wanted->diff($current);
        $remove = $current->diff($wanted);

        DB::transaction(function () use ($video, $own, $add, $remove): void {
            foreach ($add as $playlistId) {
                $last = $own->get($playlistId)?->last_position;
                $own->get($playlistId)?->videos()->attach($video->id, [
                    'position' => is_null($last) ? 0 : ((int) $last) + 1,
                ]);
            }

            if ($remove->isNotEmpty()) {
                DB::table('playlist_has_videos')
                    ->where('video_id', $video->id)
                    ->whereIn('playlist_id', $remove->all())
                    ->delete();
            }
        });
    }
}
