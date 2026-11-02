<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settings > SEO refuses a video address prefix or pattern it cannot use with words the admin reads in their own
 * language (they were three English sentences in every language: "The video URL pattern contains unsupported tokens:
 * foo."), in the plain words of the fields' own help.
 */
class AdminVideoAddressMessagesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string, 2: string}> field, value, the refusal */
    public static function refusals(): array
    {
        return [
            'a prefix another page uses' => ['video_url_prefix', 'admin', 'Another part of the site already uses this word in its addresses. Choose another one.'],
            'a pattern without uuid or id' => ['video_url_pattern', '/{slug}/', 'Video addresses must contain {uuid} or {id}.'],
            'a placeholder nobody knows' => ['video_url_pattern', '/{slug}-{id}-{colour}/', 'The site does not know these placeholders: {colour}.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_the_refusal_is_a_sentence_of_the_admins_language(string $field, string $value, string $refusal): void
    {
        $admin = User::factory()->admin()->create();

        $errors = $this->actingAs($admin)
            ->putJson('/admin/settings/save', ['groups' => ['seo' => [$field => $value]], 'fields' => ['seo' => [$field]]])
            ->assertStatus(422)
            ->json('errors');

        $messages = array_merge(...array_values(array_map(static fn ($list): array => (array) $list, (array) $errors)));
        $this->assertContains($refusal, $messages, json_encode($errors));
    }
}
