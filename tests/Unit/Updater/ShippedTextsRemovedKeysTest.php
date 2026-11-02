<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\ShippedTexts;
use App\Support\Updater\TranslationMerge;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Judge R3-026: the merge of a language file the owner edited cannot tell the keys a release removed from the owner's own
 * keys, so an updated site whose owner had edited lang/de.json kept every key 1.2.3 removed (593 since 1.2.0, among them
 * the importers' catalogue names lane KS withdrew), with its old words, in Settings > Languages. The data file now lists
 * those keys ("removed": short SHA-256 of each key and of every text a release shipped for it, never the words), and
 * Finish takes such a key out of an edited file while its text is still exactly a shipped one; the owner's own text, a
 * key the release still has and a key only the owner's file has stay.
 */
class ShippedTextsRemovedKeysTest extends TestCase
{
    public function test_a_removed_key_with_a_shipped_text_leaves_the_file_and_the_owners_own_text_stays(): void
    {
        $removed = [
            ShippedTexts::shortHash('Old catalogue name') => [ShippedTexts::shortHash('Alter Katalogname'), ShippedTexts::shortHash('Old catalogue name')],
            ShippedTexts::shortHash('Old button') => [ShippedTexts::shortHash('Alter Knopf')],
        ];
        $texts = ['Save' => ['new' => 'Speichern', 'old' => ['Sichern']]];
        $live = [
            'Old catalogue name' => 'Alter Katalogname',   // shipped by 1.2.2: goes
            'Old button' => 'Mein eigener Knopf',          // the owner's own text: stays
            'Save' => 'Sichern',                           // corrected as before
            'My own key' => 'Mein Text',                   // a key only the owner's file has: stays
        ];

        $result = ShippedTexts::correct($live, $texts, $removed);

        $this->assertSame(['Old button' => 'Mein eigener Knopf', 'Save' => 'Speichern', 'My own key' => 'Mein Text'], $result['data']);
        $this->assertSame([1, 0, 1], [$result['updated'], $result['kept'], $result['removed']]);

        // Without the list (an older data file), nothing is removed: as before.
        $this->assertSame(0, ShippedTexts::correct($live, $texts)['removed']);
        $this->assertArrayHasKey('Old catalogue name', ShippedTexts::correct($live, $texts)['data']);
    }

    public function test_a_file_with_only_removed_keys_is_written_and_reported(): void
    {
        $root = $this->root();
        File::put($root.'/lang/de.json', TranslationMerge::encode(['Gone' => 'Weg', 'Mine' => 'Meins']));
        $shipped = ['lang/de.json' => str_repeat('0', 64)];
        $removed = [ShippedTexts::shortHash('Gone') => [ShippedTexts::shortHash('Weg')]];

        $report = ShippedTexts::refresh($root, ['fr' => ['Save' => ['new' => 'Enregistrer', 'old' => ['Sauver']]]], $shipped, $removed);

        $this->assertSame(['de' => ['updated' => 0, 'kept' => 0, 'removed' => 1, 'written' => true]], $report);
        $this->assertSame(['Mine' => 'Meins'], TranslationMerge::decode((string) file_get_contents($root.'/lang/de.json')));
        $this->assertSame([], ShippedTexts::refresh($root, [], $shipped, $removed), 'nothing left: safe to run again');
    }

    public function test_the_data_file_lists_the_removed_keys_by_short_hash_never_in_words(): void
    {
        $current = ['de' => ['Save' => 'Speichern'], 'en' => ['Save' => 'Save', 'Still here in English' => 'Still here in English']];
        $releases = [
            ['de' => ['Save' => 'Sichern', 'Withdrawn name' => 'Ein Name', 'Still here in English' => 'Noch da'], 'en' => ['Withdrawn name' => 'Withdrawn name']],
            ['de' => ['Withdrawn name' => 'Ein anderer Name']],
        ];
        // An earlier data file's list: a key the tree has again is dropped, another one stays.
        $carried = [ShippedTexts::shortHash('Save') => [ShippedTexts::shortHash('x')], ShippedTexts::shortHash('Gone long ago') => [ShippedTexts::shortHash('Lange weg')]];

        $data = ShippedTexts::build($current, [], ['1.2.2'], '1.2.3', $releases, $carried);
        $json = ShippedTexts::encodeData($data);

        $expected = [
            ShippedTexts::shortHash('Gone long ago') => [ShippedTexts::shortHash('Lange weg')],
            ShippedTexts::shortHash('Withdrawn name') => [ShippedTexts::shortHash('Ein Name'), ShippedTexts::shortHash('Ein anderer Name'), ShippedTexts::shortHash('Withdrawn name')],
        ];
        ksort($expected, SORT_STRING);
        $expected = array_map(function (array $texts): array {
            sort($texts, SORT_STRING);

            return $texts;
        }, $expected);

        $this->assertSame($expected, ShippedTexts::decodeRemoved($json));
        $this->assertSame(16, strlen((string) array_key_first(ShippedTexts::decodeRemoved($json))));

        foreach (['Withdrawn name', 'Ein Name', 'Ein anderer Name', 'Gone long ago', 'Lange weg', 'Noch da'] as $words) {
            $this->assertStringNotContainsString($words, $json, 'never in words');
        }

        // A key that one language file of this release still has is never removed (the site falls back to it).
        $this->assertArrayNotHasKey(ShippedTexts::shortHash('Still here in English'), ShippedTexts::decodeRemoved($json));
        $this->assertSame($data['texts'], ShippedTexts::decode($json), 'the texts read as before');

        // Nothing removed: no "removed" at all (the data file of a release that removed nothing stays as it was).
        $this->assertArrayNotHasKey('removed', ShippedTexts::build($current, [], ['1.2.2'], '1.2.3'));
    }

    public function test_a_hash_of_digits_only_is_read_back(): void
    {
        $json = (string) json_encode(['format' => ShippedTexts::FORMAT, 'texts' => [], 'removed' => ['1234567890123456' => ['abcdefabcdefabcd'], 'not-a-hash' => ['x']]]);

        $this->assertSame(['1234567890123456' => ['abcdefabcdefabcd']], ShippedTexts::decodeRemoved($json));
    }

    public function test_the_shipped_data_file_lists_the_keys_lane_ks_removed(): void
    {
        $removed = ShippedTexts::loadRemoved(base_path(ShippedTexts::PATH));

        $this->assertGreaterThan(500, count($removed), 'the keys 1.2.0 to 1.2.2 shipped that 1.2.3 no longer has');

        foreach (ShippedTexts::files(base_path()) as $file) {
            foreach (array_keys((array) TranslationMerge::decode((string) file_get_contents($file))) as $key) {
                $this->assertArrayNotHasKey(ShippedTexts::shortHash((string) $key), $removed, basename($file).': '.$key.' is a key of this release');
            }
        }
    }

    private function root(): string
    {
        $root = str_replace('\\', '/', storage_path('framework/testing/shipped-removed-'.bin2hex(random_bytes(4))));
        File::ensureDirectoryExists($root.'/lang');
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($root));

        return $root;
    }
}
