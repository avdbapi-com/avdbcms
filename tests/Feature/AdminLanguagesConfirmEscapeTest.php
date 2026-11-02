<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security plan S17: the old admin's Languages pages put the confirm text into single-quoted onsubmit attributes. A
 * label with an apostrophe ("O'zbek") used to end the attribute: the confirm broke (the form went through unasked) and
 * a label like "x' onmouseover='alert(1)//" added an attribute of its own. The text is now encoded so that it stays
 * inside the attribute, and the confirm shows the label as it is.
 */
class AdminLanguagesConfirmEscapeTest extends TestCase
{
    use RefreshDatabase;

    private const LABELS = ["x' onmouseover='alert(1)//", "O'zbek"];

    public function test_labels_with_apostrophes_stay_inside_the_confirm(): void
    {
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();
        $languages = [];

        foreach (self::LABELS as $i => $label) {
            $languages[] = Language::query()->create([
                'code' => ['xa', 'uz'][$i], 'label' => $label, 'emoji' => '', 'direction' => 'ltr',
                'is_default' => false, 'is_active' => true, 'sort_order' => 90 + $i,
            ]);
        }

        $pages = ['/admin/languages'];
        foreach ($languages as $language) {
            $pages[] = route('admin.languages.edit-meta', $language);
        }

        foreach ($pages as $page) {
            $html = (string) $this->actingAs($admin)->get($page)->assertOk()->getContent();
            $document = new \DOMDocument();
            @$document->loadHTML($html);
            $xpath = new \DOMXPath($document);

            $this->assertSame(0, $xpath->query('//*[@onmouseover]')->length, $page.': no attribute of the label\'s own');

            $confirms = [];
            foreach ($xpath->query('//form[@onsubmit]') as $form) {
                $handler = (string) $form->getAttribute('onsubmit');
                $this->assertSame(1, preg_match('/^return confirm\((.*)\)$/s', $handler, $match), $page.': '.$handler);
                $text = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
                $this->assertIsString($text);
                $confirms[] = $text;
            }

            foreach ($languages as $language) {
                if ($page === '/admin/languages' || str_contains($page, '/'.$language->id.'/')) {
                    $this->assertNotEmpty(array_filter($confirms, fn (string $text): bool => str_contains($text, $language->label)),
                        $page.': a confirm names '.$language->label);
                }
            }
        }
    }
}
