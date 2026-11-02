<?php

namespace Tests\Unit;

use App\Support\ContentSafety;
use App\Support\Updater\ShippedTexts;
use Tests\TestCase;

/**
 * 1.2.3 (lane KS): the catalogue names the hard list or the default list match leave the shipped language files (the
 * merge removes them from lang/*.json), and so they leave app/Support/Updater/shipped-texts.json too: the updater never
 * writes a newer text of such a name into a language file an owner edited (ShippedTexts::correct() only touches the keys
 * the data file lists), and the data file still reads as before.
 */
class ShippedTextsBlockedWordsTest extends TestCase
{
    /** Texts of the code (not catalogue names) that a list matches: they stay in the language files. */
    private const CODE_TEXTS = [
        'Child abuse',
        'Forced session.secure=true because the request / APP_URL is HTTPS.',
        'Uncensored Leaked',
    ];

    public function test_the_data_file_lists_no_catalogue_name_of_the_lists(): void
    {
        $texts = ShippedTexts::decode((string) file_get_contents(base_path(ShippedTexts::PATH)));
        $this->assertIsArray($texts, 'the data file reads');
        $this->assertNotSame([], $texts);

        $matched = [];

        foreach ($texts as $locale => $entries) {
            foreach (array_keys($entries) as $key) {
                if (in_array($key, self::CODE_TEXTS, true)) {
                    continue;
                }

                if (ContentSafety::hardMatch($key) !== null || ContentSafety::siteMatch($key, ContentSafety::DEFAULT_WORDS) !== null) {
                    $matched[] = $locale.': '.$key;
                }
            }
        }

        $this->assertSame([], $matched);
    }

    /**
     * Round 2 (the reviewer's KS-R01): the data file carries no text that names a child, a minor or a school pupil (in
     * any language) where the English text does not; the translations the lane withdrew are listed by their SHA-256
     * only (ShippedTexts::HASHED), with the new text.
     */
    public function test_the_data_file_carries_no_text_that_names_a_minor(): void
    {
        $texts = ShippedTexts::decode((string) file_get_contents(base_path(ShippedTexts::PATH)));
        $words = [];

        foreach ($texts as $locale => $entries) {
            foreach ($entries as $key => $entry) {
                if (ContentSafety::hardMatch((string) $key) !== null) {
                    continue;
                }

                foreach ([$entry['new'], ...$entry['old']] as $text) {
                    if (! str_starts_with($text, ShippedTexts::HASHED) && ContentSafety::hardMatch($text) !== null) {
                        $words[] = $locale.': '.$key;
                    }
                }
            }
        }

        $this->assertSame([], $words);

        // e.g. the catalogue's tag "Girl" in Japanese and the XVIDAPI category label "Teen" in Korean
        foreach ([['ja', 'Girl', 'Girl'], ['ko', 'Teen', '틴 (18+)']] as [$locale, $key, $new]) {
            $entry = $texts[$locale][$key] ?? null;
            $this->assertNotNull($entry, $locale.' '.$key);
            $this->assertSame($new, $entry['new']);
            $this->assertNotSame([], $entry['old']);
            foreach ($entry['old'] as $old) {
                $this->assertStringStartsWith(ShippedTexts::HASHED, $old, 'never in words');
            }
        }
    }

    /**
     * Round 2: a language file an owner edited still gets the correction of a withdrawn text (correct() knows it by its
     * hash; the owner's own texts stay), and the release build never writes a withdrawn text in words again although the
     * previous release's zip has it (build() keeps the hash the tree's data file lists).
     */
    public function test_a_withdrawn_text_is_corrected_by_its_hash_and_never_written_in_words_again(): void
    {
        $withdrawn = 'a withdrawn translation';
        $texts = ['Girl' => ['new' => 'Girl', 'old' => [ShippedTexts::hashed($withdrawn)]], 'Save' => ['new' => 'Speichern', 'old' => ['Sichern']]];

        $result = ShippedTexts::correct(['Girl' => $withdrawn, 'Save' => 'Mein Text', 'Other' => 'x'], $texts);
        $this->assertSame(['Girl' => 'Girl', 'Save' => 'Mein Text', 'Other' => 'x'], $result['data']);
        $this->assertSame([1, 1], [$result['updated'], $result['kept']]);
        $this->assertSame(0, ShippedTexts::correct(['Girl' => 'Girl'], $texts)['updated'], 'a corrected file has nothing left');

        $data = ShippedTexts::build(
            ['ja' => ['Girl' => 'Girl', 'Save' => '保存']],
            [
                // the tree's own data file: the hash; the previous release: the words, and a text of another key
                ['ja' => ['Girl' => [ShippedTexts::hashed($withdrawn)]]],
                ['ja' => ['Girl' => $withdrawn, 'Save' => '保存する']],
            ],
            ['1.2.2'],
            '1.2.3'
        );
        $this->assertSame(['new' => 'Girl', 'old' => [ShippedTexts::hashed($withdrawn)]], $data['texts']['ja']['Girl']);
        $this->assertSame(['new' => '保存', 'old' => ['保存する']], $data['texts']['ja']['Save'], 'other texts as before');
        $this->assertStringNotContainsString($withdrawn, ShippedTexts::encodeData($data));
        $this->assertSame($data['texts'], ShippedTexts::decode(ShippedTexts::encodeData($data)), 'the data file reads back');
    }

    /** The logic check of the brief ("only if that keeps its logic right"): it holds before and after the removal. */
    public function test_the_updater_still_corrects_the_other_texts_and_leaves_a_removed_name_alone(): void
    {
        $texts = ShippedTexts::decode((string) file_get_contents(base_path(ShippedTexts::PATH)));
        $german = $texts['de'] ?? [];
        $kept = array_key_first($german);
        $this->assertNotNull($kept, 'the data file corrects some German texts');

        // an owner's German file with a catalogue name (an old shipped text) and a text the data file corrects
        $live = ['Voyeur' => 'Voyeur', $kept => $german[$kept]['old'][0]];
        $result = ShippedTexts::correct($live, $german);

        $this->assertSame('Voyeur', $result['data']['Voyeur'], 'no entry: the name is not rewritten');
        $this->assertSame($german[$kept]['new'], $result['data'][$kept], 'the other texts are still corrected');
        $this->assertSame(1, $result['updated']);
    }
}
