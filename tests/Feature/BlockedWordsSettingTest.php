<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\ContentSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 1.2.3 (lane KS): the site setting import_blocked_words (Content > Import > Blocked words): one card on both import
 * pages, the default list until the owner saves a list of their own, saved like every settings page, never written by
 * the old settings page; the words that are always blocked are neither shown nor editable, and an empty list of the
 * owner never turns them off.
 */
class BlockedWordsSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
        config(['admin.ui' => 'v2']);
    }

    public function test_both_import_pages_show_the_card_with_the_default_list_and_no_word_of_the_hard_list(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([route('admin.avdb-import.index'), route('admin.xvid-import.index')] as $page) {
            $html = (string) $this->actingAs($admin)->get($page)->assertOk()->getContent();

            $this->assertStringContainsString('name="groups[uploads][import_blocked_words]"', $html, $page);
            $this->assertStringContainsString('Blocked words', $html);
            $this->assertStringContainsString('Words that present a child or a school-age minor are always blocked, even when they are not in this list.', $html);
            $this->assertStringContainsString('Used by every AVDBAPI and XVIDAPI import, the scheduled ones too.', $html);
            $this->assertStringContainsString(e(ContentSafety::defaultText()), $html, 'the default list in the text box');

            foreach (['jailbait', 'lolicon', 'shotacon', 'joshi kousei', 'randoseru'] as $hard) {
                $this->assertStringNotContainsStringIgnoringCase($hard, $html, 'the hard list is never shown');
            }
        }
    }

    public function test_the_owner_saves_a_list_of_their_own_and_the_importers_read_it(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertSame(ContentSafety::DEFAULT_WORDS, ContentSafety::siteWords(), 'never saved: the default list');

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['uploads' => ['import_blocked_words' => "rape\nCosplay\n\nvoyeur"]],
                'fields' => ['uploads' => ['import_blocked_words']],
            ])
            ->assertOk()
            ->assertJsonPath('saved', ['uploads' => ['import_blocked_words']]);

        $this->assertSame("rape\nCosplay\n\nvoyeur", Setting::getGroup('uploads')['import_blocked_words'] ?? null);
        $this->assertSame(['rape', 'Cosplay', 'voyeur'], ContentSafety::siteWords());
        $this->assertSame('site', ContentSafety::forImports()->check(['A cosplay party']));
        $this->assertNull(ContentSafety::forImports()->check(['School Uniform']), 'no longer in the owner\'s list');

        // the page shows the saved list
        $this->actingAs($admin)->get(route('admin.avdb-import.index'))->assertOk()->assertSee("rape\nCosplay\n\nvoyeur");
    }

    public function test_an_empty_list_turns_the_default_words_off_but_never_the_hard_list(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['uploads' => ['import_blocked_words' => '']],
                'fields' => ['uploads' => ['import_blocked_words']],
            ])
            ->assertOk();

        $this->assertSame('', Setting::getGroup('uploads')['import_blocked_words'] ?? null);
        $this->assertSame([], ContentSafety::siteWords());
        $this->assertNull(ContentSafety::forImports()->check(['Voyeur']));
        $this->assertSame('hard', ContentSafety::forImports()->check(['Schoolgirl']), 'the hard list is not a setting');
    }

    public function test_the_old_settings_page_never_writes_it_and_a_too_long_list_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set(ContentSafety::SETTING, 'my own word', ContentSafety::SETTING_GROUP);

        // the old settings page posts its whole Uploads tab without this key
        $this->actingAs($admin)->put(route('admin.settings.update.group', 'uploads'), [
            'upload_max_duration' => 1234, 'upload_allowed_formats' => 'mp4,webm', 'default_video_status' => '0',
            'thumbnail_width' => 1280, 'thumbnail_height' => 720, 'upload18_api_url' => 'https://upload18.org/api',
            'upload18_default_cid' => 0, 'upload18_default_fid' => 0, 'upload18_default_mycid' => 0,
            'upload18_chunk_size_mb' => 50, 'upload18_thumbnail_count' => 3, 'max_thumbnail_size' => 2048,
        ])->assertSessionHasNoErrors();

        $this->assertSame('1234', (string) (Setting::getGroup('uploads')['upload_max_duration'] ?? null), 'the old page saved its tab');
        $this->assertSame('my own word', Setting::getGroup('uploads')['import_blocked_words'] ?? null);

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['uploads' => ['import_blocked_words' => str_repeat('word ', 4001)]],
                'fields' => ['uploads' => ['import_blocked_words']],
            ])
            ->assertStatus(422);

        $this->assertSame('my own word', Setting::getGroup('uploads')['import_blocked_words'] ?? null);
    }

    /**
     * Round 2 (the reviewer's KS-R11): the save refuses a list with a word too short (two letters, of or in, would block
     * nearly every title), more than 500 words or a word longer than 100 characters, and says which; nothing is
     * dropped without the owner seeing it. The help text says the limits.
     */
    public function test_the_save_refuses_too_short_too_many_or_too_long_words_and_says_which(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set(ContentSafety::SETTING, 'my own word', ContentSafety::SETTING_GROUP);

        foreach ([
            "rape\nof\nin" => 'of, in',
            implode("\n", array_map(fn (int $i): string => 'word'.$i, range(1, 501))) => '501',
            "rape\n".str_repeat('x', 101) => '100',
        ] as $list => $said) {
            $response = $this->actingAs($admin)->putJson('/admin/settings/save', [
                'groups' => ['uploads' => ['import_blocked_words' => $list]],
                'fields' => ['uploads' => ['import_blocked_words']],
            ])->assertStatus(422);

            $this->assertStringContainsString($said, json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE) ?: '');
        }

        $this->assertSame('my own word', Setting::getGroup('uploads')['import_blocked_words'] ?? null, 'nothing saved');

        // two signs are a word in Chinese, Japanese, Korean or Thai
        $this->actingAs($admin)->putJson('/admin/settings/save', [
            'groups' => ['uploads' => ['import_blocked_words' => "盗撮\n강간\nrape"]],
            'fields' => ['uploads' => ['import_blocked_words']],
        ])->assertOk();
        $this->assertSame(['盗撮', '강간', 'rape'], ContentSafety::siteWords());

        $html = (string) $this->actingAs($admin)->get(route('admin.avdb-import.index'))->assertOk()->getContent();
        $this->assertStringContainsString(e('at least 3 letters (2 in Chinese, Japanese, Korean or Thai), at most 500 of at most 100 characters'), $html);
        $this->assertStringContainsString('Words in Chinese, Japanese or Thai also match inside longer words.', $html);
    }

    /**
     * Round 2 (the reviewer's KS-R06): the old admin ("Use the old admin for now") has the card too, on both import pages,
     * with the same label, help and list, and saves it through the same save (only this field is written); a refused
     * list comes back with the reason under the field and the text kept.
     */
    public function test_the_old_admin_has_the_card_on_both_import_pages_and_saves_through_it(): void
    {
        $admin = User::factory()->admin()->create();
        \App\Support\AdminPreferences::set($admin, 'admin_ui', 'v1');
        Setting::set('max_thumbnail_size', '2048', 'uploads');

        foreach ([route('admin.avdb-import.index'), route('admin.xvid-import.index')] as $page) {
            $html = (string) $this->actingAs($admin)->get($page)->assertOk()->getContent();

            $this->assertStringContainsString('name="groups[uploads][import_blocked_words]"', $html, $page);
            $this->assertStringContainsString('name="fields[uploads][]" value="import_blocked_words"', $html);
            $this->assertStringContainsString('action="'.route('admin.settings.save').'"', $html);
            $this->assertStringContainsString('Words that present a child or a school-age minor are always blocked', $html);
            $this->assertStringContainsString(e(ContentSafety::defaultText()), $html, 'the default list in the text box');
            $this->assertStringNotContainsString('ad-page-settings', $html, 'the old admin\'s own form, not the new admin\'s');

            foreach (['jailbait', 'lolicon', 'shotacon', 'joshi kousei', 'randoseru'] as $hard) {
                $this->assertStringNotContainsStringIgnoringCase($hard, $html, 'the hard list is never shown');
            }
        }

        $this->actingAs($admin)
            ->from(route('admin.xvid-import.index'))
            ->put(route('admin.settings.save'), [
                'groups' => ['uploads' => ['import_blocked_words' => "voyeur\nhidden cam"]],
                'fields' => ['uploads' => ['import_blocked_words']],
            ])
            ->assertRedirect(route('admin.xvid-import.index'))
            ->assertSessionHas('success');

        $this->assertSame("voyeur\nhidden cam", Setting::getGroup('uploads')['import_blocked_words'] ?? null);
        $this->assertSame('2048', (string) (Setting::getGroup('uploads')['max_thumbnail_size'] ?? null), 'no other setting is written');

        $this->actingAs($admin)
            ->from(route('admin.avdb-import.index'))
            ->put(route('admin.settings.save'), [
                'groups' => ['uploads' => ['import_blocked_words' => "voyeur\nof"]],
                'fields' => ['uploads' => ['import_blocked_words']],
            ])
            ->assertRedirect(route('admin.avdb-import.index'))
            ->assertSessionHasErrors('uploads.import_blocked_words');

        $this->assertSame("voyeur\nhidden cam", Setting::getGroup('uploads')['import_blocked_words'] ?? null, 'a refused list is not saved');

        // the page shows the reason under the field and keeps what was typed
        $html = (string) $this->actingAs($admin)->get(route('admin.avdb-import.index'))->assertOk()->getContent();
        $this->assertStringContainsString('id="import_blocked_words_error"', $html);
        $this->assertStringContainsString('These words are too short: of.', $html);
        $this->assertStringContainsString("voyeur\nof</textarea>", $html);
    }
}
