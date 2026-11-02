<?php

namespace App\Http\Requests\Playlist;

use App\Enums\CustomPlaylistType;
use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Models\Playlist;
use App\Models\Video;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Saving a playlist (member design 6.7). Two ways to send its videos:
 *
 *  - the studio's editor (users/playlists/partials/editor, Playlist.tsx) sends what the member CHANGED, never a
 *    snapshot (studio-4): videos_order = the list as the editor ends it, in its order, and
 *    videos_loaded = the list the page loaded, each one comma-separated field ("12,5,9"), so a long playlist is two
 *    fields whatever its size (PHP's max_input_vars, 1000 by default, used to cut a form that carried one field per
 *    video). UpdatePlaylistAction merges it with what the playlist holds NOW (a video saved to it from a watch page in
 *    the meantime stays);
 *  - any other client sends videos[] and can only ADD videos (nothing it did not name is ever removed).
 *
 * The ids are checked together, in a few queries (one per thousand ids), not two or three queries per id: a video
 * the member may put into the playlist is one anybody can watch, one of the member's own, or one the playlist already
 * holds (a video that went private since it was added keeps the playlist saveable). With videos[], another member's
 * private video fails like an id that does not exist (the answer never tells that it exists); in the editor's list such
 * an id is left out the same way as a deleted video, and the page says how many were left out.
 */
class UpdatePlaylistRequest extends FormRequest
{
    /** At most this many ids in one list field (a playlist this long still saves in a few queries). */
    public const MAX_IDS = 20000;

    /** @var array<int, true>|null the ids the member may keep or add (memo of allowedIds()) */
    private ?array $allowed = null;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // A browser posts a textarea's line breaks as CRLF; the page counts one character each (StudioFormat::lineBreaks).
        if (is_string($this->input('description'))) {
            $this->merge(['description' => \App\Support\Studio\StudioFormat::lineBreaks($this->input('description'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        // The editor's two list fields: ids of 1 to 20 digits separated by commas, checked by isIdList() (no regular
        // expression: see there).
        $list = ['nullable', 'string', 'max:'.(self::MAX_IDS * 21), function (string $attribute, mixed $value, \Closure $fail): void {
            if (! self::isIdList($value)) {
                $fail($this->messages()['videos_order.*']);
            }
        }];

        return [
            'title' => [
                'required',
                'string',
                'max:'.config('validation.playlist.title.max'),
                Rule::notIn(CustomPlaylistType::get())
            ],
            'description' => [
                'nullable',
                'string',
                'max:'.config('validation.playlist.description.max')
            ],
            'status' => [
                'required',
                new Enum(PlaylistStatus::class)
            ],
            'sort' => [
                'required',
                new Enum(PlaylistSort::class)
            ],
            'videos' => 'nullable|array|max:'.self::MAX_IDS,
            'videos.*' => [
                'numeric',
                $this->videoTheMemberMayAdd(...),
            ],
            'videos_order' => $list,
            'videos_loaded' => $list,
        ];
    }

    /**
     * A videos[] entry the member may put into the playlist (see the class comment). Another member's private video
     * fails like an id that does not exist.
     */
    public function videoTheMemberMayAdd(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! isset($this->allowedIds()[(int) $value])) {
            $fail($this->messages()['videos.*.exists']);
        }
    }

    /**
     * The editor's list as it ends (videos_order), in its order, each id once; null when the request does not come from
     * the editor (then videos[] can only add).
     *
     * @return list<int>|null
     */
    public function orderIds(): ?array
    {
        return $this->has('videos_order') ? self::ids($this->input('videos_order')) : null;
    }

    /**
     * The list the editor loaded (videos_loaded): what it may remove. A video the playlist holds that is not in it was
     * added elsewhere after the page was opened, and stays.
     *
     * @return list<int>
     */
    public function loadedIds(): array
    {
        return self::ids($this->input('videos_loaded'));
    }

    /**
     * The ids of this request (videos[] and the editor's list) the member may keep or add, as keys: the ones the
     * playlist holds (an existing video), and among the others those anybody can watch or the member's own. One query
     * for the held ones, one per thousand others.
     *
     * @return array<int, true>
     */
    public function allowedIds(): array
    {
        if ($this->allowed !== null) {
            return $this->allowed;
        }

        $posted = is_array($this->input('videos')) ? $this->input('videos') : [];
        $ids = array_values(array_unique(array_merge(
            array_map('intval', array_filter($posted, fn ($id) => is_numeric($id))),
            $this->orderIds() ?? [],
        )));
        $ids = array_values(array_filter($ids, fn (int $id) => $id > 0));

        if ($ids === []) {
            return $this->allowed = [];
        }

        $allowed = [];
        $playlist = $this->route('playlist');

        if ($playlist instanceof Playlist) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('playlist_has_videos')
                    ->join('videos', 'videos.id', '=', 'playlist_has_videos.video_id')
                    ->where('playlist_has_videos.playlist_id', $playlist->getKey())
                    ->whereIn('playlist_has_videos.video_id', $chunk)
                    ->whereNull('videos.deleted_at')
                    ->pluck('playlist_has_videos.video_id')
                    ->each(function ($id) use (&$allowed): void {
                        $allowed[(int) $id] = true;
                    });
            }
        }

        $others = array_values(array_filter($ids, fn (int $id) => ! isset($allowed[$id])));

        foreach (array_chunk($others, 1000) as $chunk) {
            Video::query()
                ->whereIn('videos.id', $chunk)
                ->where(fn ($query) => $query->active()->orWhere('videos.user_id', Auth::id()))
                ->pluck('videos.id')
                ->each(function ($id) use (&$allowed): void {
                    $allowed[(int) $id] = true;
                });
        }

        return $this->allowed = $allowed;
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        // studio-27: every message in the member's language (they were English sentences built in code)
        return [
            'videos.required' => __('Add at least one video.'),
            'videos.*.exists' => __('One of the videos is not available any more. Remove it and save again.'),
            'videos.*.numeric' => __('One of the videos is not available any more. Remove it and save again.'),
            'videos_order.*' => __('The list of videos could not be read. Reload the page and try again.'),
            'videos_loaded.*' => __('The list of videos could not be read. Reload the page and try again.'),
            'title.not_in' => __('This name belongs to a list every member has (:names). Pick another name.', ['names' => CustomPlaylistType::nameToString()]),
        ];
    }

    /**
     * An editor list field as the editor writes it: ids of 1 to 20 digits separated by commas ("12,5,9"), no empty
     * part. Checked with string functions, never a regular expression: the pattern this replaces,
     * ^\d{1,20}(,\d{1,20})*$, repeats a group once per id, and PCRE's JIT stack ran out at about 4,900 ids
     * (preg_match returned false, so the rule failed), so a playlist of 5,000 videos could never be saved from the
     * editor. This check is linear in the length of the field and has no such limit.
     */
    public static function isIdList(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strspn($value, '0123456789,') !== strlen($value)) {
            return false;
        }

        foreach (explode(',', $value) as $part) {
            $digits = strlen($part);

            if ($digits === 0 || $digits > 20) {
                return false;
            }
        }

        return true;
    }

    /**
     * "12,5,9" as [12, 5, 9]: positive ids, each once, in their order.
     *
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $value) as $part) {
            $id = (int) trim($part);

            if ($id > 0 && ! isset($ids[$id])) {
                $ids[$id] = true;
            }

            if (count($ids) >= self::MAX_IDS) {
                break;
            }
        }

        return array_keys($ids);
    }
}
