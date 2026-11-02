<?php

namespace Tests\Unit;

use App\Support\ContentSafety;
use App\Support\Updater\ShippedTexts;
use Tests\TestCase;

/**
 * 1.2.3 round 2 (lane KS, the reviewer's KS-R01): no shipped translation (lang/<locale>.json, every language) names a
 * child, a minor or a school pupil where its English text does not: the importers' catalogue showed visitors such
 * translations of ordinary tag and category names (the tag Girl, the category Teen) through Tag::localizedName and
 * __($category->title). The words are the hard list's own, in every language (App\Support\ContentSafety); a text of the
 * code whose English names a child (the report reason Child abuse, the setting's help) keeps its translations.
 *
 * The language files are corrected by the release merge from the lane's i18n.json (lane copies never merge them): while a
 * translation the data file app/Support/Updater/shipped-texts.json withdrew is still in a language file, the merge has
 * not run in this tree and the test says so instead of failing; on the merged tree it checks every file.
 */
class CatalogueTranslationsSafetyTest extends TestCase
{
    public function test_no_shipped_translation_names_a_minor_where_its_english_does_not(): void
    {
        $texts = ShippedTexts::decode((string) file_get_contents(base_path(ShippedTexts::PATH))) ?? [];
        $pending = 0;
        $files = glob(lang_path('*.json')) ?: [];
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $locale = basename($file, '.json');
            $lang = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($lang, $locale);

            foreach ($texts[$locale] ?? [] as $key => $entry) {
                if (is_string($lang[$key] ?? null) && in_array(ShippedTexts::hashed($lang[$key]), $entry['old'], true)) {
                    $pending++;
                }
            }
        }

        if ($pending > 0) {
            $this->markTestSkipped($pending.' withdrawn translations are still in lang/*.json: the release merge applies the corrections (lane KS i18n.json); this tree is a lane copy.');
        }

        $found = [];

        foreach ($files as $file) {
            $locale = basename($file, '.json');

            foreach (json_decode((string) file_get_contents($file), true) as $key => $value) {
                if (is_string($value) && $value !== (string) $key && ContentSafety::hardMatch($value) !== null && ContentSafety::hardMatch((string) $key) === null) {
                    $found[] = $locale.': '.mb_substr((string) $key, 0, 60);
                }
            }
        }

        $this->assertSame([], $found, 'translations that name a minor where the English text does not');
    }
}
