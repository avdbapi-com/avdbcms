<?php

namespace App\Http\Controllers\Api\Private;

use App\Http\Requests\Video\ProgressUpdateRequest;
use App\Models\Video;
use App\Services\PlaybackProgressSignalService;
use Illuminate\Http\JsonResponse;

class PlaybackProgressController
{
    public function update(ProgressUpdateRequest $request, Video $video, PlaybackProgressSignalService $progress): JsonResponse
    {
        $signal = $progress->ingest(
            $request->user(),
            $video,
            $request->positionSeconds(),
            $request->durationSeconds(),
            $request->playedAt()
        );

        if (! $signal) {
            return response()->json(['stored' => false], 202);
        }

        return response()->json([
            'stored' => true,
            'position_seconds' => $signal->position_seconds,
            'duration_seconds' => $signal->duration_seconds,
            'progress_basis_points' => $signal->progress_basis_points,
            'last_played_at' => $signal->last_played_at?->toISOString(),
        ]);
    }
}
