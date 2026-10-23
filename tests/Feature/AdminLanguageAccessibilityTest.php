<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLanguageAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_language_form_associates_labels_with_stable_controls(): void
    {
        $document = $this->getAdminDocument(route('admin.languages.create'));
        $xpath = new \DOMXPath($document);

        $this->assertFieldHasLabel($xpath, 'language-code');
        $this->assertFieldHasLabel($xpath, 'language-name');
        $this->assertFieldHasLabel($xpath, 'language-emoji');
        $this->assertFieldHasLabel($xpath, 'language-direction');

        $this->assertSame(1, $xpath->query('//input[@id="is_active" and @name="is_active"]')->length);
        $this->assertSame(1, $xpath->query('//label[@for="is_active"]')->length);
    }

    public function test_edit_language_metadata_associates_labels_with_stable_controls(): void
    {
        $language = Language::query()->where('code', 'en')->firstOrFail();
        $document = $this->getAdminDocument(route('admin.languages.edit-meta', $language));
        $xpath = new \DOMXPath($document);

        $this->assertFieldHasLabel($xpath, 'language-code');
        $this->assertFieldHasLabel($xpath, 'language-name');
        $this->assertFieldHasLabel($xpath, 'language-emoji');
        $this->assertFieldHasLabel($xpath, 'language-direction');
        $this->assertFieldHasLabel($xpath, 'language-sort-order');

        $this->assertSame(1, $xpath->query('//input[@id="is_active" and @name="is_active"]')->length);
        $this->assertSame(1, $xpath->query('//label[@for="is_active"]')->length);
    }

    public function test_language_index_exposes_named_actions_progress_values_and_import_dialog(): void
    {
        $document = $this->getAdminDocument(route('admin.languages.index'));
        $xpath = new \DOMXPath($document);

        $progressBars = $xpath->query('//div[@role="progressbar"]');
        $this->assertGreaterThan(0, $progressBars->length);
        foreach ($progressBars as $progressBar) {
            $this->assertNotSame('', $progressBar->getAttribute('aria-label'));
            $this->assertMatchesRegularExpression('/^(?:0|[1-9][0-9]?|100)$/', $progressBar->getAttribute('aria-valuenow'));
            $this->assertSame('0', $progressBar->getAttribute('aria-valuemin'));
            $this->assertSame('100', $progressBar->getAttribute('aria-valuemax'));
            $this->assertMatchesRegularExpression('/^\d+%$/', $progressBar->getAttribute('aria-valuetext'));
        }

        $iconOnlyActions = $xpath->query(
            '//td[last()]//*[self::a or self::button][.//i and string-length(normalize-space(.)) = 0]'
        );
        $this->assertGreaterThan(0, $iconOnlyActions->length);
        foreach ($iconOnlyActions as $action) {
            $this->assertNotSame('', $action->getAttribute('aria-label'));
        }

        $modal = $xpath->query('//div[@id="importModal"]')->item(0);
        $this->assertNotNull($modal);
        $this->assertSame('dialog', $modal->getAttribute('role'));
        $this->assertSame('true', $modal->getAttribute('aria-modal'));
        $this->assertSame('importModalLabel', $modal->getAttribute('aria-labelledby'));
        $this->assertSame(1, $xpath->query('//*[@id="importModalLabel"]')->length);

        $closeButton = $xpath->query('//div[@id="importModal"]//button[@data-bs-dismiss="modal"]')->item(0);
        $this->assertNotNull($closeButton);
        $this->assertSame(__('Close'), $closeButton->getAttribute('aria-label'));

        $this->assertFieldHasLabel($xpath, 'import-locale');
        $this->assertFieldHasLabel($xpath, 'import-json-file');
    }

    public function test_language_index_speaks_the_admins_language(): void
    {
        $document = $this->getAdminDocument(route('admin.languages.index'), 'vi');
        $xpath = new \DOMXPath($document);

        $this->assertSame(1, $xpath->query('//html[@lang="vi"]')->length);
        $progressBars = $xpath->query('//div[@role="progressbar"]');
        $this->assertGreaterThan(0, $progressBars->length);
        foreach ($progressBars as $progressBar) {
            $label = $progressBar->getAttribute('aria-label');
            $this->assertStringStartsWith('Mức độ dịch của ', $label);
            $this->assertStringNotContainsString('translation coverage', $label);
        }

        $closeButton = $xpath->query('//div[@id="importModal"]//button[@data-bs-dismiss="modal"]')->item(0);
        $this->assertNotNull($closeButton);
        $this->assertSame(trans('Close', [], 'vi'), $closeButton->getAttribute('aria-label'));
        $this->assertNotSame('Close', $closeButton->getAttribute('aria-label'));
    }

    public function test_arabic_admin_pages_are_right_to_left(): void
    {
        $document = $this->getAdminDocument(route('admin.languages.index'), 'ar');
        $xpath = new \DOMXPath($document);

        $this->assertSame(1, $xpath->query('//html[@lang="ar" and @dir="rtl"]')->length);
        foreach ($xpath->query('//div[@role="progressbar"]') as $progressBar) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $progressBar->getAttribute('aria-label'));
        }
    }

    private function getAdminDocument(string $url, ?string $locale = null): \DOMDocument
    {
        $admin = User::factory()->admin()->create();

        $request = $this->actingAs($admin);
        if ($locale !== null) {
            $request = $request->withSession(['app_locale' => $locale]);
        }
        $response = $request->get($url)->assertOk();
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function assertFieldHasLabel(\DOMXPath $xpath, string $id): void
    {
        $this->assertSame(1, $xpath->query(sprintf('//*[@id="%s"]', $id))->length);
        $this->assertSame(1, $xpath->query(sprintf('//label[@for="%s"]', $id))->length);
    }
}
