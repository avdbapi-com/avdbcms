<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use App\Policies\VideoPolicy;
use App\Support\NumberFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner question OQ-7 (1.2.3 round 3, applied only with the owner's approval): the upload window's refusal answers of
 * VideoPolicy::upload() ("Sorry ! You are limited to upload maximum 10 videos with free plan. ...", the space limit,
 * "Uploads are currently disabled by the administrator.") were English in every language. They are plain sentences in
 * the member's language now; the checks, their order and the 403 status are unchanged, and nothing of the upload
 * window or the upload flow changes (the owner's decision of 25 Oct 2026 on the upload panel's texts stands).
 */
class UploadRefusalsInTheMembersLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploads_switched_off_answers_in_the_members_language(): void
    {
        config(['site.allow_uploads' => false]);
        $member = User::factory()->create();

        foreach (['en' => 'Uploads are switched off on this site.', 'fr' => null, 'de' => null, 'ar' => null] as $locale => $english) {
            app()->setLocale($locale);
            $answer = app(VideoPolicy::class)->upload($member);

            $this->assertTrue($answer->denied());
            $this->assertSame(403, $answer->status());
            $this->assertSame(__('Uploads are switched off on this site.'), $answer->message());
            if ($english === null) {
                $this->assertNotSame('Uploads are switched off on this site.', $answer->message(), "$locale: translated");
            }
        }
        app()->setLocale('en');
    }

    public function test_the_free_plans_video_count_answers_with_the_languages_forms(): void
    {
        config(['site.allow_uploads' => true, 'plans.free.max_uploads' => 2]);
        $member = User::factory()->create();
        Video::factory()->count(2)->for($member, 'user')->create(['size' => 1000]);

        app()->setLocale('en');
        $answer = app(VideoPolicy::class)->upload($member->fresh());
        $this->assertSame(403, $answer->status());
        $this->assertSame('The free plan allows 2 videos. Upgrade to Premium to upload more.', $answer->message());

        config(['plans.free.max_uploads' => 1]);
        $this->assertSame('The free plan allows 1 video. Upgrade to Premium to upload more.', app(VideoPolicy::class)->upload($member->fresh())->message());

        foreach (['fr', 'de', 'ru', 'ar', 'zh'] as $locale) {
            app()->setLocale($locale);
            $message = (string) app(VideoPolicy::class)->upload($member->fresh())->message();
            $this->assertStringNotContainsString('free plan', $message, "$locale: translated");
            $this->assertStringNotContainsString('Sorry', $message);
        }
        app()->setLocale('en');
    }

    public function test_the_free_plans_space_answers_with_sizes_in_the_members_language(): void
    {
        config(['site.allow_uploads' => true, 'plans.free.max_uploads' => 100, 'plans.free.max_videos_storage' => 1073741824]);
        $member = User::factory()->create();
        Video::factory()->for($member, 'user')->create(['size' => 805306368]);
        $this->app['request']->query->set('size', 536870912);

        foreach (['en', 'fr', 'ar'] as $locale) {
            app()->setLocale($locale);
            $answer = app(VideoPolicy::class)->upload($member->fresh());

            $this->assertSame(403, $answer->status());
            $this->assertSame(__('The free plan has :size of space for videos and :free of it is left. Upgrade to Premium for more space.', [
                'size' => NumberFormat::localFileSize(1073741824),
                'free' => NumberFormat::localFileSize(268435456),
            ]), $answer->message());
        }
        app()->setLocale('en');
        $this->assertSame('The free plan has 1 GB of space for videos and 256 MB of it is left. Upgrade to Premium for more space.', app(VideoPolicy::class)->upload($member->fresh())->message());
    }
}
