<?php

namespace App\Support\Updater;

/**
 * The texts of the maintenance hook (storage/framework/maintenance.php): the "being updated" page for visitors, the
 * Finish/Undo page of the administrator who started the swap, and the messages of its JSON answers. The hook runs
 * without Laravel, so they are translated here, while Laravel still runs, into the site's default language and written
 * into the hook with it (F8 17). Placeholders (:done, :total, :left) stay as they are: the hook fills them in.
 * Every text is a literal __() call, so scripts/i18n_check.php finds it.
 *
 * $kind "update" is a one-click update, "undo" is Undo this update (the same swap, the other way).
 */
final class HookTexts
{
    /** Languages written right to left (config/languages.php marks them with 'rtl' too). */
    private const RTL = ['ar', 'fa', 'he', 'ur'];

    /**
     * @return array<string, string> every key MaintenanceHook reads, plus lang (the locale code) and dir (ltr | rtl)
     */
    public static function for(string $kind, string $locale): array
    {
        $locale = self::locale($locale);

        $shared = [
            'visitor' => __('This site is being updated. Please come back in a minute.', [], $locale),
            'busy' => __('Another step of the update is running. Wait a moment.', [], $locale),
            'continue' => __('Continue', [], $locale),
            'working' => __('Working...', [], $locale),
            'open_admin' => __('Open the Updates page', [], $locale),
            'stuck' => __('AVDBCMS could not put all your files back, so your site stays in maintenance mode. Follow the steps for an update that stopped on the AVDBCMS help site.', [], $locale),
            'help' => __('Help: an update that stopped', [], $locale),
        ];

        $texts = $kind === 'undo' ? [
            'admin_title' => __('The undo stopped before it finished.', [], $locale),
            'admin_text' => __(':done of :total files of the earlier version are back. Finish the undo, or cancel it to keep the new version.', [], $locale),
            'admin_back' => __('Something went wrong after :done of :total files had moved, and AVDBCMS is putting the files of the new version back. Click Continue to finish that.', [], $locale),
            'finish' => __('Finish the undo', [], $locale),
            'undo' => __('Cancel the undo', [], $locale),
            'installing' => __('Putting the earlier files back: :done of :total', [], $locale),
            'putting_back' => __('Putting the files of the new version back. Files left: :left', [], $locale),
            'swapped' => __('The earlier files are back.', [], $locale),
            'swapped_title' => __('The earlier files are back', [], $locale),
            'swapped_text' => __('One step is left: open the Updates page of the admin to finish the undo.', [], $locale),
            'failed' => __('The undo could not finish. Your site keeps the new version. Nothing in the database was changed.', [], $locale),
            'failed_title' => __('The undo did not finish', [], $locale),
            'undone' => __('The undo was cancelled. Your site keeps the new version.', [], $locale),
            'undone_title' => __('The undo was cancelled', [], $locale),
        ] : [
            'admin_title' => __('The update stopped before it finished.', [], $locale),
            'admin_text' => __(':done of :total files of the new version are in place. Finish the update, or undo it to put your files back as they were.', [], $locale),
            'admin_back' => __('Something went wrong after :done of :total files had moved, and AVDBCMS is putting your files back. Click Continue to finish that.', [], $locale),
            'finish' => __('Finish the update', [], $locale),
            'undo' => __('Undo the update', [], $locale),
            'installing' => __('Installing the new files: :done of :total', [], $locale),
            'putting_back' => __('Putting your files back. Files left: :left', [], $locale),
            'swapped' => __('The new files are in place.', [], $locale),
            'swapped_title' => __('The new files are in place', [], $locale),
            'swapped_text' => __('One step is left: open the Updates page of the admin to finish the update.', [], $locale),
            'failed' => __('The update could not be installed. Your site was put back as it was. Nothing in the database was changed.', [], $locale),
            'failed_title' => __('The update was not installed', [], $locale),
            'undone' => __('The update was stopped. Your site was put back as it was. Nothing in the database was changed.', [], $locale),
            'undone_title' => __('The update was stopped', [], $locale),
        ];

        return array_map('strval', $shared + $texts) + ['lang' => $locale, 'dir' => self::direction($locale)];
    }

    /** A locale code the site has (lang/<code>.json or config/languages.php), else the fallback language. */
    private static function locale(string $locale): string
    {
        $locale = trim($locale);

        if (preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/', $locale) === 1
            && (is_file(lang_path($locale.'.json')) || array_key_exists($locale, (array) config('languages', [])))) {
            return $locale;
        }

        return (string) config('app.fallback_locale', 'en');
    }

    /** 'rtl' for right-to-left languages, like AdminTheme::direction() decides it for the admin. */
    private static function direction(string $locale): string
    {
        $base = strtolower(explode('-', str_replace('_', '-', $locale))[0]);
        $info = (array) (config('languages.'.$locale) ?? config('languages.'.$base) ?? []);
        $rtl = array_key_exists('rtl', $info) ? filter_var($info['rtl'], FILTER_VALIDATE_BOOLEAN) : in_array($base, self::RTL, true);

        return $rtl || filter_var(config('site.rtl_support', false), FILTER_VALIDATE_BOOLEAN) ? 'rtl' : 'ltr';
    }
}
