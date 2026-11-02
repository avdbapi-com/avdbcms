<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\ContentSafety;
use App\Support\SeoSitemapCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (lane KS): the video sitemaps, the public machine-read list of the site's videos.
 *
 * R3-003: they never print the name of a category visitors never see (its name uses a word that is always blocked,
 * App\Support\ContentSafety), nor a stored translation (a title, a type, a description) that uses such a word: the
 * watch page shows the video's own title then, and so does the sitemap. A link format with {category} names no hidden
 * category either.
 * R3-017: they list a video in a language only where its watch page is indexable (an AVDBAPI import only in English,
 * the fallback language and the languages it has a translation of: App\Support\VideoLanguages), and their language
 * alternates point only to such pages: every address a video sitemap lists answers 200 without "noindex". Since the
 * decision of 29 Oct the watch page opens in every language (the lists show the video there) and answers "noindex,
 * follow" where it is not indexable; the sitemaps never offer such a page (tests/Feature/UntranslatedImportPageTest.php).
 *
 * Test data: neutral titles and names with one listed word.
 */
class VideoSitemapContentSafetyTest extends TestCase
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
        // the site's default language stays English (App::setLocale() of a page in another language changes app.locale,
        // which the default would otherwise follow in this one test process)
        config(['site.default_locale' => 'en']);
        $this->creator = User::factory()->create(['email_verified_at' => now(), 'username' => 'qa_sitemap_channel']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);

        parent::tearDown();
    }

    public function test_the_video_sitemaps_never_name_a_hidden_category_or_a_blocked_translation(): void
    {
        $hidden = Category::create(['title' => 'QA child category', 'slug' => 'qa-child-category', 'in_menu' => true, 'position' => 1]);
        $garden = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 2]);
        $inHidden = $this->video('QA category test video', $hidden);
        $inGarden = $this->video('QA evening walk', $garden);
        $imported = $this->video('QA imported title', $garden, ['avdb_id' => 12345]);
        $this->translate($imported, 'fr', 'QA traduction child', 'QA description child', 'QA child genre');
        $this->translate($imported, 'vi', 'Buổi tối QA', 'Mô tả QA', 'Thể loại QA');

        foreach (['en', 'fr', 'vi', 'de'] as $locale) {
            $xml = $this->sitemap($locale);
            $this->assertStringNotContainsStringIgnoringCase('QA child', $xml, $locale.': no hidden category, no blocked title, type or description');
            $this->assertStringNotContainsString('qa-child-category', $xml, $locale.': not even its address');
        }

        $en = $this->sitemap('en');
        $this->assertSame('', $this->videoField($en, $inHidden, 'category'), 'a video of a hidden category is listed without it');
        $this->assertSame('QA category test video', $this->videoField($en, $inHidden, 'title'));
        $this->assertSame('QA garden category', $this->videoField($en, $inGarden, 'category'), 'a category visitors see is named');

        // the translation that uses a blocked word gives way to the video's own texts, as on the watch page
        $fr = $this->sitemap('fr');
        $this->assertSame('QA imported title', $this->videoField($fr, $imported, 'title'));
        $this->assertSame('QA garden category', $this->videoField($fr, $imported, 'category'));
        $this->assertStringNotContainsString('description child', $this->videoField($fr, $imported, 'description'));

        $_SERVER['DETECTED_LOCALE'] = 'fr';
        $watch = (string) $this->get($this->path($imported))->assertOk()->getContent();
        unset($_SERVER['DETECTED_LOCALE']);
        $this->assertStringContainsString('QA imported title', $watch);
        $this->assertStringNotContainsString('QA traduction child', $watch, 'the watch page shows the same title');
        $this->assertStringNotContainsString('description child', $watch, 'and the same description');

        // clean translations are used as before
        $vi = $this->sitemap('vi');
        $this->assertSame('Buổi tối QA', $this->videoField($vi, $imported, 'title'));
        $this->assertSame('Thể loại QA', $this->videoField($vi, $imported, 'category'));
        $this->assertSame('Mô tả QA', $this->videoField($vi, $imported, 'description'));

        $this->assertStringNotContainsStringIgnoringCase('child', (string) $this->get(route('seo.sitemap'))->assertOk()->getContent(), 'the index names nothing');
    }

    public function test_every_address_a_video_sitemap_lists_answers_200(): void
    {
        $garden = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 1]);
        $member = $this->video('QA member upload', $garden);
        $frenchOnly = $this->video('QA French only import', $garden, ['avdb_id' => 22222]);
        $this->translate($frenchOnly, 'fr', 'QA import en français', 'Description française', null);
        $twoLanguages = $this->video('QA two language import', $garden, ['avdb_id' => 33333]);
        $this->translate($twoLanguages, 'fr', 'QA deux langues', 'Description deux', null);
        $this->translate($twoLanguages, 'vi', 'QA hai ngôn ngữ', 'Mô tả hai', null);

        $index = (string) $this->get(route('seo.sitemap'))->assertOk()->getContent();
        preg_match_all('#<loc>([^<]*sitemap-videos-[a-z]{2}-\d+\.xml)</loc>#', $index, $sitemaps);
        $this->assertNotEmpty($sitemaps[1]);
        $checked = 0;

        foreach ($sitemaps[1] as $sitemap) {
            $xml = (string) $this->get($sitemap)->assertOk()->getContent();
            preg_match_all('#<loc>([^<]+)</loc>|<xhtml:link rel="alternate" hreflang="[^"]+" href="([^"]+)"/>#', $xml, $matches, PREG_SET_ORDER);

            foreach (array_unique(array_map(fn (array $match): string => html_entity_decode($match[2] ?? '' ?: $match[1]), $matches)) as $address) {
                $page = $this->visit($address);
                $this->assertSame(200, $page->getStatusCode(), $address.' (listed in '.$sitemap.') answers');
                // ... and is to be indexed: a sitemap never offers a page that says noindex (the decision of 29 Oct)
                $this->assertNull($page->headers->get('X-Robots-Tag'), $address.' (listed in '.$sitemap.') is indexable');
                $this->assertStringNotContainsString('<meta name="robots" content="noindex', (string) $page->getContent(), $address);
                $checked++;
            }
        }

        $this->assertGreaterThan(20, $checked);

        // the French-only import: in English and French only, and its alternates are exactly those two pages
        foreach (['de', 'ar', 'vi'] as $locale) {
            $this->assertStringNotContainsString($frenchOnly->uuid, $this->sitemap($locale), $locale.' does not list it');
        }

        $fr = $this->sitemap('fr');
        $this->assertSame(['en', 'fr', 'x-default'], $this->alternates($fr, $frenchOnly));
        $this->assertSame(['en', 'fr', 'vi', 'x-default'], $this->alternates($fr, $twoLanguages));
        $this->assertContains('de', $this->alternates($this->sitemap('en'), $member), 'a member\'s video is indexable in every language');

        // its German page opens (the German lists show the video: the decision of 29 Oct) but is not to be indexed, which is
        // why no sitemap lists it and no alternate names it
        $german = $this->visit(url('/de'.$this->path($frenchOnly)));
        $this->assertSame(200, $german->getStatusCode());
        $this->assertSame('noindex, follow', $german->headers->get('X-Robots-Tag'));
    }

    public function test_the_watch_page_names_only_the_languages_it_is_indexable_in(): void
    {
        $garden = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 1]);
        $member = $this->video('QA member upload', $garden);
        $frenchOnly = $this->video('QA French only import', $garden, ['avdb_id' => 55555]);
        $this->translate($frenchOnly, 'fr', 'QA import en français', 'Description française', null);

        // its French page and its German one (which opens with noindex: the decision of 29 Oct) name the same languages:
        // the ones its page is indexable in, never German
        foreach (['fr', 'de'] as $locale) {
            $page = (string) $this->visit(url('/'.$locale.$this->path($frenchOnly)))->assertOk()->getContent();
            preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]+)">#', $page, $links, PREG_SET_ORDER);
            $this->assertSame(['en', 'fr', 'x-default'], collect($links)->pluck(1)->sort()->values()->all(), $locale);

            foreach ($links as [, $language, $address]) {
                $alternate = $this->visit($address);
                $this->assertSame(200, $alternate->getStatusCode(), $locale.' page, '.$language.': '.$address);
                $this->assertNull($alternate->headers->get('X-Robots-Tag'), $locale.' page, '.$language.': '.$address.' is indexable');
            }
        }

        // a member's video answers in every language, and its page says so
        $this->flushSession();
        app()->setLocale('en');
        preg_match_all('#<link rel="alternate" hreflang="([^"]+)"#', (string) $this->get($this->path($member))->assertOk()->getContent(), $all);
        $this->assertCount(count(config('languages')) + 1, $all[1]);
    }

    public function test_the_index_lists_no_video_sitemap_of_a_language_where_no_video_answers(): void
    {
        $garden = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 1]);
        $frenchOnly = $this->video('QA French only import', $garden, ['avdb_id' => 44444]);
        $this->translate($frenchOnly, 'fr', 'QA import en français', null, null);

        $index = (string) $this->get(route('seo.sitemap'))->assertOk()->getContent();

        $this->assertStringContainsString(url('/sitemap-videos-en-1.xml'), $index);
        $this->assertStringContainsString(url('/sitemap-videos-fr-1.xml'), $index);
        $this->assertStringNotContainsString(url('/sitemap-videos-de-1.xml'), $index);
        $this->assertStringNotContainsString(url('/sitemap-videos-ja-1.xml'), $index);
        $this->assertStringNotContainsString($frenchOnly->uuid, $this->sitemap('de'));
    }

    public function test_the_indexs_language_check_counts_exactly_what_a_video_sitemap_lists(): void
    {
        $garden = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 1]);
        $frenchOnly = $this->video('QA French only import', $garden, ['avdb_id' => 44444]);
        $this->translate($frenchOnly, 'fr', 'QA import en français', null, null);
        // a German translation of an import visitors do not see counts for nothing
        $private = $this->video('QA private import', $garden, ['avdb_id' => 55555, 'status' => VideoStatus::PRIVATE->value, 'published_at' => null]);
        $this->translate($private, 'de', 'QA privater Import', null, null);
        $this->assertIndexLists(['en', 'fr'], ['de', 'ja', 'vi']);

        // a member's upload in Japanese answers in every language; the sitemaps list it in its own language
        $this->video('QA Japanese upload', $garden, ['language' => 'ja']);
        $this->assertIndexLists(['en', 'fr', 'ja'], ['de', 'vi']);

        // an import with its German translation
        $german = $this->video('QA German import', $garden, ['avdb_id' => 66666]);
        $this->translate($german, 'de', 'QA deutscher Import', null, null);
        $this->assertIndexLists(['en', 'fr', 'ja', 'de'], ['vi']);

        // the AVDBAPI number 0 is no import (its page answers in every language, as a member's upload): the sitemaps list
        // it in its own language, so a language it is not listed in stays out of the index
        $this->video('QA number zero video', $garden, ['avdb_id' => 0, 'language' => 'ja']);
        $this->assertIndexLists(['en', 'fr', 'ja', 'de'], ['vi']);
    }

    public function test_the_index_checks_a_language_without_reading_every_imported_video(): void
    {
        $garden = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 1]);
        $frenchOnly = $this->video('QA French only import', $garden, ['avdb_id' => 44444]);
        $this->translate($frenchOnly, 'fr', 'QA import en français', null, null);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->get(route('seo.sitemap'))->assertOk();

        // each request of the index checks every language (uncached on purpose): a language without a translated import
        // is found from the translations' language index (a join from video_translations), never by a lookup per video
        // of the whole videos table (an EXISTS correlated on videos.id), which read every imported video once per language
        $correlated = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, '"video_translations"."video_id" = "videos"."id"')));
        $this->assertSame([], $correlated);
    }

    public function test_a_link_format_with_the_category_never_names_a_hidden_one(): void
    {
        config(['site.video_url_pattern' => '{category}/{slug}-{uuid}']);
        $hidden = Category::create(['title' => 'QA child category', 'slug' => 'qa-child-category', 'in_menu' => true, 'position' => 1]);
        $garden = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 2]);
        $inHidden = $this->video('QA category test video', $hidden);
        $inGarden = $this->video('QA evening walk', $garden);
        $seo = app(SeoService::class);

        $this->assertSame('/video/videos/qa-category-test-video-'.$inHidden->uuid, $seo->videoPath($inHidden), 'like a video without a category');
        $this->assertSame('/video/qa-garden-category/qa-evening-walk-'.$inGarden->uuid, $seo->videoPath($inGarden));
        $this->get($seo->videoPath($inHidden))->assertOk()->assertDontSee('qa-child-category');
        $this->assertStringNotContainsString('qa-child-category', $this->sitemap('en'));
    }

    /** A public video of the test channel. */
    private function video(string $title, Category $category, array $attributes = []): Video
    {
        return Video::factory()->create(array_merge([
            // a real one: the watch page's address only takes a UUID (the factory's number is no address)
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->creator->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => $title.' description.',
            'category_id' => $category->id,
            'status' => VideoStatus::PUBLIC->value,
            'published_at' => now()->subDays(2),
            'uploaded_at' => now()->subDays(2),
            'language' => null,
            'embed_code' => null,
        ], $attributes));
    }

    /**
     * The index lists the video sitemap of each language of $listed (and that sitemap lists a video), and none of
     * $absent (whose sitemap lists no video).
     *
     * @param  list<string>  $listed
     * @param  list<string>  $absent
     */
    private function assertIndexLists(array $listed, array $absent): void
    {
        Cache::flush();
        SeoSitemapCache::clear();
        $index = (string) $this->get(route('seo.sitemap'))->assertOk()->getContent();

        foreach ($listed as $locale) {
            $this->assertStringContainsString(url('/sitemap-videos-'.$locale.'-1.xml'), $index, $locale.': listed');
            $this->assertStringContainsString('<url>', $this->sitemap($locale), $locale.': its sitemap lists a video');
        }

        foreach ($absent as $locale) {
            $this->assertStringNotContainsString(url('/sitemap-videos-'.$locale.'-'), $index, $locale.': not listed');
            $this->assertStringNotContainsString('<url>', $this->sitemap($locale), $locale.': its sitemap lists no video');
        }
    }

    private function translate(Video $video, string $locale, string $title, ?string $description, ?string $type): void
    {
        DB::table('video_translations')->insert([
            'video_id' => $video->id, 'locale' => $locale, 'title' => $title, 'description' => $description,
            'type_name' => $type, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sitemap(string $locale): string
    {
        $response = $this->get(route('seo.sitemap.videos.locale.page', ['locale' => $locale, 'page' => 1]))->assertOk();
        $xml = (string) $response->getContent();
        $document = new \DOMDocument();
        $this->assertTrue(@$document->loadXML($xml), $locale.' sitemap is XML');

        return $xml;
    }

    /** The <video:$field> text of the video's entry in a sitemap ('' when the entry has none). */
    private function videoField(string $xml, Video $video, string $field): string
    {
        $entry = $this->entry($xml, $video);

        return preg_match('#<video:'.$field.'><!\[CDATA\[(.*?)\]\]></video:'.$field.'>#s', $entry, $match) === 1 ? $match[1] : '';
    }

    /** @return list<string> the hreflang values of the video's entry in a sitemap */
    private function alternates(string $xml, Video $video): array
    {
        preg_match_all('#hreflang="([^"]+)"#', $this->entry($xml, $video), $matches);
        $languages = $matches[1];
        sort($languages);

        return array_values(array_unique($languages));
    }

    private function entry(string $xml, Video $video): string
    {
        foreach (explode('<url>', $xml) as $entry) {
            if (preg_match('#<loc>[^<]*'.preg_quote((string) $video->uuid, '#').'</loc>#', $entry) === 1) {
                return $entry;
            }
        }

        $this->fail('the sitemap lists '.$video->title);
    }

    private function path(Video $video): string
    {
        return app(SeoService::class)->videoPath($video);
    }

    /** GET an absolute address of the site, its language prefix taken off as public/index.php does. */
    private function visit(string $address): \Illuminate\Testing\TestResponse
    {
        $path = '/'.ltrim((string) parse_url($address, PHP_URL_PATH), '/');
        $segments = explode('/', trim($path, '/'));
        unset($_SERVER['DETECTED_LOCALE']);
        // a fresh visitor each time: the test's session store would otherwise keep the language of the page before
        $this->flushSession();
        app()->setLocale('en');

        if (isset(config('languages')[$segments[0] ?? '']) && count($segments) > 1) {
            $_SERVER['DETECTED_LOCALE'] = array_shift($segments);
            $path = '/'.implode('/', $segments);
        }

        try {
            return $this->get($path);
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
        }
    }
}
