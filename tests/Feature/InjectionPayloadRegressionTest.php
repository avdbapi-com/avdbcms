<?php

namespace Tests\Feature;

use App\Enums\ReportReason;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Report;
use App\Models\User;
use App\Models\Video;
use App\Notifications\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InjectionPayloadRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_search_sql_payload_does_not_widen_web_or_api_results(): void
    {
        Config::set('scout.driver', 'collection');

        $creator = User::factory()->create();
        $category = $this->createCategory('Injection Search');
        $matching = $this->createPublicVideo('Needle Search Result', $creator, $category);
        $unrelated = $this->createPublicVideo('Unrelated Search Result', $creator, $category);
        $payload = "' OR 1=1 --";

        $this->get(route('search.index', ['q' => $payload]))
            ->assertOk()
            ->assertDontSee($matching->title)
            ->assertDontSee($unrelated->title);

        $this->getJson('/api/search?q=' . rawurlencode($payload))
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('items', []);
    }

    public function test_admin_user_search_sql_payload_does_not_widen_results(): void
    {
        $admin = User::factory()->admin()->create([
            'username' => 'admin-filter-owner',
            'email' => 'admin-filter-owner@example.test',
        ]);
        $target = User::factory()->create([
            'username' => 'filter-target-user',
            'email' => 'filter-target@example.test',
        ]);
        $unrelated = User::factory()->create([
            'username' => 'filter-unrelated-user',
            'email' => 'filter-unrelated@example.test',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['search' => "' OR 1=1 --"]))
            ->assertOk()
            ->assertDontSee($target->email)
            ->assertDontSee($unrelated->email);
    }

    public function test_report_type_injection_payload_is_rejected_without_creating_report(): void
    {
        $reporter = User::factory()->create();
        $creator = User::factory()->create();
        $video = $this->createPublicVideo('Report Injection Target', $creator, $this->createCategory('Reports'));

        $this->actingAs($reporter)
            ->postJson(route('report'), [
                'reason' => ReportReason::MISINFORMATION->value,
                'type' => Video::class . '; DROP TABLE videos; --',
                'id' => $video->id,
                'comment' => 'Attempting to tamper with report type resolution.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);

        $this->assertSame(0, Report::query()->count());
        $this->assertDatabaseHas('videos', ['id' => $video->id]);
    }

    public function test_contact_email_header_injection_payload_is_rejected(): void
    {
        Notification::fake();

        $this->postJson(route('contact.contact'), [
            'name' => 'Header Probe',
            'email' => "attacker@example.test\r\nBcc: victim@example.test",
            'message' => str_repeat('Safe message body. ', 10),
            'website' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        Notification::assertNothingSent();
    }

    private function createCategory(string $title): Category
    {
        return Category::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'icon' => 'play',
            'in_menu' => true,
            'position' => 1,
        ]);
    }

    private function createPublicVideo(string $title, User $creator, Category $category): Video
    {
        return Video::factory()
            ->for($creator, 'user')
            ->for($category)
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => $title,
                'slug' => str($title)->slug()->toString(),
                'published_at' => now()->subMinutes(5),
                'uploaded_at' => now()->subMinutes(5),
                'language' => null,
            ]);
    }
}
