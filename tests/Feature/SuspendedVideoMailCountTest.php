<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Support\MemberMail;
use App\Support\LocalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Judge R2A-045: the "Your video was suspended" mail wrote the video's view count with English digit grouping in every
 * language ("2,102,382 vues"). It now uses the mail's language, as the site's pages do (App\Support\LocalNumber).
 */
class SuspendedVideoMailCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_the_view_count_uses_the_digit_grouping_of_the_mails_language(): void
    {
        $member = User::factory()->create(['username' => 'nightdrive_tv']);
        $video = (object) ['title' => 'Golden hour drive along the coast', 'views' => 2102382, 'created_at' => now()->subDays(3)];
        $expected = ['en' => '2,102,382', 'fr' => "2\u{202F}102\u{202F}382", 'de' => '2.102.382', 'es' => '2.102.382', 'pt' => '2.102.382', 'ru' => "2\u{00A0}102\u{00A0}382"];

        foreach (array_keys((array) config('languages')) as $locale) {
            app()->setLocale($locale);
            $count = LocalNumber::format(2102382);
            $line = MemberMail::videoBan($member, $video)['panel']['lines'][0];
            $text = view('mails.text.video.ban', ['notifiable' => $member, 'video' => $video])->render();

            $this->assertStringContainsString($count, $line, $locale);
            $this->assertStringContainsString($count, $text, $locale.': the text part');
            if (isset($expected[$locale])) {
                $this->assertSame($expected[$locale], $count, $locale);
            }
            if ($count !== '2,102,382') {
                $this->assertStringNotContainsString('2,102,382', $line, $locale.': not the English grouping');
            }
        }
    }

    public function test_small_counts_and_the_plural_are_unchanged(): void
    {
        $member = User::factory()->create();

        foreach ([0 => 'No views', 1 => '1 view', 999 => '999 views'] as $views => $words) {
            $line = MemberMail::videoBan($member, (object) ['title' => 'x', 'views' => $views, 'created_at' => null])['panel']['lines'][0];
            $this->assertSame($words, $line);
        }
    }
}
