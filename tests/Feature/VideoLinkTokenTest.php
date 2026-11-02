<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Helpers\Number;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner question SV-RV-04 (round 3): the link token of a video a member uploads (Number::unique(), called by the upload
 * code) was a shuffle of the digits of the current Unix time, so the unlisted link of a video uploaded at a known moment
 * had only some 10^4-10^5 candidates. It is ten digits from the system's cryptographic generator now, in the same format,
 * and never the link of an existing video.
 */
class VideoLinkTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_token_keeps_the_format_and_is_not_made_of_the_digits_of_the_time(): void
    {
        $tokens = [];
        $multisets = [];

        for ($i = 0; $i < 60; $i++) {
            $token = Number::unique();
            $this->assertMatchesRegularExpression('/^[0-9]{10}$/', $token);
            $this->assertMatchesRegularExpression('/^'.Video::UUID_PATTERN.'$/', $token);
            $tokens[$token] = true;
            $digits = str_split($token);
            sort($digits);
            $multisets[implode('', $digits)] = true;
        }

        // drawn within a second or two: a shuffle of the time's digits gives one or two digit sets, ten random digits
        // almost always sixty
        $this->assertCount(60, $tokens, 'no token twice');
        $this->assertGreaterThan(20, count($multisets), 'not the digits of the time in another order');
    }

    public function test_a_token_that_a_video_has_already_is_never_given(): void
    {
        $owner = User::factory()->create();
        $live = Video::factory()->for($owner)->create(['uuid' => '0000000042']);
        $deleted = Video::factory()->for($owner)->create(['uuid' => '0000000043']);
        $deleted->delete();

        $this->assertTrue(Number::takenAsVideoLink('0000000042'));
        $this->assertTrue(Number::takenAsVideoLink('0000000043'), 'a deleted video keeps its link');
        $this->assertFalse(Number::takenAsVideoLink('0000000044'));
        $this->assertSame($live->id, Video::query()->where('uuid', '0000000042')->value('id'));
    }

    public function test_a_video_with_such_a_token_opens_by_its_link(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $video = Video::factory()->for($owner)->withStatus(VideoStatus::UNLISTED)->create(['published_at' => now()->subDay()]);

        $this->assertMatchesRegularExpression('/^[0-9]{10}$/', (string) $video->uuid, 'the factory names its videos as an upload does');
        $this->get(parse_url($video->route, PHP_URL_PATH))->assertOk();
    }
}
