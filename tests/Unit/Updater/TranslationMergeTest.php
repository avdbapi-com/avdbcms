<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\Manifest;
use App\Support\Updater\SwapPlanner;
use App\Support\Updater\TranslationMerge;
use Tests\TestCase;

/**
 * Translations (updater.md amendment 1, F8 9, 18). A language file still as shipped is replaced as a whole, so its
 * unchanged keys take the new texts and the keys the release removed go. A file the owner changed is merged: the
 * owner's text wins for every key both files have, the release's new keys come in, and keys only the owner's file has
 * stay (with the installed manifest's file hash as the only "base", a key the release removed cannot be told from a key
 * the owner added, so both stay). A file the release did not change stays exactly as the owner has it.
 */
class TranslationMergeTest extends TestCase
{
    public function test_the_owner_wins_for_shared_keys_and_new_keys_come_in(): void
    {
        $owner = ['Hello' => 'Chào bạn', 'Bye' => 'Tạm biệt', 'My own' => 'Của tôi'];
        $release = ['Hello' => 'Xin chào', 'Bye' => 'Tạm biệt', 'New' => 'Mới', 'Also new' => 'Cũng mới'];

        $result = TranslationMerge::merge($owner, $release);

        $this->assertSame(['Hello' => 'Chào bạn', 'Bye' => 'Tạm biệt', 'My own' => 'Của tôi', 'New' => 'Mới', 'Also new' => 'Cũng mới'], $result['data']);
        $this->assertSame(2, $result['added']);
        $this->assertSame(1, $result['kept'], 'one shared key has a text of the owner');
    }

    public function test_language_files_are_read_and_written_like_settings_languages(): void
    {
        $this->assertSame(['A' => 'a', 'B' => 'b'], TranslationMerge::decode("\xEF\xBB\xBF{\"A\": \"a\", \"B\": \"b\"}"));
        $this->assertSame([], TranslationMerge::decode('{}'));
        $this->assertNull(TranslationMerge::decode('["a", "b"]'), 'a list');
        $this->assertNull(TranslationMerge::decode('{"A": {"nested": "x"}}'), 'not flat');
        $this->assertNull(TranslationMerge::decode('{"A": 1}'), 'not a string');
        $this->assertNull(TranslationMerge::decode('{"A": "a",}'), 'broken JSON');

        $this->assertSame("{\n    \"A\": \"á/b\",\n    \"B\": \"b\"\n}\n", TranslationMerge::encode(['B' => 'b', 'A' => 'á/b']));
        $this->assertSame("{\r\n    \"A\": \"a\"\r\n}\r\n", TranslationMerge::encode(['A' => 'a'], "\r\n"));
        $this->assertSame("{}\n", TranslationMerge::encode([]));
        $this->assertSame("\r\n", TranslationMerge::lineEnding("{\r\n  \"a\": \"b\"\r\n}\r\n"));
        $this->assertSame("\n", TranslationMerge::lineEnding("{\n}\n"));

        $this->assertTrue(TranslationMerge::isLanguageFile('lang/vi.json'));
        $this->assertTrue(TranslationMerge::isLanguageFile('lang/pt-BR.json'));
        $this->assertFalse(TranslationMerge::isLanguageFile('lang/vi/auth.json'));
        $this->assertFalse(TranslationMerge::isLanguageFile('vendor/pkg/lang/vi.json'));
        $this->assertTrue(TranslationMerge::isLanguageArray('lang/vi/auth.php'));
        $this->assertFalse(TranslationMerge::isLanguageArray('lang/vi.json'));
    }

    public function test_an_unchanged_language_file_is_replaced_whole_so_removed_keys_go(): void
    {
        $shipped = $this->encodeJson(['Hello' => 'Xin chào', 'Old key' => 'Khóa cũ']);
        $release = $this->encodeJson(['Hello' => 'Xin chào!', 'New' => 'Mới']);
        $plan = $this->plan(['lang/vi.json' => $shipped], ['lang/vi.json' => $shipped], ['lang/vi.json' => $release]);

        $this->assertSame([['put', 'lang/vi.json', 'staging/lang/vi.json']], $this->moves($plan));
        $this->assertSame(1, $plan['report']['replaced']);
        $this->assertSame([], $plan['merged']);
    }

    public function test_a_language_file_the_owner_changed_is_merged(): void
    {
        $shipped = $this->encodeJson(['Hello' => 'Xin chào', 'Bye' => 'Tạm biệt', 'Old key' => 'Khóa cũ']);
        $owner = $this->encodeJson(['Hello' => 'Chào bạn', 'Bye' => 'Tạm biệt', 'Old key' => 'Khóa cũ', 'Mine' => 'Của tôi']);
        $release = $this->encodeJson(['Hello' => 'Xin chào!', 'Bye' => 'Tạm biệt nhé', 'New' => 'Mới']);
        $plan = $this->plan(['lang/vi.json' => $shipped], ['lang/vi.json' => $owner], ['lang/vi.json' => $release]);

        $this->assertSame([['put', 'lang/vi.json', 'merged/lang/vi.json']], $this->moves($plan));
        $this->assertSame([['path' => 'lang/vi.json', 'added' => 1, 'kept' => 2]], $plan['report']['merged']);
        $this->assertSame(
            ['Bye' => 'Tạm biệt', 'Hello' => 'Chào bạn', 'Mine' => 'Của tôi', 'New' => 'Mới', 'Old key' => 'Khóa cũ'],
            TranslationMerge::decode($plan['merged']['lang/vi.json'])
        );
        $this->assertSame(hash('sha256', $plan['merged']['lang/vi.json']), $plan['ops'][0]['new']);
        $this->assertSame([['path' => 'lang/vi.json', 'action' => 'merged', 'old' => hash('sha256', $owner), 'new' => $plan['ops'][0]['new']]],
            array_values(array_filter($plan['entries'], fn (array $e): bool => $e['path'] === 'lang/vi.json')));
    }

    public function test_an_owner_file_stays_when_the_release_adds_nothing_or_did_not_change_it(): void
    {
        $shipped = $this->encodeJson(['Hello' => 'Xin chào']);
        $owner = $this->encodeJson(['Hello' => 'Chào bạn']);

        $sameFile = $this->plan(['lang/vi.json' => $shipped], ['lang/vi.json' => $owner], ['lang/vi.json' => $shipped]);
        $noNewKey = $this->plan(['lang/vi.json' => $shipped], ['lang/vi.json' => $owner], ['lang/vi.json' => $this->encodeJson(['Hello' => 'Xin chào!'])]);

        foreach ([$sameFile, $noNewKey] as $plan) {
            $this->assertSame([], $this->moves($plan));
            $this->assertSame(1, $plan['report']['unchanged']);
            $this->assertSame([], $plan['report']['merged']);
        }
    }

    public function test_a_broken_owner_file_and_owner_changed_php_arrays_are_kept_with_the_new_copy_next_to_them(): void
    {
        $shipped = ['lang/vi.json' => $this->encodeJson(['Hello' => 'Xin chào']), 'lang/vi/auth.php' => "<?php return ['failed' => 'Sai'];"];
        $owner = ['lang/vi.json' => '{"Hello": "Chào bạn",', 'lang/vi/auth.php' => "<?php return ['failed' => 'Sai rồi'];"];
        $release = ['lang/vi.json' => $this->encodeJson(['Hello' => 'Xin chào', 'New' => 'Mới']), 'lang/vi/auth.php' => "<?php return ['failed' => 'Sai!'];"];
        $plan = $this->plan($shipped, $owner, $release);

        $this->assertSame([
            ['put', 'lang/vi.json.avdbcms-1.0.1', 'staging/lang/vi.json'],
            ['put', 'lang/vi/auth.php.avdbcms-1.0.1', 'staging/lang/vi/auth.php'],
        ], $this->moves($plan));
        $this->assertSame(['pre', 'pre'], array_column($plan['ops'], 'phase'));
        $this->assertSame([
            ['path' => 'lang/vi.json', 'copy' => 'lang/vi.json.avdbcms-1.0.1'],
            ['path' => 'lang/vi/auth.php', 'copy' => 'lang/vi/auth.php.avdbcms-1.0.1'],
        ], $plan['report']['kept']);
    }

    /**
     * SwapPlanner for language files only.
     *
     * @param  array<string, string>  $shipped  the installed version's files
     * @param  array<string, string>  $live  the site's files now
     * @param  array<string, string>  $release  the new version's files
     */
    private function plan(array $shipped, array $live, array $release): array
    {
        $hashes = fn (array $files): array => array_map(fn (string $c): string => hash('sha256', $c), $files);
        $new = new Manifest('1.0.1', '8.3.0', '1.0.0', $hashes($release), []);
        $installed = new Manifest('1.0.0', '8.3.0', '1.0.0', $hashes($shipped), []);

        return SwapPlanner::plan(
            $new,
            $installed,
            hash('sha256', 'new manifest'),
            fn (string $path): ?string => $path === Manifest::FILE ? hash('sha256', 'new manifest') : (isset($live[$path]) ? hash('sha256', $live[$path]) : null),
            fn (string $path): ?string => $live[$path] ?? null,
            fn (string $path): ?string => $release[$path] ?? null,
        );
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> */
    private function moves(array $plan): array
    {
        return array_map(fn (array $op): array => [$op['do'], $op['path'], $op['src']], $plan['ops']);
    }

    private function encodeJson(array $data): string
    {
        return TranslationMerge::encode($data);
    }
}
