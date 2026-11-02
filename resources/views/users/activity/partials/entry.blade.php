{{-- One entry of "Your activity" (member design 6.8): an icon, one sentence and the time. The sentence is
     a translated line whose names are bold links ("You liked **Blue hour above the valley** by oceanlens"): the line is
     escaped first and only then gets the escaped names, so a title or a translation can never add markup. Anything
     deleted since says so. Variable: $activity (the controller loads every name used here). --}}
@php
    use App\Models\Comment;
    use App\Models\Interaction;
    use App\Models\Video;
    use App\Support\Studio\StudioFormat;
    use Illuminate\Support\HtmlString;
    use Illuminate\Support\Str;

    $subject = $activity->subject;
    // names, titles and comment words are user texts: isolated (<bdi>), so they keep their own direction inside the
    // sentence of any language (1.2.3 round 1, R1-025)
    $bold = function (?string $text, ?string $url = null): string {
        $text = '<bdi>'.e((string) $text).'</bdi>';

        return '<strong>'.($url ? '<a class="mk-quiet-link" href="'.e($url).'">'.$text.'</a>' : $text).'</strong>';
    };
    // the line comes translated (a literal __() at each call, so the translation tools find every sentence)
    $say = fn (string $line, array $parts = []) => new HtmlString(strtr(e($line), $parts));
    $videoLink = function (?Video $video) use ($bold): ?string {
        if (! $video || $video->trashed()) {
            return null;
        }

        return $bold((string) $video->title, $video->route);
    };
    $icon = 'circle-info';
    $sentence = $say(__('This item was deleted.'));

    if ($subject instanceof Comment) {
        $title = $videoLink($subject->video);
        $excerpt = '<bdi>'.e(Str::limit(trim((string) $subject->content), 80)).'</bdi>';

        if ($subject->parent_id !== null) {
            $icon = 'reply';
            $name = $bold($subject->parent?->user?->username ?? __('a deleted member'));
            $sentence = $title
                ? $say(__('You replied to :name on :title'), [':name' => $name, ':title' => $title])
                : $say(__('You replied to :name on a video that was deleted'), [':name' => $name]);
        } else {
            $icon = 'comment';
            $sentence = $title
                ? $say(__('You commented on :title: “:text”'), [':title' => $title, ':text' => $excerpt])
                : $say(__('You commented on a video that was deleted: “:text”'), [':text' => $excerpt]);
        }
    } elseif ($subject instanceof Interaction) {
        $liked = (bool) $subject->status;
        $icon = $liked ? 'thumbs-up' : 'thumbs-down';
        $target = $subject->likeable;

        if ($target instanceof Video) {
            $title = $videoLink($target);
            $channel = '<bdi>'.e($target->user?->username ?? __('a deleted channel')).'</bdi>';
            $sentence = $title
                ? ($liked
                    ? $say(__('You liked :title by :channel'), [':title' => $title, ':channel' => $channel])
                    : $say(__('You disliked :title by :channel'), [':title' => $title, ':channel' => $channel]))
                : ($liked ? $say(__('You liked a video that was deleted')) : $say(__('You disliked a video that was deleted')));
        } elseif ($target instanceof Comment && ! $target->trashed()) {
            $name = $bold($target->user?->username ?? __('a deleted member'));
            $title = $videoLink($target->video);
            $sentence = $title
                ? ($liked
                    ? $say(__('You liked a comment by :name on :title'), [':name' => $name, ':title' => $title])
                    : $say(__('You disliked a comment by :name on :title'), [':name' => $name, ':title' => $title]))
                : ($liked
                    ? $say(__('You liked a comment by :name on a video that was deleted'), [':name' => $name])
                    : $say(__('You disliked a comment by :name on a video that was deleted'), [':name' => $name]));
        } else {
            $sentence = $liked ? $say(__('You liked a comment that was deleted')) : $say(__('You disliked a comment that was deleted'));
        }
    }
@endphp
<li class="mk-item mk-item--activity">
    <span class="mk-attn__icon" style="--mk-tone:var(--avd-muted);--mk-tone-rgb:var(--avd-shade-rgb)" aria-hidden="true"><i class="fa-solid fa-{{ $icon }}"></i></span>
    <div class="mk-item__body"><span>{{ $sentence }}</span></div>
    <time class="mk-muted" style="font-size:.8125rem" datetime="{{ $activity->perform_at?->toIso8601String() }}">{{ StudioFormat::time($activity->perform_at) }}</time>
</li>
