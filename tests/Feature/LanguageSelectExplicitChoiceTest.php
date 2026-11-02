<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round 2a (R2A-003, WCAG 3.2.2 On Input): the full footer's language select became the browser's own select with
 * onchange="window.location.href = this.value" (R1-073). Windows and Linux browsers change a closed select with the arrow
 * and letter keys and send "change" at once, so a keyboard user who tabbed to it to look at the languages was sent to
 * /fr/... by the first ArrowDown (a member's language was saved on the way). The slim footer's select and the account
 * panel's Language (onchange="this.form.submit()") did the same.
 *
 * The three selects carry data-avd-apply and no inline handler; the layout's early script applies an explicit choice
 * only: a pick in the open list (change without a key) or Enter on the select. The browser behaviour itself (Chromium,
 * WebKit, Firefox: arrow, arrow + Enter, arrow + Tab, letter + Escape, a pick, Back) is proven by the lane's browser
 * check.
 */
class LanguageSelectExplicitChoiceTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT_MARK = "s.hasAttribute('data-avd-apply')";

    public function test_the_full_footer_select_applies_an_explicit_choice_only(): void
    {
        $html = $this->get(route('pages.trend'))->assertOk()->getContent();
        $footer = $this->between($html, '<footer', '</footer>');

        $this->assertSame(1, preg_match('#<select class="avd-footer-select" aria-label="[^"]+" data-native-select data-avd-apply>#', $footer), 'the full footer\'s select');
        $this->assertStringNotContainsString('onchange', $footer, 'no inline handler that navigates on any change');
        // still the browser's own select (R1-073): app.ts builds no control for it
        $this->assertStringNotContainsString('ts-wrapper', $footer);
        $this->assertListenersOnce($html);
    }

    public function test_the_slim_footer_select_applies_an_explicit_choice_only(): void
    {
        $creator = User::factory()->create();

        $html = $this->actingAs($creator)->get(route('user.videos.index'))->assertOk()->getContent();
        $footer = $this->between($html, '<footer', '</footer>');

        $this->assertStringContainsString('avd-footer--slim', $footer);
        $this->assertSame(1, preg_match('#<select class="avd-footer-select avd-footer-select--slim" aria-label="[^"]+" data-native-select data-avd-apply>#', $footer), 'the slim footer\'s select');
        $this->assertStringNotContainsString('onchange', $footer);
        $this->assertListenersOnce($html);
    }

    public function test_the_account_panels_language_is_sent_on_an_explicit_choice_only(): void
    {
        $member = User::factory()->create();

        $html = $this->actingAs($member)->get(route('pages.trend'))->assertOk()->getContent();
        $form = $this->between($html, '<form class="mk-account__pref" method="POST"', '</form>');

        $this->assertMatchesRegularExpression('#<select class="form-select" id="account-language" name="locale" data-native-select data-avd-apply>#', $form);
        $this->assertStringNotContainsString('onchange', $form);
        // without a script the form still has its own button
        $this->assertMatchesRegularExpression('#<noscript><button type="submit" class="mk-btn mk-btn--secondary mk-btn--sm">#', $form);
        $this->assertListenersOnce($html);
    }

    /**
     * Second pass (the reviewer's CC-RV-02): a letter key finds a language in the select by the first letter of its
     * option, and the full footer's options began with the owner's flag ("🇩🇪 Deutsch"): "d" never reached Deutsch in
     * Chromium, WebKit or Firefox, while the slim footer and the account panel (no flag) found it. Every Language select
     * starts its options with the language's name now and names each option's language (lang), as the account panel
     * already did. 1.2.3 round 3 (R3-084): the options are the names only in every Language select: the browser drew the
     * full footer's 17 colour flags each time its list opened (90-130 ms more at CPU x4 than the other selects); the
     * owner's flag of the page's language stays in the full footer's box, before the name (a decoration).
     */
    public function test_every_language_select_starts_its_options_with_the_name(): void
    {
        $member = User::factory()->create();

        $public = $this->get(route('pages.trend'))->assertOk()->getContent();
        $full = $this->between($this->between($public, '<footer', '</footer>'), '<select class="avd-footer-select" ', '</select>');
        $studio = $this->actingAs($member)->get(route('user.videos.index'))->assertOk()->getContent();
        $slim = $this->between($this->between($studio, '<footer', '</footer>'), '<select class="avd-footer-select avd-footer-select--slim"', '</select>');
        $page = $this->actingAs($member)->get(route('pages.trend'))->assertOk()->getContent();
        $panel = $this->between($page, '<select class="form-select" id="account-language"', '</select>');

        $languages = config('languages');
        $this->assertGreaterThan(1, count($languages));
        foreach (['full footer' => [$full, false], 'slim footer' => [$slim, false], 'account panel' => [$panel, false]] as $where => [$select, $withFlag]) {
            preg_match_all('#<option\b([^>]*)>(.*?)</option>#s', $select, $options, PREG_SET_ORDER);
            $this->assertCount(count($languages), $options, $where);
            $i = 0;
            foreach ($languages as $code => $info) {
                [, $attributes, $text] = $options[$i++];
                $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $label = (string) ($info['label'] ?? '');
                $this->assertStringStartsWith($label, $text, $where.': '.$code.' starts with its name');
                $flag = trim((string) ($info['emoji'] ?? ''));
                if ($withFlag && $flag !== '') {
                    $this->assertSame($label.' '.$flag, $text, $where.': '.$code.' keeps the owner\'s flag after the name');
                } else {
                    $this->assertSame($label, $text, $where.': '.$code);
                }
                $this->assertStringContainsString(' lang="'.$code.'"', $attributes, $where.': '.$code.' says its language');
            }
        }
        // the flag-first text is gone from the full footer ("🇩🇪 Deutsch": a letter never reached the name)
        $this->assertDoesNotMatchRegularExpression('#<option\b[^>]*>\s*[\x{1F1E6}-\x{1F1FF}]#u', $full);
        // R3-084: no flag in any option of the full footer's list; the page language's flag is the box's decoration
        $this->assertDoesNotMatchRegularExpression('#<option\b[^>]*>[^<]*[\x{1F1E6}-\x{1F1FF}]#u', $full);
        $flag = trim((string) (config('languages.'.app()->getLocale().'.emoji') ?? ''));
        if ($flag !== '') {
            $this->assertStringContainsString('<div class="avd-footer-lang"><span class="avd-footer-lang__flag" aria-hidden="true">'.e($flag).'</span><select class="avd-footer-select" ', (string) preg_replace('/>\s+</', '><', $public));
        }
    }

    /**
     * 1.2.3 round 3 (R3-084, second pass): the box's flag is drawn in the system UI font, as the flags in the list were
     * (a look's own font would download its symbols subset for the regional-indicator letters on every page with the
     * full footer: Swiss Bento's Roboto +20 KB, Glass Lounge's Onest +18 KB; layout-theme-a gives every span the look's
     * font, so the rule needs !important), and it hides while the arrow keys show another language than the page's in
     * the closed select (the language applies on an explicit choice only: "🇬🇧 Français" said two things).
     */
    public function test_the_full_footers_flag_is_drawn_in_the_system_font_and_only_for_the_pages_language(): void
    {
        $css = (string) file_get_contents(base_path('resources/sass/enhancements.scss'));

        $this->assertMatchesRegularExpression('/\.avd-footer-lang__flag \{[^}]*font-family: system-ui, -apple-system, "Segoe UI", sans-serif !important;[^}]*\}/', $css);
        $this->assertStringContainsString('.avd-footer-lang:has(> select option:checked:not([selected])) > .avd-footer-lang__flag {', $css);
        $this->assertMatchesRegularExpression('/\.avd-footer-lang:has\(> select option:checked:not\(\[selected\]\)\) > \.avd-footer-lang__flag \{\s*visibility: hidden;\s*\}/', $css);
        // the select itself (the language names, each in its own script) keeps the system UI font too
        $this->assertMatchesRegularExpression('/html\[data-bs-theme\] \.avd-footer-select,\s*html\[data-bs-theme\] \.avd-footer-select option \{\s*font-family: system-ui, -apple-system, "Segoe UI", sans-serif;\s*\}/', $css);

        // the page's own choice is the option with the selected attribute (the one the box's flag belongs to)
        $footer = (string) file_get_contents(resource_path('views/layouts/menus/footer.blade.php'));
        $this->assertStringContainsString('<option @selected(App::currentLocale() === $lang) value="{{ locale_switch_url($lang) }}" lang="{{ $lang }}">{{ $info[\'label\'] }}</option>', $footer);
    }

    /** The listeners: once per page, in the <head> (before the header, so the selects work from the first paint), ES5. */
    private function assertListenersOnce(string $html): void
    {
        $this->assertSame(1, substr_count($html, self::SCRIPT_MARK), 'the explicit-choice listeners are printed once');
        $at = strpos($html, self::SCRIPT_MARK);
        $this->assertLessThan(strpos($html, '</head>'), $at, 'in the <head>');
        $this->assertLessThan(strpos($html, 'class="navbar avd-header'), $at, 'before the header and its account panel');

        $start = strrpos(substr($html, 0, $at), '(function(w,d){var keyed=null,');
        $this->assertNotFalse($start);
        $end = strpos($html, '})(window,document);', $at);
        $this->assertNotFalse($end);
        $script = substr($html, (int) $start, $end - (int) $start);
        $this->assertLessThan(strpos($html, '</script>', $at), $end, 'one inline script');
        foreach ([
            // listeners on the document, in the capture phase, for the marked selects only
            "on=function(t,f){d.addEventListener(t,function(e){var s=pick(e);if(s)f(s,e)},true)}",
            // a key that changes a closed select only marks it: the change it sends at once is not applied
            "(k.length===1||/^(Arrow|Page|Home|End)/.test(k))){keyed=s;w.setTimeout(function(){keyed=null},0)}",
            "on('change',function(s){if(s!==keyed)apply(s)});",
            // Enter applies the language the select shows
            "if(k==='Enter'){if(s.selectedIndex!==own(s)){e.preventDefault();apply(s)}}",
            // the page's own language comes back: Escape, leaving the select, a press that opens the list, the history,
            // leaving the page (Tab past its last control: no focusout in Firefox)
            "else if(k==='Escape'||k==='Esc')back(s);",
            "on('mousedown',back);on('focusout',back);",
            "w.addEventListener('pageshow',backAll);w.addEventListener('blur',backAll)",
            'if(s.options[i].defaultSelected)return i;',
            // a select in a form sends it (the account panel's POST), another one opens its choice's address
            'if(s.form){if(s.form.requestSubmit)s.form.requestSubmit();else s.form.submit()}else if(s.value)w.location.href=s.value',
        ] as $part) {
            $this->assertStringContainsString($part, $script, $part);
        }
        // ES5 only (the early inline scripts run on every browser the site supports, before any bundle)
        foreach (['=>', 'let ', 'const ', '?.', '??', '`'] as $modern) {
            $this->assertStringNotContainsString($modern, $script, $modern);
        }
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'missing: '.$from);
        $end = strpos($html, $to, (int) $start);

        return substr($html, (int) $start, $end === false ? null : $end - (int) $start + strlen($to));
    }
}
