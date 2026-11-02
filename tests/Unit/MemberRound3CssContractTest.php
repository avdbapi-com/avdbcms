<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.2.3 round 3: the member CSS rules behind four fixes (what they do on screen was checked in browsers: Chromium and
 * WebKit on phones, in several looks and the longest languages).
 * - R3-011: a clamped title on a touch screen took its 44px target as padding, and a box clips its overflow at its
 *   padding edge, so its hidden lines were painted in the padding, over the next line.
 * - R3-054: a button on a phone broke a word anywhere ("Veröffentliche" + "n" on the draft page in German at 360px).
 * - R2R-03: the channel band's Subscribe was a grid column squeezed below its label at 320-414px.
 * - R1-070 = R2R-10: the channel page's own rules are their own stylesheet (member/_channel-page.scss).
 */
class MemberRound3CssContractTest extends TestCase
{
    public function test_a_clamped_title_takes_its_touch_extension_as_a_border_on_touch_screens(): void
    {
        $kit = $this->compact('resources/sass/member/_kit.scss');
        $coarse = $this->mediaBlocks($kit, '(pointer: coarse)');
        $this->assertStringContainsString(':root .mk-item__title:is(.mk-clamp-1, .mk-clamp-2, .mk-clamp-3) { padding-block: 0; border-block: var(--mk-touch-ext) solid transparent; }', $coarse);
        // the extension itself stays: the padding and the negative margin of every touch-extended link
        $this->assertStringContainsString('margin-block: calc(-1 * var(--mk-touch-ext));', $coarse);
        // CS-RV2: forced colours paint a transparent border in the text's colour (a bar over and under every clamped
        // title): there the extension is padding again and the box clips at its content edge; after the border rule
        $forced = '@media (forced-colors: active) { :root .mk-item__title:is(.mk-clamp-1, .mk-clamp-2, .mk-clamp-3) { padding-block: var(--mk-touch-ext); border-block-width: 0; overflow: clip; overflow-clip-margin: content-box; } }';
        $this->assertStringContainsString($forced, $coarse);
        $this->assertGreaterThan(strpos($coarse, 'border-block: var(--mk-touch-ext) solid transparent;'), strpos($coarse, $forced), 'the forced-colours rule comes after the border rule');
    }

    public function test_a_button_on_a_phone_wraps_between_its_words_never_inside_one(): void
    {
        $shared = $this->compact('resources/sass/member/_shared.scss');
        $phone = $this->mediaBlocks($shared, '(max-width: 575.98px)');
        $this->assertStringContainsString(':root .mk-btn:not(.mk-btn--fixed):not(.mk-subscribe) { white-space: normal; overflow-wrap: break-word; }', $phone);
        $this->assertStringNotContainsString('overflow-wrap: anywhere', $this->ruleOf($shared, ':root .mk-btn:not(.mk-btn--fixed):not(.mk-subscribe)'));
        // ... and it never pushes its panel off the screen
        $this->assertStringContainsString('max-inline-size: 100%;', $this->ruleOf($shared, ':root .mk-btn'));

        // the look picker's swatches: as wide as the longest word of their label
        $account = $this->compact('resources/sass/member/_account.scss');
        $this->assertStringContainsString('min-inline-size: min-content;', $this->ruleOf($account, ':root .mk-swatch'));
        $label = $this->ruleOf($account, ':root .mk-swatch__label');
        $this->assertStringContainsString('overflow-wrap: break-word;', $label);
        $this->assertStringNotContainsString('overflow-wrap: anywhere', $label);
    }

    public function test_subscribe_on_the_channel_band_is_never_narrower_than_its_label_on_phones(): void
    {
        $page = $this->compact('resources/sass/member/_channel-page.scss');
        $phone = $this->mediaBlocks($page, '(max-width: 575.98px)');
        // Subscribe fills its row and is never narrower than its label; a label wider than the whole row wraps between its words
        $this->assertStringContainsString(':root .mk-channel__actions .mk-subscribe { order: 1; flex: 1 0 auto; white-space: normal; overflow-wrap: break-word; }', $phone);
        // CS-RV1: the face it shows keeps .5rem from the button's edges (where the label wraps, the bell and the chevron
        // stayed on the edge line)
        $this->assertStringContainsString(':root .mk-channel__actions .mk-subscribe__face:not([aria-hidden]) { padding-inline: .5rem; }', $phone);
        // Share and More wrap together (the row is filled from its end, its rows from the bottom), the creator's button
        // has the bottom row and cuts its label inside the band
        $this->assertStringContainsString(':root .mk-channel__actions { flex-direction: row-reverse; flex-wrap: wrap-reverse; }', $phone);
        $this->assertStringContainsString(':root .mk-channel__actions > .mk-channel__more { order: -1; }', $phone);
        $this->assertStringContainsString(':root .mk-channel__actions > .mk-channel__cta { order: -2; flex: 1 1 100%; min-inline-size: 0; max-inline-size: none; justify-content: flex-start; }', $phone);
        // the visitor's row is the actions' own wrapping flex row: no grid column squeezes Subscribe any more
        $this->assertStringNotContainsString('grid-template-columns: minmax(0, 1fr) auto auto', $page);
        $all = $page.$this->compact('resources/sass/member/_channel.scss');
        $this->assertStringNotContainsString('.mk-channel__actions > .mk-subscribe { grid-column: 1; }', $all);
        // the owner's row keeps its grid (Customize channel + Share, Manage videos under them)
        $this->assertStringContainsString(':root .mk-channel__actions--owner { display: grid; grid-template-columns: minmax(0, 1fr) auto; }', $phone);
    }

    public function test_the_channel_pages_own_rules_keep_the_member_kits_specificity_floor(): void
    {
        $page = (string) preg_replace('#/\*.*?\*/#s', '', $this->source('resources/sass/member/_channel-page.scss'));
        $selectors = 0;
        foreach ($this->selectorGroups($page) as $group) {
            foreach (explode(',', $group) as $selector) {
                $selector = trim($selector);
                $this->assertMatchesRegularExpression('/^(?::root|html)\b/', $selector, 'every selector starts with :root or html (member design 5.13)');
                $selectors++;
            }
        }
        $this->assertGreaterThan(100, $selectors);
        // the header, the band, the tabs and the featured video moved, the shared library rules stayed
        $this->assertStringContainsString(':root .mk-channel__band {', $page);
        $this->assertStringContainsString(':root .mk-channel__tabs {', $page);
        $channel = $this->source('resources/sass/member/_channel.scss');
        $this->assertStringNotContainsString(':root .mk-channel__band {', $channel);
        $this->assertStringContainsString(':root .mk-lrows > .mk-lrows__ad', $channel);
    }

    /** The selector lists of a stylesheet's rules (inside @media / @supports / @container too; keyframe stops skipped). @return list<string> */
    private function selectorGroups(string $css): array
    {
        $groups = [];
        $depth = 0;
        $skipDepth = null;
        $buffer = '';
        $length = strlen($css);
        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($char === '{') {
                $head = trim($buffer);
                $buffer = '';
                $depth++;
                if ($skipDepth !== null) {
                    continue;
                }
                if (str_starts_with($head, '@keyframes')) {
                    $skipDepth = $depth;
                } elseif ($head !== '' && $head[0] !== '@') {
                    $groups[] = $head;
                }
            } elseif ($char === '}') {
                if ($skipDepth === $depth) {
                    $skipDepth = null;
                }
                $depth--;
                $buffer = '';
            } elseif ($char === ';') {
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }

        return $groups;
    }

    /** The rules of every @media block with this condition, one string. */
    private function mediaBlocks(string $compact, string $condition): string
    {
        $out = '';
        $offset = 0;
        $needle = '@media '.$condition.' {';
        while (($start = strpos($compact, $needle, $offset)) !== false) {
            $depth = 0;
            $length = strlen($compact);
            for ($i = $start + strlen($needle) - 1; $i < $length; $i++) {
                if ($compact[$i] === '{') {
                    $depth++;
                } elseif ($compact[$i] === '}' && --$depth === 0) {
                    break;
                }
            }
            $out .= substr($compact, $start, $i - $start + 1)."\n";
            $offset = $i;
        }
        $this->assertNotSame('', $out, $needle);

        return $out;
    }

    /** The declarations of the first rule with exactly this selector (at the top or in a block). */
    private function ruleOf(string $compact, string $selector): string
    {
        $this->assertSame(1, preg_match('/(?:^|[;{}]) ?'.preg_quote($selector, '/').' \{([^}]*)\}/', $compact, $m), $selector);

        return $m[1];
    }

    /** The stylesheet without comments, every run of white space one space. */
    private function compact(string $relative): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('#/\*.*?\*/#s', '', $this->source($relative))));
    }

    private function source(string $relative): string
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/'.$relative);
        $this->assertNotFalse($text, $relative);

        return str_replace("\r\n", "\n", (string) $text);
    }
}
