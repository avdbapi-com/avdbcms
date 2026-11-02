<?php

namespace App\Http\Requests\Video;

use App\Playlists\Types\LikedVideosPlaylist;
use App\Support\LinkProof;
use App\Support\ShownToMember;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        return true;
    }

    /**
     * Indicates if the validator should stop on the first rule failure.
     *
     * @var bool
     */
    protected $stopOnFirstFailure = true;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules() : array
    {
        return [
            'video_id' => [
                'required',
                // One id: a list of ids passed "exists" and broke the controller's toggle (a 500).
                'integer',
                // A deleted video is answered like an id that does not exist: before, the rule let it through and the
                // controller answered 404 with another text, which told which deleted ids exist (R1FX-REV-5).
                Rule::exists('videos', 'id')->whereNull('deleted_at')->where(fn (Builder $query) => $this->watchableOrInThePlaylist($query)),
            ],
            'playlist_id' => [
                'required',
                'integer',
                Rule::exists('playlists', 'id')->where(function (Builder $query){
                    return $query
                        ->where('user_id', $this->user()->id)
                        ->whereNot('title', LikedVideosPlaylist::getName());
                })
            ],
        ];
    }

    /**
     * The videos the Save dialog accepts (round 2a R2A-061): what the watch page shows this member, by its own rule
     * (App\Support\ShownToMember::videos(), the rule of like and dislike too: VideoPolicy::show), or a video already in
     * the chosen playlist of the member, which the toggle takes out again also after it went private (as Watch later,
     * R1-168). The rule accepted every public or unlisted status: another member's video with a date to come, or still
     * uploading, was saved (201) while its page answered 404. Written as one query, so an id the member may not see is
     * refused exactly like one that does not exist: same answer, same work.
     * Round 3 R2R-06: another member's UNLISTED video only with proof that the member has its link (App\Support\LinkProof:
     * its watch or embed page opened in this browser, `video_uuid` = its link token from the apps, or the member's own
     * lists or history showing it already); the playlist it is in already can still take it out.
     */
    private function watchableOrInThePlaylist(Builder $query): void
    {
        $user = $this->user();
        $userId = (int) $user->getAuthIdentifier();
        $playlistId = filter_var($this->input('playlist_id'), FILTER_VALIDATE_INT);
        $uuid = is_string($this->input('video_uuid')) ? $this->input('video_uuid') : null;

        $query->where(function (Builder $shown) use ($user, $uuid): void {
            ShownToMember::videos($shown, $user);
            LinkProof::savable($shown, $user, $uuid);
        });

        if ($playlistId !== false && $playlistId > 0) {
            $query->orWhereExists(fn (Builder $row) => $row->selectRaw('1')->from('playlist_has_videos')
                ->join('playlists', 'playlists.id', '=', 'playlist_has_videos.playlist_id')
                ->whereColumn('playlist_has_videos.video_id', 'videos.id')
                ->where('playlist_has_videos.playlist_id', $playlistId)
                ->where('playlists.user_id', $userId));
        }
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        // The same text for a missing, private and deleted video (and for a playlist that is not the member's), in the
        // member's language: Save to playlist shows it as is.
        return [
            'video_id.exists' => __('This video can no longer be saved.'),
            'playlist_id.exists' => __('This playlist no longer exists.'),
        ];
    }
}
