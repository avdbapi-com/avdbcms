<?php

namespace Tests\Feature\Member;

use App\Models\User;
use App\Support\ChannelCustomizationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Account settings > Channel follows the site's switches (member design 6.0 "Account: Channel", 6.19):
 * channel customization off hides the whole "Channel look" and the tagline and button fields, and a save cannot change
 * the stored choices; a group whose options the admin all switched off is not shown (nor sent when the page asks for
 * it); a single switched-off option is not offered and a save keeps the admin's default for it; the subscriber-count
 * switch is disabled with a note while the site hides counts.
 */
class ChannelLookSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'app.locale' => 'en', 'site.channel_customization_enabled' => true]);
        $this->withoutVite();
    }

    public function test_with_channel_customization_off_the_channel_look_is_hidden_and_a_save_keeps_the_stored_choices(): void
    {
        config(['site.channel_customization_enabled' => false]);
        $member = User::factory()->create(['description' => 'Old description']);
        $member->forceFill(['channel_customization' => ['tagline' => 'Kept tagline', 'motion' => 'pulse']])->save();

        $html = (string) $this->actingAs($member)->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();
        $this->assertStringContainsString('id="hChannelPage"', $html, 'the Channel page panel stays');
        $this->assertStringContainsString('id="description"', $html);
        $this->assertStringNotContainsString('id="hChannelLook"', $html);
        $this->assertStringNotContainsString('class="mk-group-block', $html);
        $this->assertStringNotContainsString('id="tagline"', $html);
        $this->assertStringNotContainsString('id="cta_label"', $html);

        $this->actingAs($member)->withHeaders(self::SCRIPT)
            ->get(route('user.edit', ['section' => 'channel', 'group' => 'colours']))
            ->assertNotFound();
        $this->flushHeaders();

        $this->actingAs($member)->from(route('user.edit', ['section' => 'channel']))
            ->put(route('user.update'), [
                'section' => 'channel',
                'description' => 'New description',
                'channel_customization' => ['motion' => 'none', 'tagline' => 'Changed'],
            ])
            ->assertSessionHasNoErrors();

        $fresh = $member->fresh();
        $this->assertSame('New description', $fresh->description);
        $this->assertSame('pulse', $fresh->channel_customization['motion'] ?? null);
        $this->assertSame('Kept tagline', $fresh->channel_customization['tagline'] ?? null);
    }

    /**
     * Each "Channel look" group with the admin switches of all its options.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function lookGroups(): array
    {
        return [
            'Colours' => ['colours', 'Colours', ['theme', 'accent', 'surface']],
            'Header' => ['header', 'Header', ['layout', 'banner', 'shape', 'avatar', 'pattern', 'stats']],
            'Motion' => ['motion', 'Motion', ['motion']],
            'Sizes' => ['sizes', 'Sizes', ['video_layout', 'featured_layout', 'featured_sizing', 'latest_layout', 'latest_sizing', 'playlists_layout', 'playlists_sizing', 'layout']],
        ];
    }

    /**
     * @param  list<string>  $gates
     */
    #[DataProvider('lookGroups')]
    public function test_a_group_whose_options_are_all_switched_off_is_not_shown(string $group, string $title, array $gates): void
    {
        $member = User::factory()->create();

        // shown while its options are on
        $before = (string) $this->actingAs($member)->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();
        $this->assertStringContainsString('<summary><span>'.$title.'</span>', $before);

        foreach ($gates as $gate) {
            config(['site.channel_customization_'.$gate.'_enabled' => false]);
        }

        $html = (string) $this->actingAs($member)->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();
        $this->assertStringNotContainsString('<summary><span>'.$title.'</span>', $html);
        $this->assertStringNotContainsString('data-group="'.$group.'"', $html);
        $this->assertStringContainsString('<summary><span>Sections</span>', $html, 'the other groups stay');

        $this->actingAs($member)->withHeaders(self::SCRIPT)
            ->get(route('user.edit', ['section' => 'channel', 'group' => $group]))
            ->assertNotFound();
    }

    public function test_a_switched_off_option_is_not_offered_and_a_save_keeps_the_admins_default(): void
    {
        $default = ChannelCustomizationSettings::userDefaults()['pattern'];
        $other = collect(array_keys(ChannelCustomizationSettings::options()['pattern']))->first(fn ($value) => $value !== $default);
        $this->assertIsString($other);

        $member = User::factory()->create();
        config(['site.channel_customization_pattern_enabled' => false]);

        $header = (string) $this->actingAs($member)->withHeaders(self::SCRIPT)
            ->get(route('user.edit', ['section' => 'channel', 'group' => 'header']))
            ->assertOk()
            ->assertHeader('X-Mk-Fragment', 'channel-group')
            ->getContent();
        $this->assertStringNotContainsString('id="cc-pattern"', $header);
        $this->assertStringContainsString('id="cc-layout"', $header, 'the other options of the group stay');
        $this->flushHeaders();

        $this->actingAs($member)->from(route('user.edit', ['section' => 'channel']))
            ->put(route('user.update'), ['section' => 'channel', 'channel_customization' => ['pattern' => $other, 'motion' => 'none']])
            ->assertSessionHasNoErrors();

        $stored = $member->fresh()->channel_customization;
        $this->assertSame($default, $stored['pattern'] ?? null, 'a hidden option keeps the admin default');
        $this->assertSame('none', $stored['motion'] ?? null, 'the shown options are saved');
    }

    public function test_the_subscriber_count_switch_waits_while_the_site_hides_counts(): void
    {
        $member = User::factory()->create();
        $member->forceFill(['show_subscribers' => true])->save();

        $shown = (string) $this->actingAs($member)->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();
        $this->assertStringContainsString('<input type="hidden" name="show_subscribers" value="0">', $shown);
        $this->assertDoesNotMatchRegularExpression('/name="show_subscribers" value="1"[^>]*disabled/', $shown);

        config(['site.show_subscriber_count' => false]);
        $hidden = (string) $this->actingAs($member)->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="show_subscribers" value="1"[^>]*disabled/', $hidden);
        $this->assertStringNotContainsString('<input type="hidden" name="show_subscribers" value="0">', $hidden, 'a save cannot switch it off meanwhile');
        $this->assertStringContainsString(e('This site hides subscriber counts on every channel, so this choice waits until it shows them again.'), $hidden);

        // a save of the section while counts are hidden keeps the member's choice
        $this->actingAs($member)->from(route('user.edit', ['section' => 'channel']))
            ->put(route('user.update'), ['section' => 'channel', 'description' => 'Hello'])
            ->assertSessionHasNoErrors();
        $this->assertTrue((bool) $member->fresh()->show_subscribers);
    }
}
