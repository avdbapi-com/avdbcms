<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\FooterSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The AVDB API Telegram group moved to a private invite link and personal support has its own account (migration
 * 2026_10_26_000000). New sites get the new links; an updated site that still stores the old group link t.me/avdb_api
 * (Telegram channel, "Support" links) gets the new group plus the support account next to it. A link or line the owner
 * chose stays exactly as stored.
 */
class TelegramLinksTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_26_000000_update_telegram_links';

    private const GROUP = 'https://t.me/+jj6McO-iYfA0MTM1';

    private const SUPPORT = 'https://t.me/avdbapi_support';

    private const OLD = 'https://t.me/avdb_api';

    private const OLD_FACTORY_FOOTER = "Contact|/contact\nTelegram Support|https://t.me/avdb_api\nTerms of Service|/terms";

    private const NEW_FACTORY_FOOTER = "Contact|/contact\nTelegram Group|https://t.me/+jj6McO-iYfA0MTM1\nTelegram Support|https://t.me/avdbapi_support\nTerms of Service|/terms";

    public function test_a_new_site_gets_the_new_group_link_and_no_old_link_anywhere(): void
    {
        $this->assertSame(self::GROUP, DB::table('settings')->where('key', 'social_telegram')->value('value'));

        foreach (DB::table('settings')->get(['key', 'value']) as $row) {
            $this->assertStringNotContainsStringIgnoringCase('t.me/avdb_api', (string) $row->value, $row->key);
        }
    }

    public function test_the_factory_support_links_offer_the_group_and_the_support_account(): void
    {
        $this->assertSame(self::NEW_FACTORY_FOOTER, FooterSettings::defaults()['footer_support_links']);
        $this->assertSame(self::NEW_FACTORY_FOOTER, config('admin_settings.settings.footer_support_links.default'));

        $links = FooterSettings::parseLinks(FooterSettings::defaults()['footer_support_links']);
        $this->assertSame(
            [['Contact', '/contact'], ['Telegram Group', self::GROUP], ['Telegram Support', self::SUPPORT], ['Terms of Service', '/terms']],
            array_map(fn (array $link): array => [$link['label'], $link['url']], $links),
        );
    }

    public function test_the_two_labels_are_translated_in_every_language(): void
    {
        $files = glob(lang_path('*.json')) ?: [];
        $this->assertGreaterThanOrEqual(18, count($files));

        foreach ($files as $file) {
            $strings = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            $this->assertNotSame('', trim((string) ($strings['Telegram Group'] ?? '')), basename($file));
            $this->assertNotSame('', trim((string) ($strings['Telegram Support'] ?? '')), basename($file));
        }
    }

    public function test_an_updated_site_with_the_old_telegram_channel_gets_the_new_group(): void
    {
        foreach ([self::OLD, 'http://t.me/avdb_api', 't.me/avdb_api', 'https://telegram.me/avdb_api/', ' https://T.ME/AVDB_API '] as $old) {
            $this->putSetting('social_telegram', $old, 'sociallinks');
            $this->migration()->up();

            $this->assertSame(self::GROUP, Setting::query()->where('key', 'social_telegram')->value('value'), $old);
        }
    }

    public function test_an_own_or_empty_telegram_channel_stays_exactly_as_stored(): void
    {
        foreach (['https://t.me/my_site_channel', 'https://t.me/avdb_api_fans', '', self::GROUP] as $own) {
            $this->putSetting('social_telegram', $own, 'sociallinks', '2026-01-02 03:04:05');
            $this->migration()->up();

            $row = DB::table('settings')->where('key', 'social_telegram')->first();
            $this->assertSame($own, (string) $row->value);
            $this->assertSame('2026-01-02 03:04:05', (string) $row->updated_at, 'an untouched row keeps its timestamp');
        }
    }

    public function test_the_old_factory_support_links_become_the_new_factory_links(): void
    {
        $this->putSetting('footer_support_links', self::OLD_FACTORY_FOOTER, 'appearance');
        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(self::NEW_FACTORY_FOOTER, DB::table('settings')->where('key', 'footer_support_links')->value('value'));
    }

    public function test_own_support_lines_line_breaks_and_labels_stay(): void
    {
        $migration = $this->migration();

        // Windows line breaks and an own label stay; the support line is added once, right after the group.
        $this->assertSame(
            "Help|/help\r\nOur Telegram|" . self::GROUP . "\r\nTelegram Support|" . self::SUPPORT . "\r\nFAQ|/faq",
            $migration::supportLinks("Help|/help\r\nOur Telegram|https://t.me/avdb_api\r\nFAQ|/faq"),
        );
        // The support account is already listed: only the group link changes.
        $this->assertSame(
            "Telegram Group|" . self::GROUP . "\nSupport|https://t.me/AvdbAPI_support",
            $migration::supportLinks("Telegram Support|t.me/avdb_api\nSupport|https://t.me/AvdbAPI_support"),
        );
        // No old link: the exact stored text comes back.
        foreach (["Contact|/contact\nTelegram|https://t.me/my_group", '', "Bots|https://t.me/avdb_api_bot", "Text only\n"] as $own) {
            $this->assertSame($own, $migration::supportLinks($own));
        }
        $this->assertTrue($migration::isOldLink('tg://resolve?domain=avdb_api'));
        $this->assertFalse($migration::isOldLink('https://t.me/avdb_api_bot'));
        $this->assertFalse($migration::isOldLink('https://example.com/t.me/avdb_api'));
    }

    public function test_the_footer_and_the_import_page_show_both_links(): void
    {
        $this->withoutVite();
        $html = view('layouts.default')->render();
        $this->assertStringContainsString('href="' . self::GROUP . '"', $html);
        $this->assertStringContainsString('href="' . self::SUPPORT . '"', $html);
        $this->assertStringNotContainsStringIgnoringCase('t.me/avdb_api', $html);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.avdb-import.index'))
            ->assertOk()
            ->assertSee('href="' . self::GROUP . '"', false)
            ->assertSee('href="' . self::SUPPORT . '"', false)
            ->assertDontSee('t.me/avdb_api', false);
    }

    public function test_no_code_view_or_seed_file_carries_the_old_group_link(): void
    {
        // The frozen copy of the old factory values in the theme catalogue v2 migration is history (it classifies
        // sites that still store them) and stays; the Telegram migration and this test name the old link to find it.
        $allowed = [
            'database/migrations/2026_10_20_000002_migrate_appearance_to_theme_catalog_v2.php',
            'database/migrations/2026_05_05_000000_rebrand_settings_to_avdbclip.php', // down(): removes either link
            'database/migrations/' . self::MIGRATION . '.php',
        ];
        $pattern = '~(?:t|telegram)\.(?:me|dog)(?:/|\\\\/)avdb_api(?![A-Za-z0-9_])~i';
        $found = [];

        foreach (['app', 'bootstrap', 'config', 'css', 'database', 'helpers', 'lang', 'public', 'resources', 'routes'] as $dir) {
            if (! is_dir(base_path($dir))) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                if (! $file->isFile() || in_array($relative, $allowed, true) || $file->getSize() > 5_000_000) {
                    continue;
                }
                if (preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                    $found[] = $relative;
                }
            }
        }

        $this->assertSame([], $found);
    }

    private function putSetting(string $key, string $value, string $group, string $at = '2026-05-05 03:55:31'): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'group' => $group, 'created_at' => $at, 'updated_at' => $at]);
        Setting::clearCache();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/' . self::MIGRATION . '.php');
    }
}
