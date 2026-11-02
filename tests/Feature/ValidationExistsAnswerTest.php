<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 1.2.3 round 3 pre-fix pass, R2R-08 (verified, no change needed in this pass): round 2's lanes saw the validation line
 * 'exists' as the English "This :attribute is not found in ours records" in 16 of 17 languages (int-C2). Round 2's
 * R2A-049 (lane CA) had already given every language its own plain line and corrected the English, and int-C3 has it.
 * This guards it: every language's line is its own, the English reads "The selected :attribute was not found.", and an
 * `exists` rule without a message of its own answers in the reader's language. A like of a video that is not there has
 * its own plain sentence since the pre-fix merge (validation.custom.id.exists), also in the reader's language.
 */
class ValidationExistsAnswerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_every_language_has_its_own_exists_line_and_the_english_is_plain(): void
    {
        $english = (require lang_path('en/validation.php'))['exists'];
        $this->assertSame('The selected :attribute was not found.', $english);

        $locales = array_values(array_diff(array_unique(array_merge(array_keys((array) config('languages')), ['fil'])), ['en']));
        $this->assertGreaterThanOrEqual(17, count($locales));
        foreach ($locales as $locale) {
            $line = (require lang_path($locale.'/validation.php'))['exists'];

            $this->assertNotSame($english, $line, $locale);
            $this->assertStringNotContainsString('ours records', $line, $locale);
            $this->assertStringNotContainsString('not found in', $line, $locale);
            $this->assertMatchesRegularExpression('/:attribute/i', $line, $locale.' names the field');
        }
    }

    /**
     * A like of something no longer shown answers the plain sentence of validation.custom.id.exists in the reader's
     * language (merged after the pre-fix pass with lane TR's look-around: the field "id" is no word a reader knows).
     */
    public function test_a_like_of_a_video_that_is_not_there_answers_in_the_readers_language(): void
    {
        config(['cache.limiter' => 'array']);
        Cache::flush();
        $member = User::factory()->create();
        $missing = (int) Video::withTrashed()->max('id') + 1000;
        $answers = [];

        foreach (['en', 'es', 'de'] as $locale) {
            $this->app['auth']->forgetGuards();
            $response = $this->actingAs($member, 'sanctum')
                ->withHeader('X-Locale', $locale)
                ->postJson('/api/like', ['model' => Video::class, 'id' => $missing])
                ->assertStatus(422);

            $this->assertSame($locale, $response->headers->get('Content-Language'), $locale);
            $this->assertSame(__('validation.custom.id.exists', [], $locale), $response->json('errors.id.0'), $locale);
            $answers[$locale] = (string) $response->json('errors.id.0');
        }

        $this->assertSame('This is no longer available.', $answers['en']);
        $this->assertSame('Esto ya no está disponible.', $answers['es']);
        $this->assertSame('Dieser Inhalt ist nicht mehr verfügbar.', $answers['de']);
    }

    /** An `exists` rule without a sentence of its own answers the plain line of the reader's language. */
    public function test_an_exists_rule_without_its_own_sentence_answers_the_readers_language(): void
    {
        $answers = [];

        foreach (['en', 'es', 'de'] as $locale) {
            app()->setLocale($locale);
            $message = Validator::make(['category_id' => 999999999], ['category_id' => 'exists:categories,id'])->errors()->first('category_id');

            $this->assertSame(__('validation.exists', ['attribute' => __('validation.attributes.category_id', [], $locale)], $locale), $message, $locale);
            $answers[$locale] = $message;
        }

        $this->assertSame('The selected category was not found.', $answers['en']);
        $this->assertStringStartsWith('No se encontró el valor elegido para', $answers['es']);
        $this->assertStringStartsWith('Der gewählte Wert für', $answers['de']);
    }
}
