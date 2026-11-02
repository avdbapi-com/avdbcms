<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Round 1 of 1.2.3, account pages: the fixes that live in scripts and styles, read from their sources (the browser
 * checks of the lane measured them).
 */
class AccountPagesSourceContractTest extends TestCase
{
    private function source(string $path): string
    {
        $file = dirname(__DIR__, 2).'/'.$path;
        $this->assertFileExists($file);

        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }

    /**
     * R1-065: the searchable lists never build TomSelect's table of 65,536 character variants (diacritics: true), the
     * 0.3-1.8 s task of the first search on a phone: both kinds of list fold their labels and the typed words instead.
     */
    public function test_the_searchable_lists_search_without_the_character_table(): void
    {
        $app = $this->source('resources/js/app.ts');

        $this->assertMatchesRegularExpression('/const tomSelectSearch = \{\s*diacritics: false,\s*highlight: false,\s*onInitialize: function \(this: InstanceType<TomSelectConstructor>\) \{\s*this\.hook\(\'after\', \'refreshOptions\', \(\) => markFoldedWords\(this\.dropdown_content, this\.inputValue\(\)\)\);\s*\},\s*score: function \(query: string\)/', $app);
        $this->assertStringContainsString(".normalize('NFKD')", $app);
        $this->assertSame(2, substr_count($app, "new TomSelect(element as TomInput, {\n        ...tomSelectSearch,"), 'the single and the multiple list');
        $this->assertDoesNotMatchRegularExpression('/^\s*diacritics:\s*true\s*,/m', $app, 'no list asks for the table');
    }

    /**
     * Review of R1-065: the bold marks of the typed letters in the results come from the folded texts too (TomSelect's
     * own marks needed the table: without it "etats" left "États" plain); they are made after every refresh of the
     * list, the earlier ones taken off first, and a decomposed accent stays with its letter.
     */
    public function test_the_searchable_lists_mark_the_typed_letters_from_the_folded_texts(): void
    {
        $app = $this->source('resources/js/app.ts');
        $marks = $this->between($app, 'const markFoldedWords = ', 'const tomSelectSearch = ');

        $this->assertStringContainsString("content.querySelectorAll('span.highlight').forEach(", $marks, 'the marks of the last refresh go first');
        $this->assertStringContainsString("content.querySelectorAll('.option').forEach(", $marks, 'only the results are marked');
        $this->assertStringContainsString('const folded = foldWithPlaces(text.data);', $marks);
        $this->assertStringContainsString("/[\\u0300-\\u036f]/.test(text.data.charAt(end))", $marks, 'a decomposed accent stays with its letter');
        $this->assertStringContainsString("mark.className = 'highlight';", $marks);
        $this->assertStringContainsString('if (foldedTexts.size >= 2000) {', $app, 'the folded texts kept are bounded');
    }

    /**
     * Review of R1-029: the side menu's and the footer's light/dark switches take a "theme-changed" event without a
     * detail (the layout's early script, when the page follows the device) and show the page's theme: they threw on the
     * missing detail and kept showing the old one.
     */
    public function test_the_light_dark_switches_follow_a_change_told_without_a_detail(): void
    {
        $switch = $this->source('resources/js/components/Switchs/ThemeSwitcher.tsx');

        $this->assertStringNotContainsString('if (detail.id !== id) {', $switch, 'RC1 read the id of a missing detail');
        $this->assertStringContainsString("const next = detail?.theme ?? document.documentElement.getAttribute('data-bs-theme');", $switch);
        $this->assertStringContainsString('if (detail && detail.id === id) {', $switch, 'its own change is still left alone');
        // the layout still tells such a change without a detail: the case the switch takes
        $this->assertStringContainsString("window.dispatchEvent(new CustomEvent('theme-changed'));", $this->source('resources/views/components/layout.blade.php'));
    }

    /**
     * R1-029: the account panel and Account settings tell each other the choice (light, dark or device) with the
     * theme-changed event, and each one takes the other's choice.
     */
    public function test_the_two_theme_switches_tell_each_other_the_choice(): void
    {
        $panel = $this->source('resources/views/layouts/menus/account.blade.php');
        $this->assertStringContainsString("detail: { theme: theme, mode: value, id: 'account-theme-seg' }", $panel);
        $this->assertStringContainsString("if (d.mode === 'light' || d.mode === 'dark' || d.mode === 'device') { pref = d.mode; }", $panel);

        $profile = $this->source('resources/views/users/profile/sections/profile.blade.php');
        $this->assertStringContainsString('@theme-changed.window="sync($event.detail)"', $profile);
        // the component (avdAccountTheme) is in the cached studio script since 1.2.3 round 3 (R3-086)
        $script = $this->source('resources/js/member/studio-pages.js');
        $this->assertStringContainsString("detail: {theme, mode, id: 'account-settings'}", $script);
        $this->assertMatchesRegularExpression("/sync\\(detail\\) \\{.*?this\\.mode = detail\\.mode;/s", $script);
    }

    /**
     * R1-118 and R1-120 (member CSS): no sparkle stars behind the text of member pages in the Shorts Feed look, and the
     * crop sheet's buttons keep their words on one line on phones. Round 2 (R2A-039): the same rule holds the buttons of
     * the other Account settings sheets ("Delete my account", "Sign out other devices").
     */
    public function test_member_pages_draw_no_stars_and_the_crop_buttons_do_not_wrap(): void
    {
        $account = $this->source('resources/sass/member/_account.scss');

        $this->assertMatchesRegularExpression('/html\[data-avd-member\]\[data-avd-backdrop="sparkles"\]\[data-bs-theme\] \{\s*--avd-page-backdrop: radial-gradient/', $account);
        $this->assertStringNotContainsString('url(', $this->between($account, 'html[data-avd-member][data-avd-backdrop="sparkles"][data-bs-theme] {', '}'));
        $this->assertMatchesRegularExpression('/@media \(max-width: 575\.98px\) \{\s*:root \.mk-crop-dialog \.mk-sheet__foot > \.mk-btn,\s*:root \.avd-profile-settings \.mk-dialog \.mk-sheet__foot > \.mk-btn \{\s*flex: 1 1 auto;\s*white-space: nowrap;/', $account);
    }

    private function between(string $text, string $start, string $end): string
    {
        $from = strpos($text, $start);
        $this->assertNotFalse($from, $start);
        $to = strpos($text, $end, $from + strlen($start));

        return substr($text, $from, $to - $from);
    }
}
