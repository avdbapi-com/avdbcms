<?php

namespace Tests\Feature;

use App\Enums\ReportReason;
use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\ContentSafety;
use App\Support\SeoSitemapCache;
use App\Support\ShownToMember;
use App\Support\VideoLanguages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (lane KS, second pass): the orchestrator's decision of 29 Oct on the lane's question.
 *
 * The lists of a language show every imported video there (Video::scopeForLocale(): Home, Trending, the category pages,
 * search, the public API's feeds), while the watch page of an AVDBAPI import without a translation of that language
 * answered 404 "This video is not available in this language" (already in 1.2.2): a German visitor who clicked its card
 * got a 404. Now the page follows the lists, and such a page is not indexed:
 *  - it opens (200) in every language, with the video's own texts where it has no translation of that language;
 *  - where it is not indexable (App\Support\VideoLanguages::indexable(): an AVDBAPI import in a language other than
 *    English and the default language that it has no translation of) it answers "X-Robots-Tag: noindex, follow" and its
 *    robots meta tag says the same; a translated page, an English one and a member's upload carry no such header;
 *  - the sitemaps list neither that page nor an alternate to it, and no hreflang link names it;
 *  - bans, deleted channels, private videos, dates to come and the admin's rule are unchanged, in every language;
 *  - what the page offers a member works on it (like, save, report, Watch later, the progress, comment likes, the API's
 *    video, view and player): the policy and its one-query twin App\Support\ShownToMember follow the page.
 *
 * Test data: neutral titles.
 */
class UntranslatedImportPageTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
        SeoSitemapCache::clear();
        unset($_SERVER['DETECTED_LOCALE']);
        // the site's default language stays English (a page in another language changes app.locale in this one process)
        config(['site.default_locale' => 'en', 'cache.limiter' => 'array']);
        $this->creator = User::factory()->create(['email_verified_at' => now(), 'username' => 'qa_import_channel']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);

        parent::tearDown();
    }

    public function test_an_untranslated_import_opens_in_every_language_and_is_not_indexed_there(): void
    {
        $import = $this->video('QA French only import', ['avdb_id' => 77777]);
        $this->translate($import, 'fr', 'QA import en français');

        foreach (['de', 'ar', 'vi', 'ja'] as $locale) {
            $page = $this->page($import, $locale);
            $html = (string) $page->getContent();

            $this->assertSame(200, $page->getStatusCode(), $locale.': the page follows the lists');
            $this->assertSame('noindex, follow', $page->headers->get('X-Robots-Tag'), $locale.': not indexed');
            $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html, $locale.': its robots meta says the same');
            $this->assertStringContainsString('QA French only import', $html, $locale.': the video\'s own title');
            $this->assertStringNotContainsString('QA import en français', $html, $locale.': not the translation of another language');
            $this->assertStringNotContainsString('This video is not available in this language', $html, $locale);
            $this->assertSame(['en', 'fr', 'x-default'], $this->hreflangs($html), $locale.': its alternates are the indexable pages only');
        }

        // where it is indexable: English (the default language) and French (its translation)
        foreach (['en' => 'QA French only import', 'fr' => 'QA import en français'] as $locale => $title) {
            $page = $this->page($import, $locale);
            $html = (string) $page->getContent();

            $this->assertSame(200, $page->getStatusCode(), $locale);
            $this->assertNull($page->headers->get('X-Robots-Tag'), $locale.': indexable, no noindex');
            $this->assertStringNotContainsString('<meta name="robots" content="noindex', $html, $locale);
            $this->assertStringContainsString($title, $html, $locale);
            $this->assertSame(['en', 'fr', 'x-default'], $this->hreflangs($html), $locale);
        }

        // a member's upload and an import of another source are indexable in every language: no noindex in German either
        foreach ([$this->video('QA member upload'), $this->video('QA other source import', ['xvid_id' => 4242])] as $video) {
            $page = $this->page($video, 'de');
            $this->assertSame(200, $page->getStatusCode(), $video->title);
            $this->assertNull($page->headers->get('X-Robots-Tag'), $video->title);
            $this->assertCount(count(config('languages')) + 1, $this->hreflangs((string) $page->getContent()), $video->title.': every language');
        }

        // a signed-in member gets the same page and the same header
        $page = $this->page($import, 'de', User::factory()->create());
        $this->assertSame(200, $page->getStatusCode());
        $this->assertSame('noindex, follow', $page->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', (string) $page->getContent());
    }

    public function test_the_noindex_words_keep_the_sites_own_nofollow(): void
    {
        $import = $this->video('QA French only import', ['avdb_id' => 77778]);

        // Settings > SEO "Tell Google not to follow links": the page's own robots rule says nofollow, and so does this one
        config(['site.robots_nofollow' => true]);
        $page = $this->page($import, 'de');
        $this->assertSame(200, $page->getStatusCode());
        $this->assertSame('noindex, nofollow', $page->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', (string) $page->getContent());
        $this->assertNull($this->page($import, 'en')->headers->get('X-Robots-Tag'), 'English stays indexable');

        // "Hide the whole site from Google"
        config(['site.robots_nofollow' => false, 'site.robots_noindex' => true]);
        $this->assertSame('noindex, nofollow', $this->page($import, 'de')->headers->get('X-Robots-Tag'));
    }

    public function test_the_sitemaps_offer_neither_the_untranslated_page_nor_an_alternate_to_it(): void
    {
        $import = $this->video('QA French only import', ['avdb_id' => 77779]);
        $this->translate($import, 'fr', 'QA import en français');
        $german = $this->video('QA German import', ['avdb_id' => 77780]);
        $this->translate($german, 'de', 'QA deutscher Import');

        foreach (['de', 'ar', 'vi'] as $locale) {
            $this->assertStringNotContainsString($import->uuid, $this->sitemap($locale), $locale.': its page there is not indexable');
        }

        $path = app(SeoService::class)->videoPath($import);

        foreach (['en', 'fr'] as $locale) {
            $xml = $this->sitemap($locale);
            $this->assertSame(['en', 'fr', 'x-default'], $this->alternates($xml, $import), $locale.': its alternates');

            foreach (['de', 'ar', 'vi', 'ja'] as $other) {
                $this->assertStringNotContainsString(url('/'.$other.$path), $xml, $locale.': no alternate to the '.$other.' page');
            }
        }

        // a translated import is listed in its language, with its alternates
        $this->assertSame(['de', 'en', 'x-default'], $this->alternates($this->sitemap('de'), $german));
        $this->assertNull($this->page($german, 'de')->headers->get('X-Robots-Tag'), 'its German page is indexable');
    }

    public function test_bans_deleted_channels_private_videos_and_the_admin_rule_are_unchanged(): void
    {
        $gone = User::factory()->create();
        $videos = [
            'private' => $this->video('QA private import', ['avdb_id' => 70001, 'status' => VideoStatus::PRIVATE->value, 'published_at' => null]),
            'date to come' => $this->video('QA later import', ['avdb_id' => 70002, 'published_at' => now()->addDays(3)]),
            'banned' => $this->video('QA banned import', ['avdb_id' => 70003, 'status' => VideoStatus::BANNED->value, 'banned_at' => now()->subHour()]),
            'channel deleted' => $this->video('QA orphan import', ['avdb_id' => 70004, 'user_id' => $gone->id]),
        ];
        $gone->delete();
        // as stored now (saving a video may have read its channel before the channel was deleted)
        $videos = array_map(fn (Video $video): Video => $video->fresh(), $videos);
        $admin = User::factory()->admin()->create();

        // a visitor: exactly as before, whatever the language
        $expected = ['private' => 404, 'date to come' => 404, 'banned' => 410, 'channel deleted' => 410];

        foreach ($videos as $state => $video) {
            foreach (['en', 'fr', 'de', 'ar'] as $locale) {
                $this->assertSame($expected[$state], $this->page($video, $locale)->getStatusCode(), 'a visitor, '.$state.', '.$locale);
            }
        }

        // the channel and an administrator: the same answer in every language, and the private video opens for both
        foreach (['the channel' => $this->creator, 'an administrator' => $admin] as $who => $account) {
            foreach ($videos as $state => $video) {
                $english = $this->page($video, 'en', $account)->getStatusCode();

                foreach (['fr', 'de', 'ar'] as $locale) {
                    $this->assertSame($english, $this->page($video, $locale, $account)->getStatusCode(), $who.', '.$state.', '.$locale);
                }
            }

            $private = $this->page($videos['private'], 'de', $account);
            $this->assertSame(200, $private->getStatusCode(), $who.': the private video');
            $this->assertSame('noindex, follow', $private->headers->get('X-Robots-Tag'), $who.': not indexable in German');
        }

        // the policy says the same as its one-query twin (likes, saves, comment likes), in every language
        foreach ([null, $this->creator, $admin, User::factory()->create()] as $account) {
            foreach (['en', 'de'] as $locale) {
                app()->setLocale($locale);

                foreach ($videos as $state => $video) {
                    $this->assertSame(Gate::forUser($account)->allows('show', $video), $this->shownToMember($video, $account),
                        ($account?->username ?? 'a visitor').', '.$state.', '.$locale);
                }
            }
        }
        app()->setLocale('en');
    }

    public function test_what_the_page_offers_a_member_works_on_it(): void
    {
        $import = $this->video('QA French only import', ['avdb_id' => 77781, 'allow_comments' => true]);
        $member = User::factory()->create(['email_verified_at' => now()]);
        $comment = Comment::withoutEvents(fn () => Comment::factory()->create(['video_id' => $import->id, 'user_id' => $this->creator->id]));
        $playlist = Playlist::query()->create(['uuid' => (string) Str::uuid(), 'title' => 'Mine '.Str::random(6), 'user_id' => $member->id, 'status' => 1, 'sort' => 5]);

        // the page opens in German and Arabic, and the policy's one-query twin (likes, saves, comment likes) says the same
        foreach (['de', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->assertTrue(Gate::forUser($member)->allows('show', $import), $locale.': the page');
            $this->assertTrue($this->shownToMember($import, $member), $locale.': what the page offers');
        }
        app()->setLocale('en');

        $german = ['X-Locale' => 'de'];

        // like (and take it back), dislike, and a like of the comment under it
        $this->api($member, 'postJson', '/api/like', ['model' => Video::class, 'id' => $import->id], $german)->assertNoContent();
        $this->assertSame(1, DB::table('interactions')->where('user_id', $member->id)->where('likeable_type', Video::class)->where('likeable_id', $import->id)->count());
        $this->api($member, 'postJson', '/api/like', ['model' => Video::class, 'id' => $import->id], $german)->assertNoContent();
        $this->api($member, 'postJson', '/api/dislike', ['model' => Video::class, 'id' => $import->id], $german)->assertNoContent();
        $this->api($member, 'postJson', '/api/like', ['model' => Comment::class, 'id' => $comment->id], $german)->assertNoContent();
        $this->assertSame(1, DB::table('interactions')->where('user_id', $member->id)->where('likeable_type', Comment::class)->count());

        // Save to playlist, Report, the progress
        $this->api($member, 'postJson', '/api/playlists/save', ['video_id' => $import->id, 'playlist_id' => $playlist->id], $german)->assertStatus(201);
        $this->assertTrue($playlist->videos()->whereKey($import->id)->exists());
        $this->api($member, 'postJson', '/api/report', ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => Video::class, 'id' => $import->id], $german)->assertSuccessful();
        $this->assertSame(1, DB::table('reports')->where('user_id', $member->id)->count());
        $this->api($member, 'postJson', route('videos.progress', $import->uuid), ['position_seconds' => 42, 'duration_seconds' => 100], $german)->assertSuccessful();

        // the public API's video, view and player, and the comment list
        $this->api(null, 'getJson', '/api/videos/'.$import->uuid, [], $german)->assertOk();
        $this->api(null, 'postJson', '/api/videos/'.$import->uuid.'/view', [], $german)->assertNoContent();
        $this->api(null, 'getJson', '/api/videos/'.$import->uuid.'/player', [], $german)->assertOk();
        $comments = $this->api(null, 'getJson', '/api/videos/'.$import->uuid.'/comments', [], $german)->assertOk()->json('data');
        $this->assertContains($comment->id, collect($comments)->pluck('id')->all(), 'the comment list of the German page');

        // Watch later, from the German page
        $this->fresh();
        $_SERVER['DETECTED_LOCALE'] = 'de';
        try {
            $this->actingAs($member)->postJson(route('custom-playlist.watch-later.toggle', $import->id), ['state' => 'on'])->assertOk();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
        }
        $this->assertTrue(DB::table('playlist_has_videos')->where('video_id', $import->id)->where('playlist_id', '!=', $playlist->id)->exists());
    }

    /**
     * The helper's two rules (App\Support\VideoLanguages): visible (the page opens) in every language for every video;
     * indexable only where an AVDBAPI import has a translation, in English and in the default language; robots() names
     * the words of a page that is visible but not indexable. The languages read once give the same answers as the query.
     */
    public function test_the_helper_keeps_the_two_rules_apart(): void
    {
        $import = $this->video('QA French only import', ['avdb_id' => 77782]);
        $this->translate($import, 'fr', 'QA import en français');
        $upload = $this->video('QA member upload', ['language' => 'ja']);
        $zero = $this->video('QA number zero video', ['avdb_id' => 0]);
        $languages = array_keys(config('languages'));
        $read = VideoLanguages::languagesOf($import);

        $this->assertSame(['fr'], $read);
        $this->assertNull(VideoLanguages::languagesOf($upload), 'a member\'s upload does not depend on translations');
        $this->assertNull(VideoLanguages::languagesOf($zero), 'the AVDBAPI number 0 is no import');

        foreach ($languages as $locale) {
            $indexable = in_array($locale, ['en', 'fr'], true);

            foreach ([$import, $upload, $zero] as $video) {
                $this->assertTrue(VideoLanguages::visible($video, $locale), $video->title.', '.$locale.': opens');
            }

            $this->assertSame($indexable, VideoLanguages::indexable($import, $locale), $locale);
            $this->assertSame($indexable, VideoLanguages::indexable($import, $locale, $read), $locale.': from the languages read once');
            $this->assertSame($indexable ? null : 'noindex, follow', VideoLanguages::robots($import, $locale, $read), $locale);
            $this->assertTrue(VideoLanguages::indexable($upload, $locale), $locale.': a member\'s upload');
            $this->assertNull(VideoLanguages::robots($zero, $locale), $locale.': the number 0');
        }

        $indexableIn = VideoLanguages::indexableIn($import, $languages);
        sort($indexableIn);
        $this->assertSame(['en', 'fr'], $indexableIn);
        $this->assertSame($languages, VideoLanguages::indexableIn($upload, $languages));

        // the query form keeps exactly the indexable rows
        foreach (['de' => [$upload->id, $zero->id], 'fr' => [$import->id, $upload->id, $zero->id]] as $locale => $ids) {
            $query = DB::table('videos')->whereIn('videos.id', [$import->id, $upload->id, $zero->id]);
            VideoLanguages::constrainIndexable($query, $locale);
            $this->assertEqualsCanonicalizing($ids, $query->pluck('videos.id')->map(fn ($id): int => (int) $id)->all(), $locale);
        }
    }

    /** A public video of the test channel. */
    private function video(string $title, array $attributes = []): Video
    {
        return Video::factory()->create(array_merge([
            // a real one: the watch page's address only takes a UUID (the factory's number is no address)
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->creator->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => $title.' description.',
            'category_id' => null,
            'status' => VideoStatus::PUBLIC->value,
            'published_at' => now()->subDays(2),
            'uploaded_at' => now()->subDays(2),
            'language' => null,
            'embed_code' => null,
        ], $attributes));
    }

    private function translate(Video $video, string $locale, string $title): void
    {
        DB::table('video_translations')->insert([
            'video_id' => $video->id, 'locale' => $locale, 'title' => $title, 'description' => null,
            'type_name' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The video's watch page in a language (its prefix taken off as public/index.php does), as a visitor or $account. */
    private function page(Video $video, string $locale, ?User $account = null): TestResponse
    {
        $this->fresh();
        $path = app(SeoService::class)->videoPath($video);

        if ($locale !== 'en') {
            $_SERVER['DETECTED_LOCALE'] = $locale;
        }

        try {
            return ($account ? $this->actingAs($account) : $this)->get($path);
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
        }
    }

    /** One request of the API, every one from the same state (no session, no limiter state, the default language). */
    private function api(?User $account, string $method, string $uri, array $data, array $headers): TestResponse
    {
        $this->fresh();
        $request = $account ? $this->actingAs($account, 'sanctum') : $this;

        return $request->withHeaders($headers)->{$method}($uri, $data);
    }

    private function fresh(): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->app['auth']->guard('web')->logout();
        Auth::forgetGuards();
        Cache::store('array')->flush();
        $this->flushHeaders();
        app()->setLocale('en');
    }

    private function shownToMember(Video $video, ?User $account): bool
    {
        $query = DB::table('videos')->where('videos.id', $video->id)->whereNull('videos.deleted_at');
        ShownToMember::videos($query, $account);

        return $query->exists();
    }

    /** @return list<string> the page's hreflang values, sorted */
    private function hreflangs(string $html): array
    {
        preg_match_all('#<link rel="alternate" hreflang="([^"]+)"#', $html, $matches);
        $languages = array_values(array_unique($matches[1]));
        sort($languages);

        return $languages;
    }

    private function sitemap(string $locale): string
    {
        $this->fresh();

        return (string) $this->get(route('seo.sitemap.videos.locale.page', ['locale' => $locale, 'page' => 1]))->assertOk()->getContent();
    }

    /** @return list<string> the hreflang values of the video's entry in a sitemap, sorted */
    private function alternates(string $xml, Video $video): array
    {
        foreach (explode('<url>', $xml) as $entry) {
            if (preg_match('#<loc>[^<]*'.preg_quote((string) $video->uuid, '#').'</loc>#', $entry) === 1) {
                preg_match_all('#hreflang="([^"]+)"#', $entry, $matches);
                $languages = array_values(array_unique($matches[1]));
                sort($languages);

                return $languages;
            }
        }

        $this->fail('the sitemap lists '.$video->title);
    }
}
