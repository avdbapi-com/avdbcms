<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\ShippedTexts;
use App\Support\Updater\TranslationMerge;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The texts the releases corrected (app/Support/Updater/shipped-texts.json) and the correction of a language file the
 * owner edited (1.2.3): a value still exactly as an older release shipped it becomes this release's text; the
 * owner's own texts, keys only the owner's file has and keys it does not have stay as they are; a corrected file has
 * nothing left to correct.
 */
class ShippedTextsTest extends TestCase
{
    /** de.json entries of the data file: three keys whose German text a release corrected. */
    private const TEXTS = [
        'Save' => ['new' => 'Speichern', 'old' => ['Sichern', 'Abspeichern']],
        'Delete' => ['new' => 'Löschen', 'old' => ['Entfernen']],
        'Upload' => ['new' => 'Hochladen', 'old' => ['Upload']],
        'Search' => ['new' => 'Suchen', 'old' => ['Suche']],
    ];

    public function test_an_edited_language_file_gets_the_corrected_texts_and_keeps_the_owners(): void
    {
        $live = [
            'Save' => 'Sichern',             // as 1.2.0 shipped it: corrected
            'Delete' => 'Entfernen',          // as 1.2.1 shipped it: corrected
            'Upload' => 'Datei senden',       // the owner's own text: kept
            'Search' => 'Suchen',             // already the new text: nothing to do
            'Home' => 'Mein Start',           // a key the release did not correct: the owner's, untouched
            'My own key' => 'Mein eigener',   // a key only the owner's file has: untouched
        ];

        $result = ShippedTexts::correct($live, self::TEXTS);

        $this->assertSame(2, $result['updated']);
        $this->assertSame(1, $result['kept']);
        $this->assertSame([
            'Save' => 'Speichern',
            'Delete' => 'Löschen',
            'Upload' => 'Datei senden',
            'Search' => 'Suchen',
            'Home' => 'Mein Start',
            'My own key' => 'Mein eigener',
        ], $result['data']);

        // Again: nothing left to correct, the owner's text is still counted as kept.
        $again = ShippedTexts::correct($result['data'], self::TEXTS);
        $this->assertSame([0, 1], [$again['updated'], $again['kept']]);
        $this->assertSame($result['data'], $again['data']);
    }

    public function test_a_key_the_file_does_not_have_is_never_added_and_a_close_text_is_never_taken_for_an_old_one(): void
    {
        $live = ['Save' => 'sichern', 'Delete' => 'Entfernen ', 'Upload' => 'Upload'];

        $result = ShippedTexts::correct($live, self::TEXTS);

        // Only an exact old text counts: another case or a trailing space is the owner's own text.
        $this->assertSame(['Save' => 'sichern', 'Delete' => 'Entfernen ', 'Upload' => 'Hochladen'], $result['data']);
        $this->assertSame([1, 2], [$result['updated'], $result['kept']]);
        $this->assertArrayNotHasKey('Search', $result['data']);
    }

    public function test_numeric_keys_are_corrected_like_any_other(): void
    {
        $texts = ShippedTexts::decode(ShippedTexts::encodeData(ShippedTexts::build(
            ['de' => ['404' => 'Nicht gefunden', '1' => 'Eins']],
            [['de' => ['404' => 'Fehlt', '1' => 'Eins']]],
            ['1.2.0'],
            '1.2.1',
        )));
        $live = TranslationMerge::decode('{"404": "Fehlt", "1": "Eins"}');

        $result = ShippedTexts::correct($live, $texts['de']);

        $this->assertSame(1, $result['updated']);
        $this->assertSame('Nicht gefunden', $result['data']['404']);
        $this->assertSame("{\n    \"1\": \"Eins\",\n    \"404\": \"Nicht gefunden\"\n}\n", TranslationMerge::encode($result['data']));
    }

    public function test_the_data_file_of_a_release_lists_only_texts_an_older_release_shipped_differently(): void
    {
        $current = [
            'de' => ['Save' => 'Speichern', 'Delete' => 'Löschen', 'New in 1.2.3' => 'Neu', 'Home' => 'Start'],
            'fr' => ['Save' => 'Enregistrer', 'Home' => 'Accueil'],
        ];
        $shipped = [
            // 1.2.0 (de: two texts corrected since; "Gone" is not in this release any more)
            ['de' => ['Save' => 'Sichern', 'Delete' => 'Entfernen', 'Home' => 'Start', 'Gone' => 'Weg'], 'fr' => ['Save' => 'Sauver', 'Home' => 'Accueil'], 'nl' => ['Save' => 'Opslaan']],
            // 1.2.1 (de: Save shipped with yet another text, Delete already as now)
            ['de' => ['Save' => 'Abspeichern', 'Delete' => 'Löschen', 'Home' => 'Start'], 'fr' => ['Save' => 'Sauver']],
            // what an older data file lists (several texts per key)
            ['de' => ['Delete' => ['Entfernen', 'Ausradieren']]],
        ];

        $data = ShippedTexts::build($current, $shipped, ['1.2.1', '1.2.0', 'not-a-version', '1.2.0'], '1.2.3');

        $this->assertSame(1, $data['format']);
        $this->assertSame('1.2.3', $data['for']);
        $this->assertSame(['1.2.0', '1.2.1'], $data['versions']);
        $this->assertSame([
            'de' => [
                'Delete' => ['new' => 'Löschen', 'old' => ['Ausradieren', 'Entfernen']],
                'Save' => ['new' => 'Speichern', 'old' => ['Abspeichern', 'Sichern']],
            ],
            'fr' => [
                'Save' => ['new' => 'Enregistrer', 'old' => ['Sauver']],
            ],
        ], $data['texts'], 'only changed texts, never the current one among the old, no key or language this release lacks');

        // The text written and read back; the counts of the build's log.
        $json = ShippedTexts::encodeData($data);
        $this->assertStringEndsWith("}\n", $json);
        $this->assertStringContainsString('"Löschen"', $json, 'UTF-8 unescaped, like the language files');
        $this->assertSame($data['texts'], ShippedTexts::decode($json));
        $this->assertSame(['for' => '1.2.3', 'versions' => ['1.2.0', '1.2.1']], ShippedTexts::versions($json));
        $this->assertSame(['de' => ['keys' => 2, 'old' => 4], 'fr' => ['keys' => 1, 'old' => 1]], ShippedTexts::counts($data['texts']));

        // The same inputs give the same file, byte for byte (the build makes it on every run).
        $this->assertSame($json, ShippedTexts::encodeData(ShippedTexts::build($current, array_reverse($shipped), ['1.2.0', '1.2.1'], '1.2.3')));
        $this->assertSame('{}', trim(json_encode(json_decode(ShippedTexts::encodeData(ShippedTexts::build([], [], [], '1.2.3')))->texts)));
    }

    public function test_a_data_file_that_is_not_one_is_not_used_and_bad_entries_are_left_out(): void
    {
        foreach (['', 'nonsense', '[]', '{"format": 2, "texts": {}}', '{"format": 1}', '{"format": "1", "texts": {}}', '{"format": 1, "texts": "x"}'] as $json) {
            $this->assertNull(ShippedTexts::decode($json), $json);
        }

        $texts = ShippedTexts::decode((string) json_encode(['format' => 1, 'texts' => [
            'de' => [
                'Good' => ['new' => 'Gut', 'old' => ['Fein', 'Gut', 7, 'Fein']],
                'No old' => ['new' => 'X', 'old' => []],
                'Only the new' => ['new' => 'Y', 'old' => ['Y']],
                'No new' => ['old' => ['Z']],
                'Not an entry' => 'text',
            ],
            '../evil' => ['Good' => ['new' => 'A', 'old' => ['B']]],
            'fr' => 'not a list',
        ]]));

        $this->assertSame(['de' => ['Good' => ['new' => 'Gut', 'old' => ['Fein']]]], $texts);
        // A BOM in front changes nothing.
        $this->assertSame($texts, ShippedTexts::decode("\xEF\xBB\xBF".json_encode(['format' => 1, 'texts' => ['de' => ['Good' => ['new' => 'Gut', 'old' => ['Fein']]]]])));
    }

    public function test_a_data_file_is_used_only_for_the_version_it_was_made_for(): void
    {
        $root = $this->root();
        File::ensureDirectoryExists($root.'/app/Support/Updater');
        File::put($root.'/'.ShippedTexts::PATH, ShippedTexts::encodeData(ShippedTexts::build(['de' => ['Save' => 'Speichern']], [['de' => ['Save' => 'Sichern']]], ['1.2.2'], '1.2.3')));

        $this->assertSame(['de' => ['Save' => ['new' => 'Speichern', 'old' => ['Sichern']]]], ShippedTexts::load($root.'/'.ShippedTexts::PATH));
        $this->assertSame(['de' => ['Save' => ['new' => 'Speichern', 'old' => ['Sichern']]]], ShippedTexts::load($root.'/'.ShippedTexts::PATH, '1.2.3'));
        $this->assertNull(ShippedTexts::load($root.'/'.ShippedTexts::PATH, '1.2.4'), 'made for another version: its new texts are not this version\'s');
        $this->assertNull(ShippedTexts::load($root.'/missing.json'));
    }

    public function test_a_language_file_is_corrected_in_its_own_line_endings_and_a_broken_one_is_left_alone(): void
    {
        $root = $this->root();
        $crlf = str_replace("\n", "\r\n", TranslationMerge::encode(['Save' => 'Sichern', 'Upload' => 'Datei senden', 'Zeta' => 'Z']));
        File::put($root.'/lang/de.json', "\xEF\xBB\xBF".$crlf);
        File::put($root.'/lang/fr.json', '{"Save": ');

        $result = ShippedTexts::correctFile($root.'/lang/de.json', self::TEXTS);

        $this->assertSame([1, 1], [$result['updated'], $result['kept']]);
        $this->assertSame(str_replace("\n", "\r\n", TranslationMerge::encode(['Save' => 'Speichern', 'Upload' => 'Datei senden', 'Zeta' => 'Z'])), $result['text']);
        $this->assertSame("\xEF\xBB\xBF".$crlf, $result['before']);
        $this->assertNull(ShippedTexts::correctFile($root.'/lang/fr.json', self::TEXTS), 'a file the owner broke stays as it is');
        $this->assertNull(ShippedTexts::correctFile($root.'/lang/xx.json', self::TEXTS));

        // Nothing to correct: no text to write.
        File::put($root.'/lang/es.json', TranslationMerge::encode(['Save' => 'Guardar']));
        $this->assertSame(['before' => TranslationMerge::encode(['Save' => 'Guardar']), 'text' => null, 'updated' => 0, 'kept' => 1, 'removed' => 0], ShippedTexts::correctFile($root.'/lang/es.json', self::TEXTS));
    }

    public function test_only_the_language_files_at_the_top_of_lang_count_and_a_file_is_replaced_in_one_rename(): void
    {
        $root = $this->root();
        File::ensureDirectoryExists($root.'/lang/de');
        File::put($root.'/lang/de/auth.php', "<?php return [];\n");
        File::put($root.'/lang/de.json', '{}');
        File::put($root.'/lang/pt_BR.json', '{}');
        File::put($root.'/lang/notes.txt', 'x');
        File::put($root.'/lang/de.json.avdbcms-1.2.3', '{}');
        File::put($root.'/lang/.de.json.1a2b3c4d.tmp', '{}');

        $this->assertSame(['de' => $root.'/lang/de.json', 'pt_BR' => $root.'/lang/pt_BR.json'], ShippedTexts::files($root));

        $this->assertTrue(ShippedTexts::write($root.'/lang/de.json', "{\n    \"Save\": \"Speichern\"\n}\n"));
        $this->assertSame("{\n    \"Save\": \"Speichern\"\n}\n", File::get($root.'/lang/de.json'));
        $this->assertSame(['.de.json.1a2b3c4d.tmp', 'de', 'de.json', 'de.json.avdbcms-1.2.3', 'notes.txt', 'pt_BR.json'], $this->names($root.'/lang'), 'no temporary file left behind');

        // A folder that cannot take the file: false, the old file as it was.
        $this->assertFalse(ShippedTexts::write($root.'/lang/missing/xx.json', '{}'));
    }

    public function test_a_file_exactly_as_this_version_shipped_it_is_never_looked_at(): void
    {
        $root = $this->root();
        File::put($root.'/lang/de.json', TranslationMerge::encode(['Save' => 'Sichern']));
        $shipped = ['lang/de.json' => hash('sha256', TranslationMerge::encode(['Save' => 'Sichern']))];

        $this->assertFalse(ShippedTexts::edited($root, 'de', $shipped), 'as shipped');
        $this->assertTrue(ShippedTexts::edited($root, 'de', ['lang/de.json' => str_repeat('0', 64)]), 'changed by the owner');
        $this->assertTrue(ShippedTexts::edited($root, 'de', []), 'a language this version does not ship');

        // refresh(): only the edited file is corrected; one as shipped stays byte for byte, also when it has an old text.
        File::put($root.'/lang/fr.json', TranslationMerge::encode(['Save' => 'Sauver', 'Home' => 'Mon accueil']));
        $texts = ['de' => ['Save' => ['new' => 'Speichern', 'old' => ['Sichern']]], 'fr' => ['Save' => ['new' => 'Enregistrer', 'old' => ['Sauver']], 'Home' => ['new' => 'Accueil', 'old' => ['Début']]]];

        $report = ShippedTexts::refresh($root, $texts, $shipped);

        $this->assertSame(['fr' => ['updated' => 1, 'kept' => 1, 'removed' => 0, 'written' => true]], $report);
        $this->assertSame(TranslationMerge::encode(['Save' => 'Sichern']), File::get($root.'/lang/de.json'));
        $this->assertSame(['Home' => 'Mon accueil', 'Save' => 'Enregistrer'], TranslationMerge::decode(File::get($root.'/lang/fr.json')));

        // Again: nothing left to correct; the owner's own text is still reported as kept.
        $this->assertSame(['fr' => ['updated' => 0, 'kept' => 1, 'removed' => 0, 'written' => true]], ShippedTexts::refresh($root, $texts, $shipped));
    }

    public function test_pending_counts_what_a_correction_would_change_and_writes_nothing(): void
    {
        $root = $this->root();
        $text = TranslationMerge::encode(['Save' => 'Sichern', 'Delete' => 'Entfernen', 'Upload' => 'Datei senden', 'Mein' => 'Text']);
        File::put($root.'/lang/de.json', $text);
        File::put($root.'/lang/fr.json', '{"Save": ');

        $this->assertSame(2, ShippedTexts::pending($root.'/lang/de.json', self::TEXTS));
        $this->assertSame($text, File::get($root.'/lang/de.json'), 'read only');
        $this->assertSame(ShippedTexts::correctFile($root.'/lang/de.json', self::TEXTS)['updated'], ShippedTexts::pending($root.'/lang/de.json', self::TEXTS));
        $this->assertNull(ShippedTexts::pending($root.'/lang/fr.json', self::TEXTS), 'a broken file');
        $this->assertNull(ShippedTexts::pending($root.'/lang/xx.json', self::TEXTS), 'no file');

        File::put($root.'/lang/es.json', TranslationMerge::encode(['Save' => 'Speichern', 'Upload' => 'Mein Upload']));
        $this->assertSame(0, ShippedTexts::pending($root.'/lang/es.json', self::TEXTS), 'only new or own texts');
    }

    public function test_the_shipped_data_file_is_a_data_file_of_this_code(): void
    {
        $json = (string) file_get_contents(base_path(ShippedTexts::PATH));
        $texts = ShippedTexts::decode($json);

        $this->assertIsArray($texts, ShippedTexts::PATH.' is a data file of format '.ShippedTexts::FORMAT);
        $this->assertNotSame([], $texts);
        $versions = ShippedTexts::versions($json);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $versions['for']);
        $this->assertContains('1.2.0', $versions['versions'], 'the texts of 1.2.0 (sites update from it)');

        foreach ($texts as $locale => $entries) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $locale);

            foreach ($entries as $entry) {
                $this->assertNotContains($entry['new'], $entry['old']);
            }
        }

        // German: the texts 1.2.2 corrected (178 of them) are among them; a later release may drop a few keys.
        $this->assertGreaterThan(100, count($texts['de'] ?? []));
    }

    /* ----------------------------------------------------------------- helpers */

    private function root(): string
    {
        $root = str_replace('\\', '/', storage_path('framework/testing/shipped-texts-'.bin2hex(random_bytes(4))));
        File::ensureDirectoryExists($root.'/lang');
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($root));

        return $root;
    }

    /** @return list<string> */
    private function names(string $folder): array
    {
        $names = array_values(array_diff(scandir($folder) ?: [], ['.', '..']));
        sort($names, SORT_STRING);

        return $names;
    }
}
