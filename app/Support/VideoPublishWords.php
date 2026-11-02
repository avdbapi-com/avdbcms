<?php

namespace App\Support;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Video;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * 1.2.3 round 3 (KS-F3, decided: the safest default): a video whose title, tags or category use a word that is always
 * blocked (App\Support\ContentSafety's hard list: a child, a school-age minor; never the owner's own import list) does
 * not go out to visitors from a save: it is not made public, unlisted or scheduled (a scheduled video goes public by
 * itself), and while it is out its title is not changed to such a title nor its category to such a category. Every way
 * a member or an admin saves a video asks refusals(): Video details and the draft page's Publish, the studio's bulk
 * visibility, the admin's video page and the admin's bulk bar (the apps have no address that edits a video). The answer
 * names the field, never the words.
 *
 * Not changed: a private save (a member may keep any title for themselves), the upload itself (a new upload is a
 * private draft until it is published) and the admin's Ban and Unban (Unban after a review brings a video back as it
 * was; its other details still save while its title and category stay as they are). The update's pass bans the videos a
 * site already had (App\Support\BlockedWordsPass); the importers never bring one in.
 *
 * KS-F1: categoryRule() is the category of a member's save: one that exists and visitors see, or the video's own (an
 * unchanged form still saves); a category visitors never see is refused exactly like one that does not exist.
 */
final class VideoPublishWords
{
    /** The statuses that put a video in front of visitors. */
    public const OUT = [VideoStatus::PUBLIC, VideoStatus::UNLISTED, VideoStatus::PLANNED];

    /**
     * Why a save that gives $video these values may not go through, by field ('title', 'category_id', 'status' for its
     * tags => the message, in the reader's language). Empty: the save may go on.
     *
     * @param  array<string, mixed>  $values  the values the save gives the video: status, title, category_id (a key
     *                                        left out keeps the stored value)
     * @return array<string, string>
     */
    public static function refusals(Video $video, array $values): array
    {
        $status = array_key_exists('status', $values) ? self::status($values['status']) : self::status($video->status);

        if (! in_array($status, self::OUT, true)) {
            return [];
        }

        // Going out now: everything it shows counts. Out already: only what this save changes (what an admin let
        // through after a review stays until it is changed).
        $goesOut = ! in_array(self::status($video->status), self::OUT, true);
        $refusals = [];
        $storedTitle = (string) ($video->getAttributes()['title'] ?? '');
        $title = array_key_exists('title', $values) ? (string) $values['title'] : $storedTitle;

        if (($goesOut || $title !== $storedTitle) && ContentSafety::hardMatch($title) !== null) {
            $refusals['title'] = __('This title uses words that are not allowed on this site.');
        }

        $storedCategory = $video->category_id === null ? null : (int) $video->category_id;
        $category = array_key_exists('category_id', $values)
            ? (filled($values['category_id']) && is_numeric($values['category_id']) ? (int) $values['category_id'] : null)
            : $storedCategory;

        if ($category !== null && ($goesOut || $category !== $storedCategory)) {
            // (a list that loaded the videos' categories and tags asks no query per video)
            $categoryTitle = $category === $storedCategory && $video->relationLoaded('category')
                ? $video->category?->title
                : Category::query()->whereKey($category)->value('title');

            if (ContentSafety::hides((string) $categoryTitle)) {
                $refusals['category_id'] = __('This category uses words that are not allowed on this site. Choose another one.');
            }
        }

        if ($goesOut && $video->exists) {
            $tags = $video->relationLoaded('tags') ? $video->tags->pluck('name') : $video->tags()->pluck('tags.name');

            if ($tags->contains(fn ($name): bool => ContentSafety::hides((string) $name))) {
                $refusals['status'] = __('A tag of this video uses words that are not allowed on this site, so it cannot be public, unlisted or scheduled.');
            }
        }

        return $refusals;
    }

    /**
     * Refuses the save as a validation error (the form shows each under its field, with what was typed).
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public static function check(Video $video, array $values): void
    {
        $refusals = self::refusals($video, $values);

        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }
    }

    /**
     * The category a member's save may give the video (KS-F1): one that exists and visitors see, or the video's own; a
     * category visitors never see answers like one that does not exist (the same rule, the same message).
     */
    public static function categoryRule(?Video $video): Exists
    {
        $own = $video?->category_id === null ? null : (int) $video->category_id;
        $hidden = Category::query()->get(['id', 'title'])
            ->filter(fn (Category $category): bool => (int) $category->id !== $own && ContentSafety::hides((string) $category->title))
            ->map(fn (Category $category): int => (int) $category->id)
            ->values()
            ->all();

        return Rule::exists('categories', 'id')->where(fn (Builder $query) => $query->whereNotIn('id', $hidden));
    }

    private static function status(mixed $value): ?VideoStatus
    {
        return match (true) {
            $value instanceof VideoStatus => $value,
            is_numeric($value) => VideoStatus::tryFrom((int) $value),
            default => null,
        };
    }
}
