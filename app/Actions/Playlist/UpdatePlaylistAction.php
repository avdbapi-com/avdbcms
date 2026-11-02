<?php

namespace App\Actions\Playlist;

use App\Http\Requests\Playlist\UpdatePlaylistRequest;
use App\Models\Playlist;
use App\Support\LegacyRowTimes;
use Illuminate\Support\Facades\DB;

/**
 * Saves a playlist (member design 6.7). The editor's save is a change, never a snapshot (studio-4):
 * the playlist keeps what happened to it since the page was opened elsewhere (a video saved to it from a watch
 * page in another tab), and the member's own removals and order apply. The list is written in a few queries whatever
 * its length (one DELETE, one CASE update and one INSERT per 500 rows), never one query per video.
 */
class UpdatePlaylistAction
{
    /** Rows per statement (a CASE update binds two values per row). */
    private const CHUNK = 500;

    /**
     * @return int how many videos of the editor's list were left out (deleted, or not available to the member any more)
     */
    public function execute(UpdatePlaylistRequest $request, Playlist $playlist): int
    {
        $playlist->update($request->safe()->only(['title', 'description', 'status', 'sort']));

        $order = $request->orderIds();
        $leftOut = 0;

        if ($order !== null) {
            $leftOut = $this->mergeVideos($playlist, $request->loadedIds(), $order, $request->allowedIds());
        } else {
            $this->addVideos($playlist, array_values(array_filter(
                array_map('intval', $request->array('videos')),
                fn (int $id) => $id > 0,
            )));
        }

        $playlist->touch();

        return $leftOut;
    }

    /**
     * The editor's save (videos_order + videos_loaded), merged with what the playlist holds now:
     *  - a video of the editor's list stays or comes in, in the editor's order;
     *  - a video the page loaded that is not in the editor's list any more was removed by the member: it goes;
     *  - a video the page loaded that the playlist no longer holds was removed elsewhere meanwhile: it stays out;
     *  - a video the playlist holds that the page did not load was added elsewhere meanwhile: it stays, after the
     *    editor's list (in its own order);
     *  - a new video the member may not add (deleted, another member's private video) is left out and counted.
     * A video keeps the day it was added; positions follow the final order.
     *
     * @param  list<int>  $loaded
     * @param  list<int>  $order
     * @param  array<int, true>  $allowed
     */
    private function mergeVideos(Playlist $playlist, array $loaded, array $order, array $allowed): int
    {
        return DB::transaction(function () use ($playlist, $loaded, $order, $allowed): int {
            $id = (int) $playlist->getKey();
            $rows = DB::table('playlist_has_videos')
                ->where('playlist_id', $id)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'video_id', 'position']);

            $held = [];        // video id => [row id, position], first row of each video
            $duplicates = [];  // row ids of a video held twice (an older client's double insert)

            foreach ($rows as $row) {
                $videoId = (int) $row->video_id;

                if (isset($held[$videoId])) {
                    $duplicates[] = (int) $row->id;
                } else {
                    $held[$videoId] = [(int) $row->id, (int) $row->position];
                }
            }

            $wasLoaded = array_fill_keys($loaded, true);
            $final = [];
            $leftOut = 0;

            foreach ($order as $videoId) {
                if (isset($final[$videoId])) {
                    continue;
                }

                if (isset($held[$videoId])) {
                    $final[$videoId] = true;
                } elseif (isset($wasLoaded[$videoId])) {
                    continue; // removed elsewhere after the page was opened: it stays out
                } elseif (isset($allowed[$videoId])) {
                    $final[$videoId] = true;
                } else {
                    $leftOut++;
                }
            }

            foreach (array_keys($held) as $videoId) {
                if (! isset($wasLoaded[$videoId]) && ! isset($final[$videoId])) {
                    $final[$videoId] = true; // added elsewhere after the page was opened: it stays
                }
            }

            $final = array_keys($final);
            $remove = array_values(array_diff(array_keys($held), $final));

            foreach (array_chunk($remove, self::CHUNK) as $chunk) {
                DB::table('playlist_has_videos')->where('playlist_id', $id)->whereIn('video_id', $chunk)->delete();
            }

            foreach (array_chunk($duplicates, self::CHUNK) as $chunk) {
                DB::table('playlist_has_videos')->whereIn('id', $chunk)->delete();
            }

            $moves = [];
            $inserts = [];
            // "Date added": the site's clock once the update has noted the older rows (App\Support\LegacyRowTimes)
            $added = LegacyRowTimes::playlistStamp($playlist);

            foreach ($final as $position => $videoId) {
                if (isset($held[$videoId])) {
                    if ($held[$videoId][1] !== $position) {
                        $moves[$videoId] = $position;
                    }
                } else {
                    $inserts[] = ['playlist_id' => $id, 'video_id' => $videoId, 'position' => $position] + $added;
                }
            }

            $this->movePositions($id, $moves);

            foreach (array_chunk($inserts, self::CHUNK) as $chunk) {
                DB::table('playlist_has_videos')->insert($chunk);
            }

            return $leftOut;
        });
    }

    /**
     * New positions in one CASE update per 500 rows.
     *
     * @param  array<int, int>  $moves  video id => position
     */
    private function movePositions(int $playlistId, array $moves): void
    {
        if ($moves === []) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable('playlist_has_videos');
        $position = $grammar->wrap('position');
        $video = $grammar->wrap('video_id');
        $playlist = $grammar->wrap('playlist_id');

        foreach (array_chunk($moves, self::CHUNK, true) as $chunk) {
            $cases = [];
            $bindings = [];

            foreach ($chunk as $videoId => $newPosition) {
                $cases[] = 'WHEN ? THEN ?';
                $bindings[] = (int) $videoId;
                $bindings[] = (int) $newPosition;
            }

            $ids = array_map('intval', array_keys($chunk));

            DB::update(
                "UPDATE {$table} SET {$position} = CASE {$video} ".implode(' ', $cases)." ELSE {$position} END"
                ." WHERE {$playlist} = ? AND {$video} IN (".implode(', ', array_fill(0, count($ids), '?')).')',
                array_merge($bindings, [$playlistId], $ids),
            );
        }
    }

    /**
     * A request without the editor's list (another client, an older page) can only ADD videos: the ones it names that
     * the playlist does not hold yet go at the end, in one insert. Nothing it did not name is ever removed (before, a
     * 30-video playlist saved from a page that had loaded 24 kept 24: studio-4).
     *
     * @param  list<int>  $ids
     */
    private function addVideos(Playlist $playlist, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($playlist, $ids): void {
            $pivot = DB::table('playlist_has_videos')->where('playlist_id', $playlist->getKey());
            $held = array_fill_keys((clone $pivot)->pluck('video_id')->map(fn ($id) => (int) $id)->all(), true);
            $last = (clone $pivot)->max('position');
            $position = is_null($last) ? 0 : ((int) $last) + 1;
            $added = LegacyRowTimes::playlistStamp($playlist);
            $rows = [];

            foreach ($ids as $id) {
                if (isset($held[$id])) {
                    continue;
                }

                $rows[] = ['playlist_id' => $playlist->getKey(), 'video_id' => $id, 'position' => $position++] + $added;
                $held[$id] = true;
            }

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('playlist_has_videos')->insert($chunk);
            }
        });
    }
}
