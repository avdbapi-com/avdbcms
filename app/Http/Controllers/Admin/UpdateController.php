<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPreferences;
use App\Support\AppearanceApplier;
use App\Support\PendingMigrations;
use App\Support\SettingsRevision;
use App\Support\TimeBudget;
use App\Support\Updater\Updater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * "Finish update" on Home and the look choices that follow it. A new version uploaded over an older one works at once;
 * its database changes wait for one click here, and so do the texts it corrected in language files the owner changed
 * (Updater::correctLanguageFiles(): a text still exactly as an older version shipped it gets the new wording, the
 * owner's own texts stay). "Correct the texts" on Tools > Updates posts here with only=texts: the texts alone (a release
 * without a database change has no Finish box on Home, and a Finish that found the update lock taken left them), and
 * the answer goes back to Tools > Updates. The upgrade moves the look to the new theme collection and keeps the previous
 * look for 30 days (revision #0), so the administrator can go back - and forward again.
 *
 * One click works at most SECONDS seconds and stops in time before the host's limit (App\Support\TimeBudget): when
 * database changes are left, it says so and Home keeps its box for the next click.
 */
class UpdateController
{
    /** Seconds of work one click of Finish update may spend (TimeBudget): a host's time limit never ends it half-way. */
    private const SECONDS = 20.0;

    /** The texts are corrected in the same click only with this many seconds left (Home offers them again otherwise). */
    private const TEXTS_SECONDS = 8.0;

    public function finish(Request $request): JsonResponse|RedirectResponse
    {
        // The same deadline as the one-click update's Finish: the migrations stop in time, and a click on Finish update
        // again goes on with the rest ("more").
        TimeBudget::start(Updater::$finishSeconds ?? self::SECONDS, Updater::FINISH_RESERVE);

        try {
            return $this->finishWithin($request);
        } finally {
            TimeBudget::clear();
        }
    }

    private function finishWithin(Request $request): JsonResponse|RedirectResponse
    {
        $textsOnly = $request->input('only') === 'texts';
        $result = $textsOnly
            ? ['ok' => true, 'busy' => false, 'more' => false, 'ran' => [], 'message' => '']
            : PendingMigrations::run();
        $messages = [$result['message']];
        $outcome = ['state' => 'none', 'files' => []];
        $unsaved = [];

        // Tools > Logs > Admin actions (R3-019): the database changes this click made (the texts it corrects write
        // their own rows: Updater::correctLanguageFiles()); none made, nothing written.
        if (! $textsOnly && ($ran = count((array) ($result['ran'] ?? []))) > 0) {
            AuditLogController::log('tools.database.migrated', $ran.' '.($ran === 1 ? 'database change' : 'database changes'));
        }

        // Not while another Finish is still at it (it does the texts too), and not while database changes wait for the
        // next click or too little time is left (Home and Tools > Updates offer "Correct the texts" then). A database
        // step that failed does not hold the texts back: the new files are in place either way.
        if (! $result['busy'] && empty($result['more']) && ($textsOnly || TimeBudget::left() >= self::TEXTS_SECONDS)) {
            try {
                $outcome = $this->updater()->correctLanguageFiles($request->user());
            } catch (Throwable $e) {
                Updater::reportSafely($e);
                $outcome = ['state' => 'busy', 'files' => []];
            }

            // Files whose corrected texts could not be saved (they stay as they were; Tools > Updates still lists them).
            $unsaved = array_map(fn (string $locale): string => 'lang/'.$locale.'.json',
                array_map('strval', array_keys(array_filter($outcome['files'], fn (array $entry): bool => empty($entry['written'])))));

            // A one-click update or undo that runs does the texts in its own Finish: Home's Finish says nothing of them.
            if ($outcome['state'] === 'busy' || ($textsOnly && $outcome['state'] === 'running')) {
                $messages[] = __('Your language files were not checked this time, because another update step was running. Try again in a minute on Tools > Updates.');
            } else {
                $summary = self::textsMessage($outcome['files']);
                $messages[] = $summary;

                if ($unsaved !== []) {
                    $messages[] = __('These language files could not be saved, so their texts stay as they were: :files.', ['files' => \App\Support\AdminText::list($unsaved)]);
                } elseif ($summary === null && $textsOnly) {
                    $messages[] = __('Your language files have no texts left to correct.');
                }
            }
        }

        $ok = $textsOnly ? ! in_array($outcome['state'], ['busy', 'running'], true) && $unsaved === [] : $result['ok'];
        // Back where the click came from: Home (from=home), else Tools > Updates for the texts alone.
        $back = $textsOnly && $request->input('from') !== 'home' ? 'admin.updates.index' : 'admin.index';

        return $this->answer($request, $ok, trim(implode(' ', array_filter($messages))),
            ['ran' => $result['ran'], 'busy' => $result['busy'], 'more' => ! empty($result['more']), 'texts' => $outcome['files'], 'texts_state' => $outcome['state']], $back);
    }

    /**
     * "Texts you never changed in your language files now have the corrected wording of this version (Deutsch: 170
     * texts corrected, your own wording kept for 8 texts this version corrects, 12 texts this version no longer uses
     * removed)." When no file had a text to correct, only texts this version no longer uses that went: "Texts this
     * version no longer uses were removed from your language files; your own texts stay (Deutsch: 2 texts this version
     * no longer uses removed)." (review UP-RV-02). Null when no text was corrected or removed.
     *
     * @param  array<string, array{updated: int, kept: int, removed?: int, written: bool}>  $texts
     */
    private static function textsMessage(array $texts): ?string
    {
        $items = [];
        $corrected = false;

        foreach ($texts as $locale => $entry) {
            // The message speaks of corrected texts and of the texts this version no longer uses that went (the admin log
            // has a row for every file that changed).
            if ((int) ($entry['updated'] ?? 0) <= 0 && (int) ($entry['removed'] ?? 0) <= 0) {
                continue;
            }

            $corrected = $corrected || (int) ($entry['updated'] ?? 0) > 0;
            $name = config('languages.'.$locale.'.label');
            // with the punctuation of the admin's language (judge R2CD-RV-04); "kept": the owner's own wording of texts
            // this version corrects, never all of the owner's texts (judge R3-028)
            $items[] = \App\Support\AdminText::labelled(is_string($name) && $name !== '' ? $name : (string) $locale, \App\Support\AdminText::list(array_filter([
                (int) ($entry['updated'] ?? 0) > 0 ? trans_choice('1 text corrected|:count texts corrected', (int) $entry['updated'], ['count' => number_format((int) $entry['updated'])]) : null,
                (int) ($entry['kept'] ?? 0) > 0 ? trans_choice('your own wording kept for 1 text this version corrects|your own wording kept for :count texts this version corrects', (int) $entry['kept'], ['count' => number_format((int) $entry['kept'])]) : null,
                (int) ($entry['removed'] ?? 0) > 0 ? trans_choice('1 text this version no longer uses removed|:count texts this version no longer uses removed', (int) $entry['removed'], ['count' => number_format((int) $entry['removed'])]) : null,
            ])));
        }

        if ($items === []) {
            return null;
        }

        return $corrected
            ? __('Texts you never changed in your language files now have the corrected wording of this version (:list).', ['list' => \App\Support\AdminText::groups($items)])
            : __('Texts this version no longer uses were removed from your language files; your own texts stay (:list).', ['list' => \App\Support\AdminText::groups($items)]);
    }

    /** The updater of this site (a test binds one with a site root of its own, as on Tools > Updates). */
    private function updater(): Updater
    {
        return app()->bound(Updater::class) ? app(Updater::class) : new Updater();
    }

    /** "Restore my previous look": the appearance settings as they were before the upgrade. */
    public function restoreLook(Request $request): JsonResponse|RedirectResponse
    {
        $snapshot = SettingsRevision::upgradeSnapshot();
        $result = null;

        if ($snapshot !== null) {
            try {
                $result = AppearanceApplier::restoreRevision((int) $snapshot['id'], $request->user()?->id);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // the settings it changed back, as Appearance > history's Undo writes them (R3-019)
        if ($result !== null && ($changed = array_values(array_unique((array) ($result['changed'] ?? [])))) !== []) {
            AuditLogController::log('appearance.restored', \App\Support\AuditDetails::names($changed));
        }

        return $result === null
            ? $this->answer($request, false, __('Your previous look can no longer be restored. It is kept for 30 days after the update.'))
            : $this->answer($request, true, __('Your previous look is back.'));
    }

    /** "Use the new look again" after a restore: the upgrade runs again (it keeps a new copy of the previous look). */
    public function newLook(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $result = AppearanceApplier::upgrade($request->user()?->id);
        } catch (Throwable $e) {
            report($e);

            return $this->answer($request, false, __('The new look could not be applied. Try again.'));
        }

        // the theme of the new look, by its name (R3-019)
        if ($result !== null) {
            AuditLogController::log('appearance.new_look', (string) (\App\Support\ThemeCatalog::theme((string) ($result['theme'] ?? ''))['name'] ?? ($result['theme'] ?? '')));
        }

        return $result === null
            ? $this->answer($request, true, __('Your site already uses the new look.'))
            : $this->answer($request, true, __('Your site uses the new look again.'));
    }

    /**
     * "Keep the new look" (the notice about the moved look goes away; the previous look stays restorable) or, after a
     * restore, "Keep my previous look" (look=previous: the offer of the new look goes away). Once per upgrade snapshot.
     */
    public function keepLook(Request $request): JsonResponse|RedirectResponse
    {
        $snapshot = SettingsRevision::upgradeSnapshot();
        $previous = $request->input('look') === 'previous';

        if ($snapshot !== null || $previous) {
            $notice = ($previous ? 'look-previous-' : 'look-moved-').($snapshot['id'] ?? 0);

            if (AdminPreferences::available($request->user())) {
                AdminPreferences::dismiss($request->user(), $notice);
            } else {
                $request->session()->push('admin.dismissed', $notice);
            }
        }

        return $this->answer($request, true, $previous ? __('Your previous look stays.') : __('The new look stays.'));
    }

    private function answer(Request $request, bool $ok, string $message, array $extra = [], string $back = 'admin.index'): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message] + $extra, $ok ? 200 : 409);
        }

        $redirect = redirect()->route($back);

        return $ok ? $redirect->with('success', $message) : $redirect->withErrors(['update' => $message]);
    }
}
