<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The site's one Subscribe button (resources/js/components/Actions/Subscribe.tsx; member design 5.4, 6.10,
 * QA channel-17, watch-35, watch-17). Read from its source, as the Preact island has no PHP rendering:
 * - the Unsubscribe question ends with the kit's danger button, the one filled button of a confirmation, with its icon
 *   and word (it was the look's primary; the mockup sheets-gallery draws mk-btn--danger);
 * - after a change the subscriber count follows on the member pages (data-subscriber-count + data-count-on|off) and on
 *   the watch page (data-avd-subscriber-count + data-avd-count-on|off), where the layout script follows only a button's
 *   aria-pressed, which the bell menu's "Subscribed" does not have.
 */
final class MemberSubscribeButtonSourceTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $path = dirname(__DIR__, 2) . '/resources/js/components/Actions/Subscribe.tsx';
        self::assertFileExists($path);
        $this->source = str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    public function test_the_unsubscribe_question_ends_with_the_kit_danger_button(): void
    {
        self::assertSame(1, preg_match('#<div className="mk-sheet__foot">(?<foot>.*?)</div>\s*</Modal>#s', $this->source, $m), 'the foot of the Unsubscribe dialog');
        $foot = $m['foot'];

        // Cancel first, then the final action (a start tag holds "=>", so its end is the end of its line)
        self::assertSame(2, preg_match_all('#<button\b#', $foot), 'Cancel and Unsubscribe');
        self::assertMatchesRegularExpression('#<button type="button" className="mk-btn mk-btn--secondary"[^\n]*>\{t\(\'Cancel\'\)\}</button>#', $foot);
        self::assertSame(1, preg_match('#<button type="button" className="(?<class>[^"]+)" onClick=\{\(\) => mutate\(\'off\'\)\}[^\n]*\n(?<inner>.*?)</button>#s', $foot, $final), 'the final Unsubscribe button');
        self::assertSame('mk-btn mk-btn--danger', $final['class']);
        self::assertStringContainsString('fa-user-minus', $final['inner'], 'a danger button carries an icon and a word');
        self::assertStringContainsString("{t('Unsubscribe')}", $final['inner']);
        self::assertStringNotContainsString('mk-btn--primary', $foot, 'no look primary inside the confirmation');
    }

    public function test_the_subscriber_count_follows_on_member_pages_and_on_the_watch_page(): void
    {
        self::assertSame(1, preg_match('#const showCount = .*?\n};#s', $this->source, $m), 'showCount');
        $showCount = $m[0];
        self::assertStringContainsString('[data-subscriber-count="${id}"]', $showCount);
        self::assertStringContainsString('[data-avd-subscriber-count="${id}"]', $showCount);
        self::assertStringContainsString("'countOn' : 'countOff'", $showCount);
        self::assertStringContainsString("'avdCountOn' : 'avdCountOff'", $showCount);
        // it runs after every change the server confirmed
        self::assertMatchesRegularExpression('#onSuccess: \(answer: SubscribeAnswer, state: \'on\' \| \'off\'\) => \{[^}]*showCount\(user, now\);#s', $this->source);
    }
}
