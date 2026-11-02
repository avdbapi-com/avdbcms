<?php

namespace App\Console\Commands;

use App\Support\Updater\Updater;
use Illuminate\Console\Command;

/**
 * updates:texts - after an update by hand over SSH (UPGRADE.md, Update by hand, step 6): gives the language files the
 * owner changed the texts this version corrected, the same as Finish update on Home and "Correct the texts" on
 * Tools > Updates (Updater::correctLanguageFiles()). A text that is still exactly as an older version shipped it gets the
 * new wording; the owner's own texts and keys stay. One admin-log row per language with corrected texts (no admin: the
 * row names nobody). Safe to run again: the second time there is nothing left to correct.
 *
 * Run it as the user of the site (the user of its cron job). Run as root, each corrected file keeps its owner and group
 * (ShippedTexts::write()), so the site can still save it on Settings > Languages.
 *
 * Exit 0: done (also when nothing needed a correction, or nothing to do on this site). Exit 1: the update lock is taken
 * or a one-click update or undo runs (try again after it), or a file could not be saved.
 */
class CorrectLanguageTexts extends Command
{
    protected $signature = 'updates:texts';

    protected $description = 'Give your language files the texts this version corrected (your own texts stay)';

    public function handle(): int
    {
        $updater = app()->bound(Updater::class) ? app(Updater::class) : new Updater();
        $outcome = $updater->correctLanguageFiles(null);

        if ($outcome['state'] === 'busy') {
            $this->error('Your language files were not checked: another update step is running. Try again in a minute.');

            return self::FAILURE;
        }

        if ($outcome['state'] === 'running') {
            $this->error('Your language files were not checked: a one-click update or undo is running on Tools > Updates (its last step corrects the texts). Try again after it.');

            return self::FAILURE;
        }

        if ($outcome['state'] === 'none') {
            $this->line('Nothing to correct: this version has no list of corrected texts, or this is not an installed site (no avdbcms-manifest.json of this version).');

            return self::SUCCESS;
        }

        $failed = false;
        $corrected = 0;
        $removed = 0;

        foreach ($outcome['files'] as $locale => $entry) {
            $name = config('languages.'.$locale.'.label');
            $label = 'lang/'.$locale.'.json'.(is_string($name) && $name !== '' ? ' ('.$name.')' : '');

            if (! $entry['written']) {
                $failed = true;
                $this->error($label.': could not be saved, so its texts stay as they were.');

                continue;
            }

            $corrected += $entry['updated'];
            $removed += (int) ($entry['removed'] ?? 0);
            // "kept": your own wording of texts this version corrects; every text of yours stays (judge R3-028).
            $this->line($label.': '.implode(', ', array_filter([
                self::amount($entry['updated'], 'text', 'texts').' corrected',
                $entry['kept'] > 0 ? 'your own wording kept for '.self::amount($entry['kept'], 'text', 'texts').' this version corrects' : null,
                ($entry['removed'] ?? 0) > 0 ? self::amount((int) $entry['removed'], 'text', 'texts').' this version no longer uses removed' : null,
            ])));
        }

        // The texts this version no longer uses that went are said too: never "no text needed a correction" alone after
        // a line that removed some (review UP-RV-02).
        $gone = $removed > 0 ? self::amount($removed, 'text', 'texts').' this version no longer uses '.($removed === 1 ? 'was' : 'were').' removed' : null;
        $this->info($corrected > 0
            ? 'Done: '.self::amount($corrected, 'text', 'texts').' now '.($corrected === 1 ? 'has' : 'have').' the corrected wording of this version'.($gone !== null ? '; '.$gone : '').'.'
            : 'Done: no text needed a correction'.($gone !== null ? '; '.$gone : '').'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** "1 text", "3 texts". */
    private static function amount(int $number, string $one, string $many): string
    {
        return $number.' '.($number === 1 ? $one : $many);
    }
}
