<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ChannelCustomizationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R2A-017: a save of the creator's Channel form (Account settings > Channel) stores what the creator chose, not the
 * site's defaults the form showed. The form sends every field of its open groups with the value it shows (and, before
 * the fix, a hidden "accent chosen" mark), so a creator who only changed the tagline stored the accent, layout, band ...
 * of that moment as their own: when the admin later changed a default in Appearance > Channels ("Used on channels that
 * have not chosen their own"), that channel kept the old one while channels never saved followed.
 */
class ChannelDefaultsChoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['site.channel_customization_enabled' => true, 'site.channel_default_accent' => 'look', 'site.channel_default_layout' => 'spotlight', 'site.channel_default_theme' => 'midnight']);
    }

    public function test_a_tagline_save_leaves_the_accent_and_every_other_default_to_the_site(): void
    {
        $creator = User::factory()->create(['channel_customization' => null]);
        $form = $this->formAsShown($creator);
        $this->assertArrayNotHasKey(ChannelCustomizationSettings::ACCENT_CHOSEN, $form, 'the form sends no mark of a pick');

        $this->save($creator, array_merge($form, ['tagline' => 'Only the tagline changed']));

        $stored = $creator->refresh()->channel_customization;
        $this->assertSame('Only the tagline changed', $stored['tagline']);
        foreach (['accent', 'layout', 'theme', 'surface', 'motion', 'density', 'featured_columns', ChannelCustomizationSettings::ACCENT_CHOSEN] as $key) {
            $this->assertArrayNotHasKey($key, $stored, $key.' is not the creator\'s choice');
        }

        // the admin changes the defaults: the channel follows, like a channel never saved
        config(['site.channel_default_accent' => 'cyan', 'site.channel_default_layout' => 'poster', 'site.channel_default_theme' => 'ocean']);
        $now = $creator->refresh()->channelCustomization();
        $this->assertSame('cyan', $now['accent']);
        $this->assertSame('poster', $now['layout']);
        $this->assertSame('ocean', $now['theme']);
        $this->assertStringContainsString('data-channel-accent="cyan"', $this->get($creator->route)->assertOk()->getContent());
    }

    public function test_what_the_creator_changes_is_kept_when_the_defaults_change(): void
    {
        $creator = User::factory()->create(['channel_customization' => null]);
        $this->save($creator, array_merge($this->formAsShown($creator), ['accent' => 'violet', 'layout' => 'poster']));

        $stored = $creator->refresh()->channel_customization;
        $this->assertSame('violet', $stored['accent']);
        $this->assertSame('1', $stored[ChannelCustomizationSettings::ACCENT_CHOSEN]);
        $this->assertSame('poster', $stored['layout']);
        $this->assertArrayNotHasKey('theme', $stored);

        config(['site.channel_default_accent' => 'cyan', 'site.channel_default_layout' => 'spotlight', 'site.channel_default_theme' => 'ocean']);
        $now = $creator->refresh()->channelCustomization();
        $this->assertSame('violet', $now['accent']);
        $this->assertSame('poster', $now['layout']);
        $this->assertSame('ocean', $now['theme'], 'the band was never chosen: it follows');

        // a later save of the tagline alone keeps both choices
        $this->save($creator, array_merge($this->formAsShown($creator), ['tagline' => 'Later']));
        $now = $creator->refresh()->channelCustomization();
        $this->assertSame('violet', $now['accent']);
        $this->assertSame('poster', $now['layout']);
        $this->assertSame('1', $creator->channel_customization[ChannelCustomizationSettings::ACCENT_CHOSEN]);
    }

    public function test_the_look_colour_picked_as_a_change_stays_and_a_save_of_another_group_keeps_it(): void
    {
        // a channel that follows the site's default accent cyan picks the look's colour: a choice like any other
        config(['site.channel_default_accent' => 'cyan']);
        $creator = User::factory()->create(['channel_customization' => null]);
        $this->save($creator, array_merge($this->formAsShown($creator), ['accent' => 'look']));
        config(['site.channel_default_accent' => 'violet']);
        $this->assertSame('look', $creator->refresh()->channelCustomization()['accent']);

        // a save that does not send the accent (another group open) keeps it
        $this->save($creator, ['motion' => 'none']);
        $this->assertSame('look', $creator->refresh()->channelCustomization()['accent']);
        $this->assertSame('none', $creator->channelCustomization()['motion']);
    }

    public function test_a_channel_saved_by_1_2_2_keeps_its_stored_values_and_its_old_coral_follows_the_default(): void
    {
        // 1.2.2 stored every field with each save, the default "coral" too (R1-038: that coral is no pick)
        $creator = User::factory()->create(['channel_customization' => array_merge(ChannelCustomizationSettings::userDefaults(), ['accent' => 'coral', 'layout' => 'poster'])]);
        $this->save($creator, array_merge($this->formAsShown($creator), ['tagline' => 'After the update']));

        $stored = $creator->refresh()->channel_customization;
        $this->assertArrayNotHasKey('accent', $stored, 'the old default coral is not made a pick by a save');
        $this->assertSame('poster', $stored['layout'], 'what 1.2.2 stored stays as it was');

        config(['site.channel_default_accent' => 'cyan']);
        $this->assertSame('cyan', $creator->refresh()->channelCustomization()['accent']);
    }

    public function test_an_own_colour_changed_on_the_sites_own_colour_default_is_the_creators_pick(): void
    {
        config(['site.channel_default_accent' => 'custom', 'site.channel_default_custom_accent' => '#18c8d8']);
        $creator = User::factory()->create(['channel_customization' => null]);
        $form = $this->formAsShown($creator);
        $this->assertSame('custom', $form['accent']);

        $this->save($creator, array_merge($form, ['custom_accent' => '#aa3366']));

        $stored = $creator->refresh()->channel_customization;
        $this->assertSame('custom', $stored['accent']);
        $this->assertSame('#aa3366', $stored['custom_accent']);
        config(['site.channel_default_accent' => 'look']);
        $this->assertSame('#aa3366', $creator->refresh()->channelCustomization()['custom_accent']);
        $this->assertSame('custom', $creator->channelCustomization()['accent']);

        // and left as shown, the own colour follows the site's
        $other = User::factory()->create(['channel_customization' => null]);
        config(['site.channel_default_accent' => 'custom']);
        $this->save($other, $this->formAsShown($other));
        $this->assertArrayNotHasKey('custom_accent', $other->refresh()->channel_customization);
        config(['site.channel_default_custom_accent' => '#112233']);
        $this->assertSame('#112233', $other->refresh()->channelCustomization()['custom_accent']);
    }

    public function test_a_mark_sent_by_a_request_is_ignored(): void
    {
        $creator = User::factory()->create(['channel_customization' => null]);
        $this->save($creator, array_merge($this->formAsShown($creator), [ChannelCustomizationSettings::ACCENT_CHOSEN => '1']));

        $this->assertArrayNotHasKey('accent', $creator->refresh()->channel_customization);
        $this->assertArrayNotHasKey(ChannelCustomizationSettings::ACCENT_CHOSEN, $creator->channel_customization);
    }

    /**
     * What the Channel form of Account settings sends for its fields when nothing is changed: every field of its groups
     * with the value it shows (read from the page itself, the five groups loaded as the page loads them).
     *
     * @return array<string, string>
     */
    private function formAsShown(User $creator): array
    {
        $fields = [];

        foreach (['colours', 'header', 'motion', 'sections', 'sizes'] as $group) {
            $html = $this->actingAs($creator)->get(route('user.edit', ['section' => 'channel', 'group' => $group]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
            $doc = new \DOMDocument();
            @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
            foreach ((new \DOMXPath($doc))->query('//input[starts-with(@name, "channel_customization[")] | //select[starts-with(@name, "channel_customization[")]') as $node) {
                /** @var \DOMElement $node */
                $name = substr($node->getAttribute('name'), strlen('channel_customization['), -1);
                $type = strtolower($node->getAttribute('type'));
                if ($node->nodeName === 'select') {
                    foreach ($node->getElementsByTagName('option') as $option) {
                        if ($option->hasAttribute('selected')) {
                            $fields[$name] = $option->getAttribute('value');
                        }
                    }
                } elseif ($type === 'radio') {
                    if ($node->hasAttribute('checked')) {
                        $fields[$name] = $node->getAttribute('value');
                    }
                } elseif ($type === 'checkbox') {
                    if ($node->hasAttribute('checked')) {
                        $fields[$name] = $node->getAttribute('value');
                    }
                } else {
                    $fields[$name] = $node->getAttribute('value');
                }
            }
        }

        $this->assertArrayHasKey('accent', $fields, 'the colours group shows the accent');
        $this->assertArrayHasKey('layout', $fields, 'the header group shows the layout');

        return $fields;
    }

    /** @param array<string, string> $customization */
    private function save(User $creator, array $customization): void
    {
        $this->actingAs($creator)->from(route('user.edit', ['section' => 'channel']))
            ->put(route('user.update'), ['section' => 'channel', 'channel_customization' => $customization])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }
}
