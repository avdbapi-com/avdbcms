<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\HookTexts;
use Tests\TestCase;

/**
 * The texts of the maintenance hook (F8 11, 13, 17): every key MaintenanceHook reads exists for an update and for an
 * undo, in the site's language (Laravel's translator, while it still runs), placeholders intact, right to left for
 * Arabic.
 */
class HookTextsTest extends TestCase
{
    public function test_every_text_the_hook_reads_is_there_for_both_kinds(): void
    {
        // Every quoted key inside the argument of each ->text(...) call (some pick one of two keys).
        $source = (string) file_get_contents(app_path('Support/Updater/MaintenanceHook.php'));
        $keys = [];
        $at = 0;

        while (($at = strpos($source, '->text(', $at)) !== false) {
            $at += strlen('->text(');
            $depth = 1;

            for ($end = $at; $end < strlen($source) && $depth > 0; $end++) {
                $depth += $source[$end] === '(' ? 1 : ($source[$end] === ')' ? -1 : 0);
            }

            preg_match_all("/'([a-z_]+)'/", substr($source, $at, $end - $at), $found);
            array_push($keys, ...$found[1]);
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        // The keys of the lane contract, plus the help link.
        $this->assertSame(['admin_back', 'admin_text', 'admin_title', 'busy', 'continue', 'failed', 'failed_title', 'finish', 'help', 'installing', 'open_admin',
            'putting_back', 'stuck', 'swapped', 'swapped_text', 'swapped_title', 'undo', 'undone', 'undone_title', 'visitor', 'working'], $keys);

        foreach (['update', 'undo'] as $kind) {
            $texts = HookTexts::for($kind, 'en');

            foreach ($keys as $key) {
                $this->assertIsString($texts[$key] ?? null, $kind.': '.$key);
                $this->assertNotSame('', $texts[$key], $kind.': '.$key);
            }

            $this->assertSame('en', $texts['lang']);
            $this->assertSame('ltr', $texts['dir']);
            $this->assertStringContainsString(':done', $texts['admin_text']);
            $this->assertStringContainsString(':total', $texts['admin_text']);
            $this->assertStringContainsString(':done', $texts['installing']);
            $this->assertStringContainsString(':total', $texts['installing']);
            $this->assertStringContainsString(':left', $texts['putting_back']);
        }
    }

    public function test_the_wording_follows_the_design(): void
    {
        $update = HookTexts::for('update', 'en');
        $undo = HookTexts::for('undo', 'en');

        $this->assertSame('This site is being updated. Please come back in a minute.', $update['visitor']);
        $this->assertSame('The update stopped before it finished.', $update['admin_title']);
        $this->assertSame('Finish the update', $update['finish']);
        $this->assertSame('Undo the update', $update['undo']);
        $this->assertSame('The update could not be installed. Your site was put back as it was. Nothing in the database was changed.', $update['failed']);
        $this->assertSame('Finish the undo', $undo['finish']);
        $this->assertSame('Cancel the undo', $undo['undo']);
        $this->assertSame($update['visitor'], $undo['visitor']);
    }

    public function test_the_texts_come_in_the_site_language(): void
    {
        $vi = HookTexts::for('update', 'vi');

        $this->assertSame('vi', $vi['lang']);
        $this->assertSame(__('Continue', [], 'vi'), $vi['continue']);
        $this->assertNotSame('Continue', $vi['continue'], 'vi.json translates "Continue"');
    }

    public function test_arabic_is_right_to_left_and_an_unknown_language_falls_back(): void
    {
        $this->assertSame('rtl', HookTexts::for('update', 'ar')['dir']);
        $this->assertSame('ar', HookTexts::for('update', 'ar')['lang']);

        $unknown = HookTexts::for('update', 'zz');
        $this->assertSame(config('app.fallback_locale', 'en'), $unknown['lang']);
        $this->assertSame('ltr', $unknown['dir']);
        $this->assertSame(config('app.fallback_locale', 'en'), HookTexts::for('update', '../../etc')['lang']);

        config(['site.rtl_support' => true]);
        $this->assertSame('rtl', HookTexts::for('update', 'en')['dir'], 'the site-wide right-to-left switch');
    }
}
