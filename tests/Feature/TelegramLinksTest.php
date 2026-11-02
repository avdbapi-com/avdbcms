<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\AppearanceApplier;
use App\Support\AppearanceSettings;
use App\Support\FooterSettings;
use App\Support\PendingMigrations;
use App\Support\SettingsRevision;
use App\Support\TelegramLinks;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 1.2.3 (owner, 2026-10-26): the public AVDBAPI Telegram group t.me/avdb_api is gone. AVDBCMS links the new private group
 * (https://t.me/+jj6McO-iYfA0MTM1) and offers personal support (https://t.me/avdbapi_support) next to it: the footer's
 * Support links of a new site, the Telegram social link, the AVDBAPI import page, the installer and the documents.
 * Migration 2026_10_26_200000_new_telegram_links gives an existing site's stored settings the new group (the shipped
 * Support links become the new list; an owner's own text only gets the new address), logs it in Tools > Logs > Admin
 * actions and changes nothing else; it is safe to run twice. "Restore my previous look", "Use the new look again" and
 * Undo in the design history can bring appearance rows from before 1.2.3 back: the same change runs after them
 * (TelegramLinks::fixStoredSettings()). A stored old address that comes back otherwise (an old backup) still links the
 * new group in the footer and the social link.
 */
class TelegramLinksTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_26_200000_new_telegram_links';

    private const GROUP = 'https://t.me/+jj6McO-iYfA0MTM1';

    private const SUPPORT = 'https://t.me/avdbapi_support';

    private const OLD = 'https://t.me/avdb_api';

    /** The "Support" links 1.2.0 - 1.2.2 shipped. */
    private const OLD_SUPPORT_LINKS = "Contact|/contact\nTelegram Support|https://t.me/avdb_api\nTerms of Service|/terms";

    /** The "Support" links from 1.2.3 on. */
    private const NEW_SUPPORT_LINKS = "Contact|/contact\nTelegram group|https://t.me/+jj6McO-iYfA0MTM1\nTelegram support|https://t.me/avdbapi_support\nTerms of Service|/terms";

    private const AUDIT = 'update.telegram_links';

    private const WRITTEN_AT = '2026-10-20 08:00:00';

    private static ?object $migration = null;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function migration(): object
    {
        return self::$migration ??= require database_path('migrations/'.self::MIGRATION.'.php');
    }

    public function test_the_links_are_the_owners_new_group_and_personal_support(): void
    {
        $this->assertSame(self::GROUP, TelegramLinks::GROUP_URL);
        $this->assertSame('t.me/+jj6McO-iYfA0MTM1', TelegramLinks::GROUP_TEXT);
        $this->assertSame(self::SUPPORT, TelegramLinks::SUPPORT_URL);
        $this->assertSame('@avdbapi_support', TelegramLinks::SUPPORT_HANDLE);
        $this->assertSame('https://'.TelegramLinks::GROUP_TEXT, TelegramLinks::GROUP_URL);
        $this->assertSame('https://t.me/'.ltrim(TelegramLinks::SUPPORT_HANDLE, '@'), TelegramLinks::SUPPORT_URL);
    }

    public function test_a_new_site_offers_the_group_and_personal_support(): void
    {
        $this->assertSame(self::NEW_SUPPORT_LINKS, FooterSettings::defaults()['footer_support_links']);
        $this->assertSame(self::NEW_SUPPORT_LINKS, config('admin_settings.settings.footer_support_links.default'), 'config/admin_settings.php (generate_config.py)');
        $this->assertSame(self::NEW_SUPPORT_LINKS, AppearanceSettings::defaults()['footer_support_links']);
        $this->assertSame(
            [
                ['label' => 'Telegram group', 'url' => self::GROUP, 'external' => true],
                ['label' => 'Telegram support', 'url' => self::SUPPORT, 'external' => true],
            ],
            array_values(array_filter(FooterSettings::parseLinks(self::NEW_SUPPORT_LINKS), fn (array $link): bool => $link['external']))
        );

        // A site built from the migrations alone: the new links, and nothing in Admin actions.
        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRATION)->exists());
        $this->assertSame(self::GROUP, DB::table('settings')->where('key', 'social_telegram')->value('value'));
        $this->assertSame(self::NEW_SUPPORT_LINKS, DB::table('settings')->where('key', 'footer_support_links')->value('value'));
        $this->assertSame(0, DB::table('admin_audit_logs')->count(), 'a new site starts with an empty Admin actions list');
    }

    public function test_the_migration_knows_the_links_that_122_shipped(): void
    {
        $this->assertSame(self::OLD_SUPPORT_LINKS, $this->migration()::OLD_SUPPORT_LINKS);
        $this->assertSame(self::OLD_SUPPORT_LINKS, TelegramLinks::OLD_SUPPORT_LINKS);

        // The frozen copy of the old code's defaults that the theme catalogue upgrade of 1.2.0 classifies against.
        $source = str_replace("\r\n", "\n", (string) file_get_contents(database_path('migrations/2026_10_20_000002_migrate_appearance_to_theme_catalog_v2.php')));
        $this->assertSame(1, preg_match("/private const FACTORY_SOURCES = <<<'JSON'\n(.*?)\nJSON;/s", $source, $match));
        $frozen = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::OLD_SUPPORT_LINKS, $frozen['old_defaults']['footer_support_links']);
    }

    public function test_the_telegram_social_link_is_the_new_group(): void
    {
        DB::table('settings')->where('key', 'social_telegram')->delete();
        Setting::clearCache();
        $this->applySettingsLikeAWebRequest();
        $this->assertSame(self::GROUP, config('site.social_telegram'), 'nothing stored: the AVDBAPI group');

        $cases = [
            self::OLD => self::GROUP,
            'http://telegram.me/AVDB_API/' => self::GROUP,
            '@avdb_api' => self::GROUP,
            'https://t.me/my_own_channel' => 'https://t.me/my_own_channel',
            'https://t.me/avdb_api_news' => 'https://t.me/avdb_api_news',
            '' => '',
        ];

        foreach ($cases as $stored => $shown) {
            Setting::set('social_telegram', (string) $stored, 'sociallinks');
            $this->applySettingsLikeAWebRequest();
            $this->assertSame($shown, config('site.social_telegram'), "stored '{$stored}'");
            $this->assertSame((string) $stored, DB::table('settings')->where('key', 'social_telegram')->value('value'), "showing '{$stored}' writes nothing");
        }
    }

    public function test_the_retired_group_is_recognised_in_its_spellings_only(): void
    {
        foreach ([self::OLD, 'http://t.me/avdb_api', 't.me/avdb_api', 'telegram.me/avdb_api', 'https://telegram.me/avdb_api',
            'https://www.t.me/avdb_api', '//t.me/avdb_api', 'HTTPS://T.ME/AVDB_API', 'https://t.me/avdb_api/', 'https://t.me/avdb_api/123',
            '@avdb_api', ' https://t.me/avdb_api '] as $old) {
            $this->assertTrue(TelegramLinks::isRetiredGroup($old), $old);
            $this->assertSame(self::GROUP, TelegramLinks::current($old), $old);
        }

        foreach ([self::GROUP, self::SUPPORT, 'https://t.me/avdb_api_bot', 'https://t.me/avdb_api2', 'https://t.me/avdb_api?start=1',
            'https://example.com/t.me/avdb_api', 'https://avdbapi.com/api.php/provide/vod', 'avdb_api', '', null] as $other) {
            $this->assertFalse(TelegramLinks::isRetiredGroup($other), (string) $other);
            $this->assertSame($other, TelegramLinks::current($other), (string) $other);
        }
    }

    public function test_only_the_old_address_changes_in_a_text(): void
    {
        $cases = [
            "Contact|/contact\nTelegram Support|https://t.me/avdb_api\nFAQ|/faq" => "Contact|/contact\nTelegram Support|https://t.me/+jj6McO-iYfA0MTM1\nFAQ|/faq",
            'Join t.me/avdb_api. Or http://telegram.me/AVDB_API/42!' => 'Join t.me/+jj6McO-iYfA0MTM1. Or https://t.me/+jj6McO-iYfA0MTM1!',
            '<a href="//t.me/avdb_api/" rel="nofollow">Telegram</a>' => '<a href="https://t.me/+jj6McO-iYfA0MTM1" rel="nofollow">Telegram</a>',
            json_encode(['url' => self::OLD, 'bot' => 'https://t.me/avdb_api_bot']) => json_encode(['url' => self::GROUP, 'bot' => 'https://t.me/avdb_api_bot']),
            'https://t.me/avdb_api_bot https://example.com/t.me/avdb_api avdb_api_base_url @avdb_api' => 'https://t.me/avdb_api_bot https://example.com/t.me/avdb_api avdb_api_base_url @avdb_api',
        ];

        foreach ($cases as $text => $expected) {
            $this->assertSame($expected, TelegramLinks::replaceRetiredGroup($text), $text);
        }

        $this->assertSame(['url' => self::GROUP, 'bot' => 'https://t.me/avdb_api_bot'], json_decode(TelegramLinks::replaceRetiredGroup((string) json_encode(['url' => self::OLD, 'bot' => 'https://t.me/avdb_api_bot'])), true), 'a JSON value stays valid');
    }

    public function test_a_122_site_gets_the_new_links_once_and_nothing_else_changes(): void
    {
        User::factory()->admin()->create();
        $this->site122([
            'social_telegram' => [self::OLD, 'sociallinks'],
            'footer_support_links' => [self::OLD_SUPPORT_LINKS, 'appearance'],
            'footer_bottom_links' => ["Terms|/terms\nOur Telegram|https://t.me/avdb_api/\nContact|/contact", 'appearance'],
            'footer_explore_links' => ["Home|/\nNews bot|https://t.me/avdb_api_bot", 'appearance'],
            'ad_code_header' => ['<a href="https://telegram.me/AVDB_API" rel="sponsored">Join us</a>', 'monetization'],
            'telegram_test_json' => [json_encode(['links' => [['url' => self::OLD]]]), 'appearance'],
            'telegram_test_note' => ['Imports use avdb_api_base_url; ask @avdb_api', 'general'],
            'avdb_api_base_url' => ['https://avdbapi.com/api.php/provide/vod', 'avdb'],
        ]);
        $this->assertSame(self::OLD, Setting::getGroup('sociallinks')['social_telegram'], 'cached before the update');
        $untouched = $this->rowsExcept(['social_telegram', 'footer_support_links', 'footer_bottom_links', 'ad_code_header', 'telegram_test_json']);

        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->migration()->up();

        $this->assertSame(self::GROUP, $this->stored('social_telegram'));
        $this->assertSame(self::NEW_SUPPORT_LINKS, $this->stored('footer_support_links'), 'the shipped list becomes the new one');
        $this->assertSame("Terms|/terms\nOur Telegram|https://t.me/+jj6McO-iYfA0MTM1\nContact|/contact", $this->stored('footer_bottom_links'), 'only the address changes');
        $this->assertSame('<a href="https://t.me/+jj6McO-iYfA0MTM1" rel="sponsored">Join us</a>', $this->stored('ad_code_header'));
        $this->assertSame(['links' => [['url' => self::GROUP]]], json_decode((string) $this->stored('telegram_test_json'), true));
        $this->assertSame($untouched, $this->rowsExcept(['social_telegram', 'footer_support_links', 'footer_bottom_links', 'ad_code_header', 'telegram_test_json']), 'no other row changes');

        foreach (['social_telegram', 'footer_support_links', 'footer_bottom_links', 'ad_code_header', 'telegram_test_json'] as $key) {
            $this->assertSame('2026-11-01 10:00:00', (string) DB::table('settings')->where('key', $key)->value('updated_at'), "{$key} records when it changed");
        }

        $this->assertSame(self::GROUP, Setting::getGroup('sociallinks')['social_telegram'], 'the settings cache was cleared');
        $this->assertSame(self::NEW_SUPPORT_LINKS, Setting::getGroup('appearance')['footer_support_links']);

        // Tools > Logs > Admin actions says what changed, in full in both admins' lists.
        $audit = DB::table('admin_audit_logs')->where('action', self::AUDIT)->get();
        $this->assertCount(1, $audit);
        $this->assertNull($audit[0]->user_id);
        $this->assertSame('1.2.3: new AVDBAPI Telegram group t.me/+jj6McO-iYfA0MTM1 in: Telegram channel and 4 more', $audit[0]->details, 'five settings: the first and how many more');
        $this->assertLessThanOrEqual($this->migration()::AUDIT_MAX_LENGTH, mb_strlen($audit[0]->details));
        $this->assertSame(1, DB::table('admin_audit_logs')->count(), 'no other Admin actions row');

        // Again (a second Finish, an update run twice): nothing matches, nothing is written or logged.
        $after = $this->rowsExcept([]);
        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->migration()->up();
        $this->assertSame($after, $this->rowsExcept([]));
        $this->assertSame(1, DB::table('admin_audit_logs')->count());
    }

    public function test_a_typical_122_site_names_both_changes_in_admin_actions(): void
    {
        User::factory()->admin()->create();
        $this->site122(['social_telegram' => [self::OLD, 'sociallinks'], 'footer_support_links' => [self::OLD_SUPPORT_LINKS, 'appearance']]);

        $this->migration()->up();

        $this->assertSame(self::GROUP, $this->stored('social_telegram'));
        $this->assertSame(self::NEW_SUPPORT_LINKS, $this->stored('footer_support_links'));
        $this->assertSame(
            ['1.2.3: new AVDBAPI Telegram group t.me/+jj6McO-iYfA0MTM1 in: Telegram channel, footer Support links (group + support)'],
            DB::table('admin_audit_logs')->where('action', self::AUDIT)->pluck('details')->all()
        );
    }

    public function test_an_owners_own_support_links_keep_their_lines(): void
    {
        User::factory()->admin()->create();
        $own = "Help|/help\r\nTelegram Support|https://t.me/avdb_api\r\nFAQ|/faq";
        $this->site122(['footer_support_links' => [$own, 'appearance'], 'social_telegram' => ['https://t.me/my_own_channel', 'sociallinks']]);

        $this->migration()->up();

        $this->assertSame("Help|/help\r\nTelegram Support|https://t.me/+jj6McO-iYfA0MTM1\r\nFAQ|/faq", $this->stored('footer_support_links'), 'the owner list keeps its lines and line endings; no line is added');
        $this->assertSame('https://t.me/my_own_channel', $this->stored('social_telegram'), "the owner's own channel stays");
        $this->assertSame(['1.2.3: new AVDBAPI Telegram group t.me/+jj6McO-iYfA0MTM1 in: footer Support links'], DB::table('admin_audit_logs')->where('action', self::AUDIT)->pluck('details')->all());
    }

    public function test_the_shipped_list_saved_again_without_changes_counts_as_the_shipped_list(): void
    {
        User::factory()->admin()->create();

        foreach (["Contact|/contact\r\nTelegram Support|https://t.me/avdb_api\r\nTerms of Service|/terms\r\n",
            "Contact | /contact\nTelegram Support | https://t.me/avdb_api\n\nTerms of Service | /terms"] as $saved) {
            $this->site122(['footer_support_links' => [$saved, 'appearance']]);
            $this->migration()->up();
            $this->assertSame(self::NEW_SUPPORT_LINKS, $this->stored('footer_support_links'), json_encode($saved));
        }

        // A changed label is the owner's: only the address changes.
        $this->site122(['footer_support_links' => [str_replace('Telegram Support', 'Our Telegram', self::OLD_SUPPORT_LINKS), 'appearance']]);
        $this->migration()->up();
        $this->assertSame("Contact|/contact\nOur Telegram|https://t.me/+jj6McO-iYfA0MTM1\nTerms of Service|/terms", $this->stored('footer_support_links'));
    }

    public function test_a_site_without_the_old_links_is_not_touched(): void
    {
        User::factory()->admin()->create();
        $this->site122([
            'social_telegram' => ['', 'sociallinks'],
            'footer_support_links' => [self::NEW_SUPPORT_LINKS, 'appearance'],
            'footer_bottom_links' => ["Terms|/terms\nBot|https://t.me/avdb_api_bot", 'appearance'],
        ]);
        $before = $this->rowsExcept([]);

        $this->migration()->up();

        $this->assertSame($before, $this->rowsExcept([]));
        $this->assertSame(0, DB::table('admin_audit_logs')->count(), 'nothing changed, nothing logged');
    }

    public function test_a_site_being_built_gets_the_new_links_without_an_admin_actions_row(): void
    {
        $this->assertSame(0, DB::table('users')->count());
        $this->site122(['social_telegram' => [self::OLD, 'sociallinks'], 'footer_support_links' => [self::OLD_SUPPORT_LINKS, 'appearance']]);

        $this->migration()->up();

        $this->assertSame(self::GROUP, $this->stored('social_telegram'));
        $this->assertSame(self::NEW_SUPPORT_LINKS, $this->stored('footer_support_links'));
        $this->assertSame(0, DB::table('admin_audit_logs')->count(), 'the defaults of a new site are no news');
    }

    public function test_the_admin_actions_text_fits_the_lists(): void
    {
        $details = $this->migration()::auditDetails(['Telegram channel', 'footer Support links (group + support)', 'Bottom line links', 'Ad code in the header', 'Announcement text']);

        $this->assertSame('1.2.3: new AVDBAPI Telegram group t.me/+jj6McO-iYfA0MTM1 in: Telegram channel and 4 more', $details);
        $this->assertSame('1.2.3: new AVDBAPI Telegram group t.me/+jj6McO-iYfA0MTM1 in: Telegram channel', $this->migration()::auditDetails(['Telegram channel']));
        $this->assertSame('1.2.3: new AVDBAPI Telegram group t.me/+jj6McO-iYfA0MTM1 in 1 setting', $this->migration()::auditDetails([str_repeat('x', 200)]));
    }

    public function test_the_footer_shows_the_group_and_support_and_never_the_old_group(): void
    {
        $this->withoutVite();
        $this->applySettingsLikeAWebRequest();
        AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));

        $html = view('layouts.default')->render();
        $this->assertMatchesRegularExpression('#<a href="https://t\.me/\+jj6McO-iYfA0MTM1"\s+target="_blank" rel="noopener"\s*>Telegram group</a>#', $html);
        $this->assertMatchesRegularExpression('#<a href="https://t\.me/avdbapi_support"\s+target="_blank" rel="noopener"\s*>Telegram support</a>#', $html);
        $this->assertNoOldGroup($html);

        // An old list that came back (a restored revision, an old backup) links the new group; nothing is written.
        Setting::set('footer_support_links', self::OLD_SUPPORT_LINKS, 'appearance');
        Setting::set('social_telegram', self::OLD, 'sociallinks');
        $this->applySettingsLikeAWebRequest();
        AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));

        $html = view('layouts.default')->render();
        $this->assertMatchesRegularExpression('#<a href="https://t\.me/\+jj6McO-iYfA0MTM1"\s+target="_blank" rel="noopener"\s*>Telegram Support</a>#', $html);
        $this->assertMatchesRegularExpression('#<a href="https://t\.me/\+jj6McO-iYfA0MTM1" target="_blank" rel="noopener" title="Telegram"#', $html, 'the social icon');
        $this->assertNoOldGroup($html);
        $this->assertSame(self::OLD_SUPPORT_LINKS, Setting::getGroup('appearance')['footer_support_links'], 'showing it writes nothing');
    }

    /** The Support links of a site from before 1.2.0: the 1.2.2 list stored (the usual case), or never stored. */
    public static function supportLinksBeforeTheUpdate(): array
    {
        return [
            'the 1.2.2 list is stored' => [self::OLD_SUPPORT_LINKS],
            'the Support links were never stored' => [null],
        ];
    }

    /**
     * A site from before the theme catalogue v2 updates to 1.2.3 (the test sites updated on 22-23 Oct and
     * keep their previous look for 30 days): "Finish update" runs the theme upgrade and then the Telegram change. Home
     * then offers "Restore my previous look" (the appearance rows of before the update) and "Use the new look again" (the
     * theme upgrade once more, whose frozen copy of the old defaults holds the 1.2.2 list for Support links a site never
     * stored). Both end with the group and personal support, in the footer and in the admin's Support links field, and
     * nothing else of the look changes.
     */
    #[DataProvider('supportLinksBeforeTheUpdate')]
    public function test_the_previous_look_and_the_new_look_again_keep_the_group_and_personal_support(?string $supportLinks): void
    {
        $this->withoutVite();
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $this->makeInstallFromBefore120($supportLinks);
        $this->assertContains(self::MIGRATION, PendingMigrations::pending());
        $previousLook = SettingsRevision::rows('appearance');
        $this->assertSame($supportLinks, $previousLook['footer_support_links'] ?? null);

        // Finish update: the theme upgrade (it keeps the previous look as revision #0), then the Telegram change.
        $this->actingAs($admin)->post(route('admin.update.finish'))->assertRedirect(route('admin.index'))->assertSessionHas('success');
        $this->assertSame([], PendingMigrations::pending());
        $this->assertSame(self::NEW_SUPPORT_LINKS, $this->stored('footer_support_links'));
        $this->assertSame(self::GROUP, $this->stored('social_telegram'));
        $newLook = SettingsRevision::rows('appearance');
        $this->assertNotNull(SettingsRevision::upgradeSnapshot());

        // Restore my previous look: the rows of before the update, byte for byte, except the Support links, which keep
        // the group and personal support (a list that was never stored stays unstored: the default is the new list).
        $this->actingAs($admin)->post(route('admin.update.restore-look'))->assertSessionHas('success', 'Your previous look is back.');
        $restored = SettingsRevision::rows('appearance');
        $this->assertSame($supportLinks === null ? null : self::NEW_SUPPORT_LINKS, $restored['footer_support_links'] ?? null);
        $this->assertSame(array_diff_key($previousLook, ['footer_support_links' => true]), array_diff_key($restored, ['footer_support_links' => true]), 'the rest of the previous look comes back as it was');
        $this->assertFooterOffersTheGroupAndSupport('after "Restore my previous look"');
        $this->assertSupportLinksFieldShows(self::NEW_SUPPORT_LINKS, $admin, 'after "Restore my previous look"');

        // Use the new look again: the same new look as after the update.
        $this->actingAs($admin)->post(route('admin.update.new-look'))->assertSessionHas('success', 'Your site uses the new look again.');
        $this->assertSame($newLook, SettingsRevision::rows('appearance'));
        $this->assertSame(self::NEW_SUPPORT_LINKS, $this->stored('footer_support_links'));
        $this->assertFooterOffersTheGroupAndSupport('after "Use the new look again"');
        $this->assertSupportLinksFieldShows(self::NEW_SUPPORT_LINKS, $admin, 'after "Use the new look again"');

        // Only the appearance rows were restored; the Telegram channel kept the group. The update logged the change once.
        $this->assertSame(self::GROUP, $this->stored('social_telegram'));
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', self::AUDIT)->count());
        $this->assertSame([], TelegramLinks::fixStoredSettings(), 'nothing left to change');
    }

    /**
     * Undo in the design history puts back the appearance rows of before a change. A change made before 1.2.3 holds
     * the 1.2.2 Support links as its "before": they come back as the group and personal support, the rest as it was.
     */
    public function test_undo_in_the_design_history_keeps_the_group_and_personal_support(): void
    {
        $this->withoutVite();
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $this->site122(['footer_support_links' => [self::OLD_SUPPORT_LINKS, 'appearance']]);
        $titleBefore = $this->stored('footer_support_title');
        $published = AppearanceApplier::applyValues(['footer_support_title' => 'Help desk'], 'publish', (int) $admin->getKey());
        $this->assertNotNull($published['revision'], 'a change published on 1.2.2');

        // The update to 1.2.3.
        $this->migration()->up();
        $this->assertSame(self::NEW_SUPPORT_LINKS, $this->stored('footer_support_links'));

        $this->actingAs($admin)
            ->postJson(route('admin.appearance.restore', ['revision' => $published['revision']]))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame($titleBefore, $this->stored('footer_support_title'), 'the change is undone');
        $this->assertSame(self::NEW_SUPPORT_LINKS, $this->stored('footer_support_links'), 'the 1.2.2 list does not come back');
        $this->assertFooterOffersTheGroupAndSupport('after Undo');
    }

    public function test_the_avdbapi_import_page_offers_the_group_and_personal_support(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['code' => 1, 'total' => 0, 'pagecount' => 0, 'list' => []])]);
        $admin = User::factory()->admin()->create();

        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui]);
            $html = (string) $this->actingAs($admin)->get(route('admin.avdb-import.index'))->assertOk()->getContent();

            // Three places (the top bar, the AVDBAPI card, Quick Help), each with the group and personal support.
            $this->assertSame(3, preg_match_all('#<a href="https://t\.me/\+jj6McO-iYfA0MTM1" target="_blank" rel="noopener"[^>]*>\s*<i class="fa-brands fa-telegram[^"]*"></i>\s*Telegram group\s*</a>#', $html), $ui);
            $this->assertSame(3, preg_match_all('#<a href="https://t\.me/avdbapi_support" target="_blank" rel="noopener"[^>]*>\s*<i class="fa-brands fa-telegram[^"]*"></i>\s*Telegram support\s*</a>#', $html), $ui);
            $this->assertNoOldGroup($html);
        }
    }

    public function test_the_installer_offers_the_group_and_personal_support(): void
    {
        $lock = storage_path('framework/testing/installer-lock-'.uniqid().'.json');
        @mkdir(dirname($lock), 0777, true);
        file_put_contents($lock, '{"installed_at":"2026-10-26"}');
        $this->beforeApplicationDestroyed(fn () => @unlink($lock));
        $installer = \Mockery::mock(\App\Support\Installer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $installer->shouldReceive('lockPath')->andReturn($lock);
        $this->app->instance(\App\Support\Installer::class, $installer);

        $html = (string) $this->get('/install')->assertOk()->assertSee('Need help during install?')->getContent();

        $this->assertMatchesRegularExpression('#<a href="https://t\.me/\+jj6McO-iYfA0MTM1" target="_blank" rel="noopener">\s*<span>Telegram group</span>\s*t\.me/\+jj6McO-iYfA0MTM1\s*</a>#', $html);
        $this->assertMatchesRegularExpression('#<a href="https://t\.me/avdbapi_support" target="_blank" rel="noopener">\s*<span>Telegram support</span>\s*@avdbapi_support\s*</a>#', $html);
        $this->assertNoOldGroup($html);
        $this->assertStringNotContainsString('Avdbapi_support', $html);
    }

    public function test_the_documents_name_the_group_and_personal_support(): void
    {
        foreach (['README.md', 'LICENSE', 'NOTICE', 'UPGRADE.md'] as $file) {
            $text = (string) file_get_contents(base_path($file));

            $this->assertStringContainsString('Telegram group: '.self::GROUP, $text, $file);
            $this->assertStringContainsString('Telegram support (personal): '.self::SUPPORT, $text, $file);
            $this->assertNoOldGroup($text, $file);
            // A plain-text reader can take a full stop or comma right after the invite link into the link.
            $this->assertDoesNotMatchRegularExpression('#'.preg_quote(self::GROUP, '#').'[.,;:!?]#', $text, $file.': punctuation right after the invite link');
        }

        // The license itself (Part A, which the help site quotes word for word and GitHub shows) names both, not only
        // its list of official sources.
        $license = str_replace("\r\n", "\n", (string) file_get_contents(base_path('LICENSE')));
        $this->assertSame(1, preg_match('/\nPART A\. .*?\nPART B\. /s', $license, $partA), 'LICENSE has Part A and Part B');
        $this->assertStringContainsString(
            'Free help is available from the AVDBCMS community on Telegram: Telegram group: '.self::GROUP.' Telegram support (personal): '.self::SUPPORT,
            (string) preg_replace('/\s+/', ' ', $partA[0]),
            'LICENSE Part A, section 3'
        );
    }

    /**
     * No shipped source links the old group or spells the support account otherwise (the migrations that ran on sites
     * before 1.2.3 keep their history; TelegramLinks knows the old address to replace it).
     */
    public function test_no_source_links_the_old_group(): void
    {
        $roots = ['app', 'config', 'routes', 'resources', 'lang', 'database/install', 'database/seeders'];
        $files = ['README.md', 'LICENSE', 'NOTICE', 'UPGRADE.md', 'COMMUNITY_SETUP_GUIDE.md', 'index.php', '404.html', 'manifest.json',
            'public/404.html', 'public/manifest.json', 'public/robots.txt', 'public/index.php'];
        $allowed = ['app/Support/TelegramLinks.php'];
        $found = [];

        foreach ($roots as $root) {
            if (! is_dir(base_path($root))) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match('/\.(php|ts|tsx|js|mjs|json|scss|css|md|sql|txt|html|svg)$/i', $file->getFilename())) {
                    $files[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()))), '/');
                }
            }
        }

        foreach (array_diff(array_unique($files), $allowed) as $file) {
            $text = (string) @file_get_contents(base_path($file));

            if (stripos($text, 'avdb_api') !== false && TelegramLinks::replaceRetiredGroup($text) !== $text) {
                $found[] = $file.': the old group';
            }

            if (preg_match('#(?<![\w.])@avdb_api(?!\w)#i', $text) === 1) {
                $found[] = $file.': @avdb_api';
            }

            if (str_contains($text, 'Avdbapi_support')) {
                $found[] = $file.': Avdbapi_support';
            }
        }

        $this->assertSame([], $found);
    }

    /** @param  array<string, array{0: ?string, 1: string}>  $rows  key => [value, group]: rows of a 1.2.2 site, written days ago */
    private function site122(array $rows): void
    {
        DB::table('admin_audit_logs')->delete();

        foreach ($rows as $key => [$value, $group]) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'group' => $group, 'created_at' => self::WRITTEN_AT, 'updated_at' => self::WRITTEN_AT]);
        }

        Setting::clearCache();
    }

    private function stored(string $key): ?string
    {
        return DB::table('settings')->where('key', $key)->value('value');
    }

    /** @return array<int, array<string, mixed>> every settings row except those of $keys, as stored */
    private function rowsExcept(array $keys): array
    {
        return DB::table('settings')->whereNotIn('key', $keys)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    private function assertNoOldGroup(string $text, string $message = ''): void
    {
        $this->assertSame($text, TelegramLinks::replaceRetiredGroup($text), trim($message.' links the old group'));
        $this->assertDoesNotMatchRegularExpression('#(?:t|telegram)\.me\\\\?/avdb_api(?!\w)#i', $text, trim($message.' names the old group'));
    }

    /**
     * The database of an install from before the theme catalogue v2 (7.1, updated by uploading 1.2.3): the theme upgrade
     * and the Telegram change wait for "Finish update" (AdminFinishUpdateTest::makeOldInstall(), plus the Telegram
     * migration), its Telegram channel is the old group, and its Support links are $supportLinks (null: never stored).
     */
    private function makeInstallFromBefore120(?string $supportLinks): void
    {
        Schema::dropIfExists('admin_setting_revisions');

        if (Schema::hasColumn('users', 'admin_preferences')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('admin_preferences'));
        }

        DB::table('migrations')->whereIn('migration', [
            '2026_10_20_000001_create_admin_setting_revisions_table',
            '2026_10_20_000002_migrate_appearance_to_theme_catalog_v2',
            '2026_10_20_000003_add_admin_preferences_to_users_table',
            self::MIGRATION,
        ])->delete();
        DB::table('settings')->where('group', 'appearance')->whereIn('key', ['appearance_schema_version', 'footer_support_links'])->delete();
        Setting::query()->updateOrCreate(['key' => 'theme_preset'], ['value' => 'cyberpunk_neon', 'group' => 'appearance']);
        $this->site122(array_filter([
            'social_telegram' => [self::OLD, 'sociallinks'],
            'footer_support_links' => $supportLinks === null ? null : [$supportLinks, 'appearance'],
        ]));

        app()->forgetInstance(SettingsRevision::class.'.table');
        Cache::flush();
        PendingMigrations::forget();
    }

    /** The footer a visitor gets now: the group and personal support, never the old group. */
    private function assertFooterOffersTheGroupAndSupport(string $message): void
    {
        Setting::clearCache();
        $this->applySettingsLikeAWebRequest();
        AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));

        $html = view('layouts.default')->render();
        $this->assertMatchesRegularExpression('#<a href="https://t\.me/\+jj6McO-iYfA0MTM1"\s+target="_blank" rel="noopener"\s*>Telegram group</a>#', $html, $message);
        $this->assertMatchesRegularExpression('#<a href="https://t\.me/avdbapi_support"\s+target="_blank" rel="noopener"\s*>Telegram support</a>#', $html, $message);
        $this->assertNoOldGroup($html, $message);
    }

    /** Appearance > Header, menus & footer: the "Support" links field shows $expected (what the admin edits and saves). */
    private function assertSupportLinksFieldShows(string $expected, User $admin, string $message): void
    {
        $html = (string) $this->actingAs($admin)->get(route('admin.appearance.page', ['page' => 'menus']))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<textarea[^>]*name="[^"]*footer_support_links[^"]*"[^>]*>(.*?)</textarea>#s', $html, $field), $message.': the Support links field');
        $this->assertSame($expected, html_entity_decode($field[1], ENT_QUOTES | ENT_HTML5), $message);
        $this->assertNoOldGroup($html, $message);
    }

    /** What AppServiceProvider::applyDbSettings() does at the start of every web request (it skips the console). */
    private function applySettingsLikeAWebRequest(): void
    {
        $provider = $this->app->getProvider(AppServiceProvider::class);
        $console = new ReflectionProperty($this->app, 'isRunningInConsole');
        $before = $console->getValue($this->app);
        $console->setValue($this->app, false);

        try {
            (new ReflectionMethod($provider, 'applyDbSettings'))->invoke($provider);
        } finally {
            $console->setValue($this->app, $before);
        }
    }
}
