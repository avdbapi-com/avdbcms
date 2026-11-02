<?php

namespace App\Http\Requests\Report;

use App\Enums\ReportReason;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use App\Support\LinkProof;
use App\Support\ShownToMember;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    private const REPORTABLE_MODELS = [
        Video::class,
        Comment::class,
        User::class,
    ];

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
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        $table = $this->tableFor($this->input('type'));

        return [
            'reason' => [
                'required',
                Rule::enum(ReportReason::class)
            ],
            'comment' => [
                'nullable',
                'string',
                'max:'.config('validation.report.comment.max')
            ],
            'type' => [
                'bail',
                'required',
                Rule::in(self::REPORTABLE_MODELS)
            ],
            'id' => [
                'bail',
                'required',
                'integer',
                'min:1',
                // An id the member may not see is answered exactly like one that does not exist (reviewer finding
                // R1FX-REV-6): a deleted one, a video the member may not watch (another member's private video or
                // draft, a banned one) and a comment under such a video. Before, such a video answered 403 "You are
                // not authorized to report this video" (a deleted one 404) against 422 for a missing id, which told
                // which private ids exist. A member the member may not see (round 3 R3-082) is part of the same query.
                Rule::exists($table, 'id')->whereNull('deleted_at')->where(fn (Builder $query) => $this->shownMember($query)),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->visibleToMember((int) $value)) {
                        $fail($this->missingMessage());
                    }
                },
            ]
        ];
    }

    // A type sent as a list (type[]=...) is no model: answered as an invalid type, never a 500 (it reached ?string).
    private function tableFor(mixed $model): string
    {
        return is_string($model) && in_array($model, self::REPORTABLE_MODELS, true)
            ? (new $model())->getTable()
            : (new Video())->getTable();
    }

    /**
     * Whether the member may see what the id names: a video they may watch (the watch page's rule), a comment that a
     * video's comment list shows them (App\Support\ShownToMember::comments(), the rule of like and dislike: under a video
     * they may watch whose comments are on, not hidden by a moderator, a reply only under a comment the list shows; round 2
     * R2A-062: a comment a moderator hid answered 403 against 422 for a missing one, and a hidden reply or a comment under
     * a video with comments turned off could still be reported). A channel keeps its own rule (UserPolicy::report).
     * A channel the member may not see is refused by the exists query already (shownMember(), round 3 R3-082).
     * Round 3 R2R-06: another member's UNLISTED video, and a comment under one, need proof that the member has its link
     * (App\Support\LinkProof: `video_uuid` from the apps, its page opened in this browser, the member's own lists or
     * history): the Reports page shows the link of what was reported.
     */
    private function visibleToMember(int $id): bool
    {
        $user = $this->user();
        $uuid = is_string($this->input('video_uuid')) ? $this->input('video_uuid') : null;

        return match ($this->input('type')) {
            Video::class => ($video = Video::query()->find($id)) !== null && Gate::forUser($user)->allows('show', $video)
                && LinkProof::allowsVideo($video, $user, $uuid),
            Comment::class => DB::table('comments')->where('comments.id', $id)
                ->where(fn (Builder $query) => ShownToMember::comments($query, $user))
                ->whereExists(fn (Builder $videos) => $videos->selectRaw('1')->from('videos')
                    ->whereColumn('videos.id', 'comments.video_id')
                    ->where(fn (Builder $proof) => LinkProof::savable($proof, $user, $uuid)))
                ->exists(),
            default => true,
        };
    }

    /**
     * Round 3 R3-082: a member is reported only while active - not suspended, and with a confirmed address while the
     * site asks for one (User::is_active) - or it is the member's own channel (UserPolicy::report refuses that one, as
     * one the member reported already). Written into the id's exists query, so a suspended or unconfirmed member's id
     * takes exactly the missing id's way and work: it answered 403 "You are not authorized to report this user" against
     * 422 "This channel can no longer be reported.".
     */
    private function shownMember(Builder $query): void
    {
        if ($this->input('type') !== User::class) {
            return;
        }

        $userId = (int) ($this->user()?->getAuthIdentifier() ?? 0);

        $query->where(function (Builder $shown) use ($userId): void {
            $shown->where(function (Builder $active): void {
                $active->whereNull('banned_at');

                if (config('site.email_verification_required', false)) {
                    $active->whereNotNull('email_verified_at');
                }
            });

            if ($userId > 0) {
                $shown->orWhere('id', $userId);
            }
        });
    }

    /** The answer for an id that does not exist (or that the member may not see), in the member's language. */
    private function missingMessage(): string
    {
        return match ($this->input('type')) {
            Comment::class => __('This comment can no longer be reported.'),
            User::class => __('This channel can no longer be reported.'),
            default => __('This video can no longer be reported.'),
        };
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('Choose a reason for your report.'),
            'id.exists' => $this->missingMessage(),
        ];
    }
}
