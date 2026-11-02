<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Security plan S30: Settings > Spam says "Comments and messages that contain one of these words or come from one of
 * these email addresses are treated as spam", but the lists were only applied to the contact form. Now a new or
 * edited comment with a blocked word, or by a member whose address contains a blocked line, is refused with "Spam
 * detected" (422 on content). Normal comments, replies and comments with links work as before, also without the lists.
 */
class CommentSpamTest extends TestCase
{
    use RefreshDatabase;

    private Video $video;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->video = Video::factory()->for(User::factory()->create(), 'user')->withStatus(VideoStatus::PUBLIC)
            ->create(['allow_comments' => true, 'uploaded_at' => now()->subHour(), 'published_at' => now()->subHour()]);
    }

    public function test_a_comment_with_a_blocked_word_is_refused_in_any_case(): void
    {
        Storage::disk('local')->put('spams/words.txt', "casinobonus\r\nfree money\n");
        $member = User::factory()->create();

        $this->actingAs($member)->postJson(route('comments.store', $this->video->uuid), ['content' => 'Best CasinoBonus here'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content' => __('Spam detected')]);
        $this->assertSame(0, Comment::query()->count());

        // Editing an own comment to it: refused, the old text stays.
        $comment = $this->actingAs($member)->postJson(route('comments.store', $this->video->uuid), ['content' => 'A normal comment.'])
            ->assertCreated()->json('id');
        $this->actingAs($member)->putJson(route('comments.update', [$this->video->uuid, $comment]), ['content' => 'Get FREE MONEY now'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content' => __('Spam detected')]);
        $this->assertSame('A normal comment.', Comment::query()->findOrFail($comment)->content);
    }

    public function test_a_member_with_a_blocked_address_cannot_comment(): void
    {
        Storage::disk('local')->put('spams/emails.txt', "\n@spam-domain.example\n");
        $blocked = User::factory()->create(['email' => 'someone@SPAM-domain.example']);

        $this->actingAs($blocked)->postJson(route('comments.store', $this->video->uuid), ['content' => 'Hello there'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content' => __('Spam detected')]);
        $this->assertSame(0, Comment::query()->count());

        $this->actingAs(User::factory()->create(['email' => 'fine@example.test']))
            ->postJson(route('comments.store', $this->video->uuid), ['content' => 'Hello there'])
            ->assertCreated();
    }

    public function test_normal_comments_replies_and_links_work_as_before(): void
    {
        Storage::disk('local')->put('spams/words.txt', "casinobonus\n");
        Storage::disk('local')->put('spams/emails.txt', "@spam-domain.example\n");
        $member = User::factory()->create();

        $parent = $this->actingAs($member)->postJson(route('comments.store', $this->video->uuid), ['content' => 'Great video, see https://example.org/more'])
            ->assertCreated()->json('id');
        $this->actingAs($member)->postJson(route('comments.store', $this->video->uuid), ['content' => 'And a reply.', 'parent_id' => $parent])
            ->assertCreated();
        $this->actingAs($member)->putJson(route('comments.update', [$this->video->uuid, $parent]), ['content' => 'Edited, still fine.'])
            ->assertOk();

        $this->assertSame(2, Comment::query()->count());
    }

    public function test_without_the_list_files_every_comment_is_accepted(): void
    {
        $this->assertFalse(Storage::disk('local')->exists('spams/words.txt'));

        $this->actingAs(User::factory()->create())->postJson(route('comments.store', $this->video->uuid), ['content' => 'Hello without lists'])
            ->assertCreated();
    }
}
