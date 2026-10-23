<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionDiscoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_discover_excludes_current_user_and_existing_subscriptions(): void
    {
        $viewer = User::factory()->create(['username' => 'Viewer']);
        $subscribed = User::factory()->create([
            'username' => 'Already Followed',
            'show_subscribers' => true,
        ]);
        $available = User::factory()->create([
            'username' => 'Fresh Creator',
            'show_subscribers' => true,
        ]);

        $this->createPublicVideo($subscribed);
        $this->createPublicVideo($available);

        DB::table('subscriptions')->insert([
            'subscriber_id' => $viewer->id,
            'user_id' => $subscribed->id,
            'subscribe_at' => now(),
            'read_at' => now(),
        ]);

        $response = $this->actingAs($viewer)
            ->get(route('subscription.discover'))
            ->assertOk();

        $usernames = $response->viewData('users')->pluck('username')->all();

        $this->assertContains('Fresh Creator', $usernames);
        $this->assertNotContains('Already Followed', $usernames);
        $this->assertNotContains('Viewer', $usernames);
    }

    public function test_discovery_counts_follow_profile_locale_and_visibility(): void
    {
        $creator = User::factory()->create(['show_subscribers' => true]);
        $this->createPublicVideo($creator);
        Video::factory()->for($creator)->withStatus(VideoStatus::PUBLIC)->create(['language' => 'vi']);
        Video::factory()->for($creator)->withStatus(VideoStatus::PRIVATE)->create();

        $this->get(route('subscription.discover'))->assertOk()
            ->assertViewHas('users', fn ($users) => $users->firstWhere('id', $creator->id)?->videos_count === 1);

        $this->createPublicVideo($creator);
        $this->get(route('subscription.discover'))->assertOk()
            ->assertViewHas('users', fn ($users) => $users->firstWhere('id', $creator->id)?->videos_count === 2);
    }

    private function createPublicVideo(User $creator): void
    {
        Video::factory()
            ->for($creator)
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'uploaded_at' => now()->subDay(),
                'published_at' => now()->subDay(),
            ]);
    }
}
