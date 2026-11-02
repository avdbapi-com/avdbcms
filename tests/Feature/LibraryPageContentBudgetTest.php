<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Interaction;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Playlists\Types\WatchLaterPlaylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R1-069 (round 2a): MEMBER_DESIGN 4.3's page-content budget of the Library (the page root inside #main-container:
 * at most 16 KB raw, 3 KB gzip -9, 250 start tags), now that the page has the Saved playlists shelf (R1-034). Measured
 * on a member whose six shelves are full (six cards each, six channel chips: the most the page shows) with the demo
 * content's titles, channels and pictures, as round 2a measured it (16.4 KB / 2.9 KB / 262 before this fix). The
 * shelves' icons, the cards' title clamp and picture size and the duration badge are drawn by member/_channel.scss, and
 * (second pass) the head's folder icon and the channel chips' avatar size too.
 * Every language: the gzip size and the start tags are within the budget, and the markup (tags, attributes, links and
 * picture addresses) is byte for byte the same as the English page's: what a language adds over English is only its
 * words (Hindi, Thai, Russian and Arabic letters take 2-3 bytes each in UTF-8, so their raw size is larger).
 */
class LibraryPageContentBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const BYTES = 16 * 1024;

    private const GZIP = 3 * 1024;

    private const NODES = 250;

    /** The demo content's titles (the redesign's demo seed), as long as real ones. */
    private const TITLES = [
        'Sandbox playback test', 'Golden hour drive along the coast', 'Tokyo night walk in the rain - 4K binaural city ambience',
        'Mountain sunrise timelapse', 'Street food tour: 12 dishes in one night market', 'Lo-fi beats to study and relax to',
        'Morning stretch flow for beginners (20 min)', 'Desert storm chasing - the whole story', 'Retro synthwave mix 1984',
        'City skyline hyperlapse', 'Forest rain ambience for sleep', 'How we built a tiny cabin in 30 days (full build)',
        'Deep ocean documentary: creatures of the abyss', 'Downtown jazz club live set', 'The quiet library: 3 hour reading room',
        'Sunset beach bonfire session', 'Interview: making music on a budget', 'Northern lights over the fjord, real time',
    ];

    private const CHANNELS = ['nightdrive_tv' => 'NightdriveTV', 'oceanlens' => 'OceanLens', 'cabin_builders' => 'CabinBuilders',
        'lofi_room' => 'LofiRoom', 'street_bites' => 'StreetBites', 'sandboxadmin' => 'SandboxAdmin'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['site.ads_enabled' => false, 'site.show_history' => true]);
    }

    public function test_a_full_library_fits_the_page_content_budget_in_english(): void
    {
        $member = $this->fullLibrary(6);

        [$bytes, $gzip, $nodes, $root] = $this->measure($member, '');

        $this->assertSame(6, substr_count($root, 'class="mk-shelf"'), 'six shelves');
        $this->assertSame(30, substr_count($root, '<a class="mk-card'), 'six cards on each of the five card shelves');
        $this->assertSame(6, substr_count($root, 'class="mk-chan-chip"'));
        $this->assertLessThanOrEqual(self::BYTES, $bytes, "raw {$bytes} B");
        $this->assertLessThanOrEqual(self::GZIP, $gzip, "gzip {$gzip} B");
        $this->assertLessThanOrEqual(self::NODES, $nodes, "{$nodes} start tags");
    }

    /** Arabic words take more bytes: measured as round 2a measured it (three saved playlists). */
    public function test_the_library_fits_the_page_content_budget_in_arabic(): void
    {
        $member = $this->fullLibrary(3);

        [$bytes, $gzip, $nodes, $root] = $this->measure($member, 'ar');

        $this->assertLessThanOrEqual(self::BYTES, $bytes, "raw {$bytes} B");
        $this->assertLessThanOrEqual(self::GZIP, $gzip, "gzip {$gzip} B");
        $this->assertLessThanOrEqual(self::NODES, $nodes, "{$nodes} start tags");
    }

    /**
     * The full Library in every language of the site (config/languages.php): gzip and start tags within the budget, and
     * the same markup as in English (only the words differ, and, 1.2.3 round 3 (R3-015), the direction of the titles
     * that read the other way than the page: the demo's Latin titles carry dir=ltr on a right-to-left page, nothing on a
     * left-to-right one).
     */
    public function test_a_full_library_fits_the_gzip_and_node_budget_in_every_language_with_the_markup_of_english(): void
    {
        $member = $this->fullLibrary(6);
        [$enBytes, , , $enRoot] = $this->measure($member, '');
        $enMarkup = $enBytes - $this->wordBytes($enRoot);
        $this->assertSame(0, preg_match_all('# dir=(?:ltr|rtl)[ >]#', $enRoot), 'en: no direction of their own');

        $locales = array_keys((array) config('languages'));
        $this->assertGreaterThanOrEqual(17, count($locales));
        foreach ($locales as $locale) {
            [$bytes, $gzip, $nodes, $root] = $this->measure($member, $locale);

            $this->assertSame(30, substr_count($root, '<a class="mk-card'), "{$locale}: the full Library");
            $this->assertLessThanOrEqual(self::GZIP, $gzip, "{$locale}: gzip {$gzip} B");
            $this->assertLessThanOrEqual(self::NODES, $nodes, "{$locale}: {$nodes} start tags");
            $rtl = (bool) (config('languages.'.$locale.'.rtl') ?? false);
            $directions = preg_match_all('# dir=ltr(?=[ >])#', $root);
            $this->assertSame($rtl ? 30 : 0, $directions, "{$locale}: the 30 Latin card titles carry dir=ltr on a right-to-left page only");
            $this->assertSame($enMarkup, $bytes - $this->wordBytes($root) - strlen(' dir=ltr') * $directions, "{$locale}: the markup is the English page's, only the words differ (raw {$bytes} B)");
        }
    }

    public function test_the_drawn_parts_are_still_there_for_the_stylesheet(): void
    {
        $member = $this->fullLibrary(1);
        [, , , $root] = $this->measure($member, '');

        // the duration of every video card is its thumbnail's data-dur; the shelf heads keep their ids (their icons)
        $this->assertSame(18, preg_match_all('#<span class="mk-thumb" data-dur="\d+:\d{2}(?::\d{2})?">#', $root));
        foreach (['mkShelfHistory', 'mkShelfLater', 'mkShelfLiked', 'mkShelfPlaylists', 'mkShelfSaved', 'mkShelfSubs'] as $id) {
            $this->assertMatchesRegularExpression('#<h2 class="mk-panel__title" id="'.$id.'">[^<]#', $root, $id.': the title follows at once');
        }
        $shelves = substr($root, (int) strpos($root, '<section class="mk-shelf"'));
        $this->assertStringNotContainsString('<i ', $shelves, 'no icon element in the shelves');
        // second pass: the head's kicker is the word alone (its folder icon is drawn), the chips' avatars carry no class
        $this->assertStringContainsString('<span class="mk-head__kicker">Library</span>', $root);
        $this->assertStringNotContainsString('<i ', $root, 'no icon element in the page root');
        $this->assertSame(6, preg_match_all('#<a class="mk-chan-chip" href="[^"]+"><img src="[^"]+" alt="" loading="lazy" decoding="async">[^<]+</a>#', $root));
        $css = (string) file_get_contents(base_path('resources/sass/member/_channel.scss'));
        foreach (['#mkShelfHistory::before { content: "\f1da"; content: "\f1da" / ""; }', '#mkShelfSubs::before { content: "\f4fc"; content: "\f4fc" / ""; }',
            ':root .mk-thumb[data-dur]::after {', ':root .mk-card > .mk-thumb > img { width: 100%; height: 100%; }',
            ':root .mk-library > .mk-head .mk-head__kicker::before { order: -2; }', ':root .mk-library > .mk-head .mk-head__kicker::after {',
            'content: "\f07c" / "";', ':root .mk-chan-chip > img {'] as $rule) {
            $this->assertStringContainsString($rule, $css);
        }
    }

    /** The bytes of the words the page root shows (its text between tags) and of its aria-label values. */
    private function wordBytes(string $root): int
    {
        preg_match_all('#>([^<]+)<#', $root, $text);
        preg_match_all('# aria-label="([^"]*)"#', $root, $labels);

        return strlen(implode('', $text[1])) + strlen(implode('', $labels[1]));
    }

    /**
     * A member whose shelves are full, the way the demo member of round 2a's measure was: six videos watched, six in
     * Watch later, six liked (two of them watched, two saved for later), six own playlists of three videos, $saved saved
     * playlists of other channels (their own videos) and six channels followed.
     */
    private function fullLibrary(int $saved): User
    {
        $member = User::factory()->create(['username' => 'cl_budget_member']);
        $channels = [];
        foreach (self::CHANNELS as $name => $slug) {
            $channels[] = User::factory()->create(['username' => $name, 'slug' => $slug]);
        }
        $videos = [];
        foreach (self::TITLES as $n => $title) {
            $videos[] = Video::factory()->for($channels[$n % 6])->withStatus(VideoStatus::PUBLIC)->create([
                'title' => $title,
                // the demo content's addresses: /video/<title>-<uuid>
                'slug' => Str::slug($title),
                'uuid' => (string) Str::uuid(),
                'poster_url' => sprintf('/images/tr/thumb_%02d.jpg', $n + 1),
                'duration' => [12, 537, 10977, 751, 1384, 7711][$n % 6] + $n,
                'views' => [31, 509, 2100000, 2000, 11500, 1200][$n % 6] + $n,
                'published_at' => now()->subDays(10 + $n * 5),
                'uploaded_at' => now()->subDays(10 + $n * 5),
            ]);
        }

        foreach (array_slice($videos, 0, 6) as $n => $video) {
            DB::table('views')->insert(['video_id' => $video->id, 'user_id' => $member->id, 'ip' => '127.0.0.1', 'view_at' => now()->subMinutes(10 + $n)]);
        }
        $later = Playlist::factory()->for($member)->create(['title' => WatchLaterPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        // as members' lists are: some videos are in more than one (a liked video was watched, one saved for later too)
        foreach (array_slice($videos, 8, 6) as $n => $video) {
            $later->videos()->attach($video->id, ['position' => $n + 1, 'added_at' => now()->subMinutes($n)]);
        }
        foreach (array_slice($videos, 4, 6) as $n => $video) {
            Interaction::query()->create(['user_id' => $member->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => true, 'perform_at' => now()->subMinutes($n)]);
        }
        foreach (['Everything I want to watch again', 'Street food of Bangkok and Osaka', 'Lo-fi rooms to study with',
            'Cabin builds, from the first log', 'Ocean documentaries for the weekend', 'Late night drives and city lights'] as $n => $title) {
            $this->playlist($member, $title, array_slice($videos, $n * 3, 3));
        }
        foreach (range(1, $saved) as $n) {
            $own = array_values(array_filter($videos, fn (Video $video) => $video->user_id === $channels[$n % 6]->id));
            $playlist = $this->playlist($channels[$n % 6], 'The best of the channel, part '.$n, array_slice($own, 0, 3));
            DB::table('favorites_playlist')->insert(['user_id' => $member->id, 'playlist_id' => $playlist->id, 'added_at' => now()->subDays($n)]);
        }
        foreach ($channels as $n => $channel) {
            DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $member->id, 'subscribe_at' => now()->subDays($n + 1), 'read_at' => now()->subDays($n + 1)]);
        }

        return $member;
    }

    /** @param list<Video> $videos */
    private function playlist(User $owner, string $title, array $videos): Playlist
    {
        $playlist = Playlist::query()->create(['uuid' => (string) Str::uuid(), 'title' => $title, 'user_id' => $owner->id,
            'status' => PlaylistStatus::PUBLIC, 'is_deletable' => true]);
        foreach ($videos as $n => $video) {
            $playlist->videos()->attach($video->id, ['position' => $n, 'added_at' => now()->subHours($n)]);
        }

        return $playlist;
    }

    /**
     * The page root's raw bytes, gzip -9 bytes and start tags (MEMBER_DESIGN 4.3 / round 2a's j_budget.js).
     *
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    private function measure(User $member, string $locale): array
    {
        // public/index.php takes the language off the address (/ar/library) and hands it to the Language middleware
        if ($locale !== '') {
            $_SERVER['DETECTED_LOCALE'] = $locale;
        }
        try {
            $html = $this->actingAs($member)->get('/library')->assertOk()->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
        }
        if (getenv('CL_DUMP')) {
            file_put_contents((string) getenv('CL_DUMP').'_'.($locale ?: 'en').'.html', $html);
        }
        $at = strpos($html, 'mk-page mk-library');
        $this->assertNotFalse($at, 'the Library page root');
        $start = strrpos(substr($html, 0, $at), '<');
        $depth = 0;
        $end = strlen($html);
        preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*?(/?)>#', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $start);
        foreach ($tags as $tag) {
            if (in_array(strtolower($tag[2][0]), ['img', 'input', 'br', 'meta', 'link', 'source', 'hr', 'wbr'], true) || $tag[3][0] === '/') {
                continue;
            }
            $depth += $tag[1][0] === '/' ? -1 : 1;
            if ($depth === 0) {
                $end = $tag[0][1] + strlen($tag[0][0]);
                break;
            }
        }
        $root = substr($html, $start, $end - $start);

        return [strlen($root), strlen((string) gzencode($root, 9)), preg_match_all('#<[a-zA-Z][a-zA-Z0-9-]*\b#', $root), $root];
    }
}
