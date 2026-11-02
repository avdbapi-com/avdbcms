<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The kit cards of the channel and library pages (resources/views/library/card.blade.php, playlist-card.blade.php)
 * follow the admin's card settings like the site's own cards (member design 6.0):
 * "Title lines" (video_card_title_lines), "Cards react to the mouse" (video_card_hover_enabled), "Glow around pointed
 * cards" (video_card_hover_glow_enabled) and "Card size on phones" (mobile_video_card_density). The layout prints them
 * on <html> (data-avd-title-lines, data-avd-card-hover, data-avd-card-hover-glow, data-avd-mobile-video-card-density);
 * the member CSS keys its card rules on them.
 */
final class MemberCardSettingsCssTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $path = dirname(__DIR__, 2) . '/resources/sass/member/_channel.scss';
        self::assertFileExists($path);
        // comments out, one space for every run of white space: the rules as one line each
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
        $this->css = (string) preg_replace('/\s+/', ' ', $css);
    }

    public function test_card_titles_take_the_admins_number_of_lines_and_keep_their_room(): void
    {
        // QA: titles were always two lines, whatever "Title lines" said
        // (the cards' title carries no clamp class since R1-069: the stylesheet clamps .mk-card > .mk-card__title)
        $rule = $this->rule('html[data-avd-title-lines] .mk-card > .mk-card__title');
        self::assertStringContainsString('-webkit-line-clamp: var(--avd-title-lines, 2);', $rule);
        self::assertStringContainsString('min-block-size: calc(1.35em * var(--avd-title-lines, 2));', $rule);
        // ... and the variable is the site's own (layout-theme-a.css sets it for 1, 3 and 4 from the same attribute)
        $theme = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/css/layout-theme-a.css');
        foreach (['1', '3', '4'] as $lines) {
            self::assertMatchesRegularExpression('/html\[data-avd-title-lines="' . $lines . '"\]\s*\{\s*--avd-title-lines:\s*' . $lines . ';/', $theme);
        }
    }

    public function test_card_hover_follows_cards_react_to_the_mouse_and_the_ring_follows_the_glow_switch(): void
    {
        // QA: with "Cards react to the mouse" off a kit card still took the brand colour and a ring
        preg_match_all('/(?:^|[}{;])\s*([^{};@]*\.mk-card[^{};]*:hover[^{};]*)\{([^}]*)\}/', $this->css, $rules, PREG_SET_ORDER);
        self::assertNotEmpty($rules, 'the card hover rules');
        foreach ($rules as [, $selectors, $body]) {
            foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                self::assertStringStartsWith('html:not([data-avd-card-hover="off"])', $selector, 'a kit card hover rule outside the "Cards react to the mouse" switch');
                if (str_contains($body, 'box-shadow')) {
                    self::assertStringContainsString(':not([data-avd-card-hover-glow="off"])', $selector, 'the ring outside the glow switch');
                }
                // the calm rule (4.1): colour and shadow only, nothing that moves the hit box
                self::assertDoesNotMatchRegularExpression('/\b(transform|translate|scale|width|height|margin|padding)\s*:/', $body, $selector);
            }
        }
    }

    public function test_phone_cards_follow_the_admins_density(): void
    {
        // QA: "Card size on phones" changed nothing on the kit cards; compact (the default) is the mockups'
        // card, comfortable is roomier
        self::assertMatchesRegularExpression('/@media \(max-width: 575\.98px\) \{[^@]*html\[data-avd-mobile-video-card-density="comfortable"\]\[data-avd-mobile-video-columns="2"\] \.mk-cards \.mk-card > \.mk-card__title \{ font-size: \.9375rem; \}/', $this->css);
        self::assertMatchesRegularExpression('/html\[data-avd-mobile-video-card-density="comfortable"\]\[data-avd-mobile-video-columns="2"\] \.mk-cards:not\(\.mk-cards--shelf\):not\(\.mk-cards--rail\):not\(\.mk-cards--list\) \{ gap: 1\.25rem 1rem; \}/', $this->css);
        self::assertStringNotContainsString('data-avd-mobile-video-card-density="compact"', $this->css);
    }

    private function rule(string $selector): string
    {
        $at = strpos($this->css, $selector . ' {');
        self::assertNotFalse($at, 'no rule for ' . $selector);
        $open = strpos($this->css, '{', $at);

        return substr($this->css, $open, (int) strpos($this->css, '}', $open) - $open + 1);
    }
}
