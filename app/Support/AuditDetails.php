<?php

namespace App\Support;

use Closure;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * The details of a Tools > Logs > Admin actions row in the admin's language (R2A-054), and the way the code writes them
 * (R2A-020).
 *
 * Every row keeps its details as they were written: in English, the way the admin's own records are kept (the 1.2.2 and
 * 1.2.3 update migrations, the updater, the scheduled tasks and background jobs tools ...). Where the wording of a
 * detail is known, it is shown translated, each value it carries (a version, a file, a command, a member's name, a
 * video's title) set apart so it reads in its own direction on a right-to-left page (<bdi>; codes, addresses and
 * versions left to right). Details nobody wrote a template for (an error message, a command's own output, a line
 * written in the admin's language already) are shown as they were written. The same text serves the Scheduled tasks
 * and Background jobs activity cards (R2A-051).
 *
 * The rows written for what administrators change (R2A-020) mostly carry names, ids and setting keys only, so they read
 * the same in every language; their few words (a member blocked "with their videos", "and 12 more") have templates here.
 */
final class AuditDetails
{
    /** A list of names in one row: at most this many, then "and N more". */
    public const LIST_MAX = 25;

    /** A title or a name in a row: at most this many characters. */
    public const NAME_MAX = 80;

    /**
     * The sentences App\Support\LegacyRowTimes::auditDetails() writes for a column whose rows from before 1.2.3 needed
     * no change (lane CL, R2A-001), by row kind: its own translation keys. LegacyRowTimes writes them with
     * trans_choice(..., 'en') as literal keys, and their translations come with them (lane CL's i18n.json); here they
     * are the same text, not a second literal key for the translation tools.
     */
    private const ROW_TIMES_ALREADY = [
        'views' => ':version: the 1 view in History recorded before the update already showed its time in the site time zone (:zone), nothing was changed|:version: the :count views in History recorded before the update already showed their times in the site time zone (:zone), nothing was changed',
        'subscriptions' => ':version: the 1 subscription recorded before the update already showed its time in the site time zone (:zone), nothing was changed|:version: the :count subscriptions recorded before the update already showed their times in the site time zone (:zone), nothing was changed',
        'saved_playlists' => ':version: the 1 saved playlist recorded before the update already showed its time in the site time zone (:zone), nothing was changed|:version: the :count saved playlists recorded before the update already showed their times in the site time zone (:zone), nothing was changed',
        // judge R3-005 / R3-035 (lane DB): the likes' activities and the playlists' rows
        'likes' => ':version: the 1 like or dislike in Your activity recorded before the update already showed its time in the site time zone (:zone), nothing was changed|:version: the :count likes and dislikes in Your activity recorded before the update already showed their times in the site time zone (:zone), nothing was changed',
        'playlist_videos' => ':version: the 1 video added to a playlist recorded before the update already showed its time in the site time zone (:zone), nothing was changed|:version: the :count videos added to playlists recorded before the update already showed their times in the site time zone (:zone), nothing was changed',
    ];

    private const OPEN = "\u{E000}";

    private const CLOSE = "\u{E001}";

    /** @var list<array{0: list<string>, 1: string, 2: Closure}>|null */
    private static ?array $rules = null;

    // ---------------------------------------------------------------- how the code writes the details

    /** "alice (#12)": a member as the rows name them (the Members search finds the name). */
    public static function member(object $user): string
    {
        $name = trim((string) ($user->username ?? ''));
        $id = (int) ($user->id ?? 0);

        return ($name !== '' ? Str::limit($name, self::NAME_MAX) : '?').' (#'.$id.')';
    }

    /**
     * "My video (#45)": a video, a playlist or anything else with a title; the title as stored (a video's own, not its
     * translation for the language the administrator happens to use).
     */
    public static function titled(object $item, string $field = 'title'): string
    {
        $raw = $item instanceof \Illuminate\Database\Eloquent\Model ? ($item->getAttributes()[$field] ?? null) : null;
        $title = trim(preg_replace('/\s+/u', ' ', (string) ($raw ?? ($item->{$field} ?? ''))) ?? '');

        return ($title !== '' ? Str::limit($title, self::NAME_MAX) : '?').' (#'.(int) ($item->id ?? 0).')';
    }

    /**
     * "alice (#12), bob (#13) and 4 more": the first LIST_MAX names of a list, then how many more ($total: how many
     * the action changed when that is more than the names given).
     *
     * @param  iterable<int, string>  $names
     */
    public static function names(iterable $names, ?int $total = null): string
    {
        $all = [];

        foreach ($names as $name) {
            $all[] = (string) $name;
        }

        $shown = array_slice($all, 0, self::LIST_MAX);
        $more = max(0, max($total ?? 0, count($all)) - count($shown));

        return implode(', ', $shown).($more > 0 ? ' and '.$more.' more' : '');
    }

    /**
     * "#12, #13 and 4 more": ids of rows that have no name (comments, reports, entries of a log). A long id (a job's
     * UUID) is cut to its first 8 characters.
     *
     * @param  iterable<int, int|string>  $ids
     */
    public static function ids(iterable $ids, ?int $total = null): string
    {
        $names = [];

        foreach ($ids as $id) {
            $id = (string) $id;
            $names[] = '#'.(strlen($id) > 12 ? substr($id, 0, 8) : $id);
        }

        return self::names($names, $total);
    }

    // ---------------------------------------------------------------- how a row shows them

    /** The details as HTML for the lists: translated when their wording is known, the values set apart; else as written. */
    public static function html(string $action, ?string $details): HtmlString
    {
        $details = trim((string) $details);

        if ($details === '') {
            return new HtmlString('');
        }

        $shown = self::resolve($action, $details);

        if ($shown === null) {
            return new HtmlString(e($details));
        }

        [$text, $values] = $shown;
        $html = e($text);

        foreach ($values as $index => [$value, $kind]) {
            $html = str_replace(e(self::OPEN.$index.self::CLOSE), match ($kind) {
                'ltr' => '<bdi dir="ltr">'.e($value).'</bdi>',
                'auto' => '<bdi>'.e($value).'</bdi>',
                default => e($value),
            }, $html);
        }

        return new HtmlString($html);
    }

    /** The same as plain text (a summary of a long detail, a tooltip). */
    public static function text(string $action, ?string $details): string
    {
        $details = trim((string) $details);

        if ($details === '') {
            return '';
        }

        $shown = self::resolve($action, $details);

        if ($shown === null) {
            return $details;
        }

        [$text, $values] = $shown;

        foreach ($values as $index => [$value]) {
            $text = str_replace(self::OPEN.$index.self::CLOSE, $value, $text);
        }

        return $text;
    }

    /** Whether the wording of this row's details is known (a template translates it). */
    public static function known(string $action, ?string $details): bool
    {
        return trim((string) $details) !== '' && self::resolve($action, trim((string) $details)) !== null;
    }

    /**
     * The translated text with a token for each value, and the values with how they are set apart; null when no
     * template knows the wording.
     *
     * @return array{0: string, 1: list<array{0: string, 1: string}>}|null
     */
    private static function resolve(string $action, string $details): ?array
    {
        foreach (self::rules() as [$actions, $pattern, $build]) {
            if (! in_array($action, $actions, true) || preg_match($pattern, $details, $match) !== 1) {
                continue;
            }

            $values = [];
            $value = static function (string $text, string $kind = 'ltr') use (&$values): string {
                $values[] = [$text, $kind];

                return self::OPEN.(count($values) - 1).self::CLOSE;
            };

            try {
                $text = $build($match, $value);
            } catch (Throwable) {
                return null;
            }

            return is_string($text) && $text !== '' ? [$text, $values] : null;
        }

        return null;
    }

    /**
     * A database backup as Tools > Backups lists it (with its random part, DatabaseBackup::listedName()), or the name as
     * written when that file is gone; a view-only account (Tools > Backups is closed to it) gets the name as written.
     */
    private static function backupName(string $shown): string
    {
        try {
            if (\App\Http\Middleware\ViewOnlyDemo::isViewOnlyUser(auth()->user())) {
                return $shown;
            }

            return DatabaseBackup::listedName($shown) ?? $shown;
        } catch (Throwable) {
            return $shown;
        }
    }

    /** "1,204" -> 1204 */
    private static function number(string $digits): int
    {
        return (int) str_replace([',', ' ', "\u{202F}", "\u{00A0}"], '', $digits);
    }

    /** "24 hours" in the admin's language. */
    private static function hours(int $hours): string
    {
        return trans_choice('{1} :count hour|[2,*] :count hours', $hours);
    }

    /** "30 days" in the admin's language. */
    private static function days(int $days): string
    {
        return trans_choice('{1} :count day|[2,*] :count days', $days);
    }

    /**
     * Several lines of a command's output, each by its own template, joined with "; " (a line no template knows makes
     * the whole detail unknown: it is then shown as stored).
     *
     * @param  array<string, Closure(array<string, string>): string>  $templates  pattern => what to show
     */
    private static function lines(string $text, array $templates): string
    {
        $shown = [];

        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            foreach ($templates as $pattern => $build) {
                if (preg_match($pattern, $line, $match) === 1) {
                    $shown[] = $build($match);

                    continue 2;
                }
            }

            throw new \RuntimeException('unknown line');
        }

        return self::joinGroups($shown);
    }

    /**
     * A list of the update's English words in the admin's language ("billing line, cancel line"): each word through
     * $translate, joined the way the admin's language joins a list.
     *
     * @param  Closure(string): string  $translate
     */
    private static function words(string $list, Closure $translate, string $separator = ', '): string
    {
        $parts = array_values(array_filter(array_map('trim', explode($separator, $list)), fn (string $part): bool => $part !== ''));

        return self::join(array_map($translate, $parts));
    }

    /**
     * Parts of one kind joined the way the admin's language joins a list ("a, b, c"; Arabic "a، b"; Chinese "a、b"):
     * the punctuation of the language's script (judge R2CD-RV-04: Latin commas inside Arabic details; AdminText::list()).
     *
     * @param  iterable<int, string>  $parts
     */
    private static function join(iterable $parts): string
    {
        return AdminText::list($parts);
    }

    /** Groups of a list, or the parts of a line ("a; b"; Arabic "a؛ b"; Chinese "a；b"; AdminText::groups()). */
    private static function joinGroups(iterable $parts): string
    {
        return AdminText::groups($parts);
    }

    /** "label: value" the way the admin's language writes it (Chinese "label：value"; AdminText::labelled()). */
    private static function labelled(string $label, string $value): string
    {
        return AdminText::labelled($label, $value);
    }

    /**
     * A list of names with its "and N more" in the admin's language; the names stay as they are ($value: set apart).
     *
     * @param  Closure(string, string=): string  $value
     */
    private static function moreList(string $list, Closure $value, string $kind = 'auto'): string
    {
        if (preg_match('/^(?<names>.+) and (?<more>\d+) more$/su', $list, $match) === 1) {
            return trans_choice(':list and 1 more|:list and :count more', (int) $match['more'], ['list' => $value($match['names'], $kind)]);
        }

        return $value($list, $kind);
    }

    /**
     * The settings a save changed, in the admin's language, by the card that holds them on their page ("Email server:
     * Password, Port; Site: Site name"), as the settings catalogue names them; a key the catalogue does not know (an
     * older setting of the old settings page) is shown as it is, as code.
     */
    private static function settingNames(string $keys, Closure $value): string
    {
        $more = null;

        if (preg_match('/^(?<keys>.+) and (?<more>\d+) more$/su', $keys, $match) === 1) {
            [$keys, $more] = [$match['keys'], (int) $match['more']];
        }

        $cards = [];
        $others = [];
        $pages = (array) config('admin_settings.pages', []);

        foreach (array_filter(array_map('trim', explode(',', $keys))) as $key) {
            $setting = config('admin_settings.settings.'.$key);
            $label = is_array($setting) ? ($setting['label'] ?? null) : null;

            if (! is_string($label) || $label === '') {
                $others[] = $value($key);

                continue;
            }

            $card = $pages[$setting['page'] ?? '']['cards'][$setting['card'] ?? '']['title'] ?? null;
            $cards[is_string($card) && $card !== '' ? __($card) : ''][] = __($label);
        }

        $parts = [];

        foreach ($cards as $card => $labels) {
            $parts[] = $card !== '' ? self::labelled($card, self::join($labels)) : self::join($labels);
        }

        if ($others !== []) {
            $parts[] = self::join($others);
        }

        $list = self::joinGroups($parts);

        return $more !== null ? trans_choice(':list and 1 more|:list and :count more', $more, ['list' => $list]) : $list;
    }

    /**
     * Why a protected job was kept (Tools > Background jobs' refused delete, R3-070): stored in English since 1.2.3
     * (QueueJobInspector's risk_key) and shown in the reader's language; a reason an earlier version stored in the
     * acting admin's language is shown as it was written, set apart (it may read in another direction).
     *
     * @param  Closure(string, string=): string  $value
     */
    private static function jobReason(string $reason, Closure $value): string
    {
        $reason = trim($reason);

        return in_array($reason, QueueJobInspector::riskKeys(), true) ? $value(__($reason), 'plain') : $value($reason, 'auto');
    }

    /**
     * The fields a save changed ("name, address"), each by the name its form shows ($name; null for a field the form
     * does not have: the whole detail is then shown as it was written), joined the way the admin's language joins a list.
     *
     * @param  Closure(string): ?string  $name
     */
    private static function fieldList(string $fields, Closure $name): string
    {
        return self::words($fields, static fn (string $field): string => $name($field) ?? throw new \RuntimeException('unknown field'));
    }

    /** A field of a member or a video the admin changed, in the admin's language. */
    private static function fieldName(string $field): string
    {
        return match ($field) {
            'username' => __('Username'),
            'email' => __('Email'),
            'password' => __('Password'),
            'description' => __('Description'),
            'country' => __('Country'),
            'title' => __('Title'),
            'category' => __('Category'),
            'visibility' => __('Visibility'),
            'language' => __('Language'),
            'ads' => __('Show ads'),
            'comments' => __('Allow comments'),
            'likes' => __('Show likes'),
            'short' => __('Short'),
            default => $field,
        };
    }

    /**
     * The templates: [the actions, the stored English as a pattern, what to show]. Each translation key is written out
     * here (scripts/i18n collects the literal keys of __() / trans_choice()).
     *
     * @return list<array{0: list<string>, 1: string, 2: Closure}>
     */
    private static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $v = '(?<v>\d+\.\d+\.\d+)';

        return self::$rules = [
            // ---- the update migrations of 1.2.2 and 1.2.3 (R2A-054)
            [['update.plan_limits_defaults'], '/^'.$v.': package upload limits set to the defaults: (?<parts>.+)$/su', static function (array $m, Closure $value): string {
                $plans = [];

                foreach (explode('; ', $m['parts']) as $part) {
                    if (preg_match('/^(?<plan>free|premium) (?<limits>.+)$/u', $part, $p) !== 1) {
                        throw new \RuntimeException('unknown plan part');
                    }

                    $limits = self::words($p['limits'], static fn (string $limit): string => match (true) {
                        preg_match('/^(\d+) videos?$/', $limit, $n) === 1 => trans_choice(':count video|:count videos', (int) $n[1]),
                        preg_match('/^(.+) per file$/', $limit, $n) === 1 => __(':size per file', ['size' => $value($n[1])]),
                        preg_match('/^(.+) in all$/', $limit, $n) === 1 => __(':size in all', ['size' => $value($n[1])]),
                        default => throw new \RuntimeException('unknown limit'),
                    });
                    $plans[] = self::labelled($p['plan'] === 'free' ? __('Free plan') : __('Premium plan'), $limits);
                }

                return __(':version: the upload limits of the package were set to the defaults: :limits', ['version' => $value($m['v']), 'limits' => self::joinGroups($plans)]);
            }],
            [['security.known_passwords_reset'], '/^'.$v.': (?<n>\d+) members? (?:had|has) a password others know and got a random one: (?<names>.+)$/su', static fn (array $m, Closure $value): string => trans_choice(
                ':version: 1 member had a password others know and got a random one: :names|:version: :count members had a password others know and got a random one: :names',
                (int) $m['n'], ['version' => $value($m['v']), 'names' => $value($m['names'], 'auto')])],
            [['update.homepage_feed_list_ad'], '/^Money > Ads: the homepage feed got the ad code and shape of the Classic grid homepage list \(New videos\)$/u',
                static fn (): string => __('Money > Ads: the homepage feed got the ad code and shape of the Classic grid homepage list (New videos)')],
            [['update.publish_dates'], '/^'.$v.': (?<n>\d+) scheduled videos? got (?:its|their) planned time as (?:its|their) publish date$/u', static fn (array $m, Closure $value): string => trans_choice(
                ':version: 1 scheduled video got its planned time as its publish date|:version: :count scheduled videos got their planned time as their publish date',
                (int) $m['n'], ['version' => $value($m['v'])])],
            [['update.never_published'], '/^'.$v.': (?<n>\d+) public videos? (?:was|were) never published \(no publish date\): Newest lists (?:it|them) last\. See Content > All videos\.$/u', static fn (array $m, Closure $value): string => trans_choice(
                ':version: 1 public video was never published (no publish date): Newest lists it last. See Content > All videos.|:version: :count public videos were never published (no publish date): Newest lists them last. See Content > All videos.',
                (int) $m['n'], ['version' => $value($m['v'])])],
            [['update.recount'], '/^'.$v.': likes, dislikes and comments of every video counted again: (?<checked>[\d,]+) videos? checked, (?<fixed>[\d,]+) put right$/u', static fn (array $m, Closure $value): string => __(
                ':version: likes, dislikes and comments of every video counted again (videos checked: :checked, counts put right: :corrected)',
                ['version' => $value($m['v']), 'checked' => $value(number_format(self::number($m['checked']))), 'corrected' => $value(number_format(self::number($m['fixed'])))])],
            [['update.recount'], '/^'.$v.': likes, dislikes and comments counted again for (?<checked>[\d,]+) videos? so far \((?<fixed>[\d,]+) put right\); the cron job goes on$/u', static fn (array $m, Closure $value): string => __(
                ':version: likes, dislikes and comments counted again so far (videos checked: :checked, counts put right: :corrected); the scheduled tasks count the rest',
                ['version' => $value($m['v']), 'checked' => $value(number_format(self::number($m['checked']))), 'corrected' => $value(number_format(self::number($m['fixed'])))])],
            [['update.telegram_links'], '/^'.$v.': new AVDBAPI Telegram group (?<link>\S+) in (?<n>\d+) settings?$/u', static fn (array $m, Closure $value): string => trans_choice(
                ':version: new AVDBAPI Telegram group :link in 1 setting|:version: new AVDBAPI Telegram group :link in :count settings',
                (int) $m['n'], ['version' => $value($m['v']), 'link' => $value($m['link'])])],
            [['update.telegram_links'], '/^'.$v.': new AVDBAPI Telegram group (?<link>\S+) in: (?<names>.+?)(?: and (?<more>\d+) more)?$/u', static function (array $m, Closure $value): string {
                $names = self::words($m['names'], static fn (string $name): string => match ($name) {
                    'Telegram channel' => __('Telegram channel'),
                    'footer Support links' => __('footer Support links'),
                    'footer Support links (group + support)' => __('footer Support links (group + support)'),
                    default => __($name),
                });

                if (($m['more'] ?? '') !== '') {
                    $names = trans_choice(':list and 1 more|:list and :count more', (int) $m['more'], ['list' => $names]);
                }

                return __(':version: new AVDBAPI Telegram group :link in: :settings', ['version' => $value($m['v']), 'link' => $value($m['link']), 'settings' => $names]);
            }],
            [['update.removed_files'], '/^'.$v.': removed (?<file>\S+), the outdated database dump of (?<a>\d+\.\d+\.\d+) to (?<b>\d+\.\d+\.\d+)$/u', static fn (array $m, Closure $value): string => __(
                ':version: removed :file, the outdated database dump of :first to :last',
                ['version' => $value($m['v']), 'file' => $value($m['file']), 'first' => $value($m['a']), 'last' => $value($m['b'])])],
            [['update.removed_files'], '/^'.$v.': removed the shipped development Docker files of the upstream project \((?<files>[^)]+)\)$/u', static fn (array $m, Closure $value): string => __(
                ':version: removed the development Docker files of the upstream project that came with the package (:files)',
                ['version' => $value($m['v']), 'files' => $value($m['files'])])],
            // ... and since review UP-RV-03, when the owner had changed some of them (they stay, and docker/ with them)
            [['update.removed_files'], '/^'.$v.': removed (?<n>\d+) development Docker files? of the upstream project \((?<files>[^)]+)\); (?<kept>\d+) you changed (?:was|were) kept$/u', static fn (array $m, Closure $value): string => self::joinGroups([
                trans_choice(':version: removed 1 development Docker file of the upstream project that came with the package (:files)|:version: removed :count development Docker files of the upstream project that came with the package (:files)',
                    (int) $m['n'], ['version' => $value($m['v']), 'files' => $value($m['files'])]),
                trans_choice('1 file you changed was kept|:count files you changed were kept', (int) $m['kept']),
            ])],
            // 2026_10_29_100000_remove_unused_sign_in_and_error_pictures (judge R3-024)
            [['update.removed_files'], '/^'.$v.': removed (?<n>\d+) unused pictures? of the sign-in and error pages \((?<folders>[^)]+)\)(?:; (?<kept>\d+) you changed (?:was|were) kept)?$/u', static function (array $m, Closure $value): string {
                $text = trans_choice(':version: removed 1 unused picture of the sign-in and error pages (:folders)|:version: removed :count unused pictures of the sign-in and error pages (:folders)',
                    (int) $m['n'], ['version' => $value($m['v']), 'folders' => $value($m['folders'])]);

                return ($m['kept'] ?? '') !== ''
                    ? self::joinGroups([$text, trans_choice('1 picture you changed was kept|:count pictures you changed were kept', (int) $m['kept'])])
                    : $text;
            }],
            [['update.sign_up_limit_default'], '/^'.$v.': "Sign-ups allowed per visitor" was the package value \(1 a minute\): now the default, 3 in 10 minutes$/u', static fn (array $m, Closure $value): string => __(
                ':version: ":setting" was the package value (1 a minute): now the default, 3 in 10 minutes',
                ['version' => $value($m['v']), 'setting' => __('Sign-ups allowed per visitor')])],
            [['update.premium_page_texts'], '/^'.$v.': Premium page texts set to the new wording: (?<texts>[^…]+)$/u', static fn (array $m, Closure $value): string => __(
                ':version: Premium page texts set to the new wording: :texts',
                ['version' => $value($m['v']), 'texts' => self::words($m['texts'], static fn (string $text): string => match ($text) {
                    'billing line' => __('billing line'),
                    'cancel line' => __('cancel line'),
                    'savings note' => __('savings note'),
                    default => throw new \RuntimeException('unknown text'),
                })])],
            // App\Support\LegacyRowTimes (R1-011): the times of rows written before 1.2.3; anything the line says after
            // its sentence (the time zone) is shown as it is.
            [['update.row_times'], '/^'.$v.': (?<n>[\d,]+) (?<what>views? in History|subscriptions?|saved playlists?|likes and dislikes in Your activity|like or dislike in Your activity|videos? added to (?:a )?playlists?) recorded before the update now (?:shows its time|show their times) in the site time zone(?<rest>.*)$/su', static function (array $m, Closure $value): string {
                $count = self::number($m['n']);
                $replace = ['version' => $value($m['v']), 'count' => number_format($count)];
                $text = match (true) {
                    str_starts_with($m['what'], 'view') => trans_choice(':version: 1 view in History recorded before the update now shows its time in the site time zone|:version: :count views in History recorded before the update now show their times in the site time zone', $count, $replace),
                    str_starts_with($m['what'], 'subscription') => trans_choice(':version: 1 subscription recorded before the update now shows its time in the site time zone|:version: :count subscriptions recorded before the update now show their times in the site time zone', $count, $replace),
                    str_starts_with($m['what'], 'like') => trans_choice(':version: 1 like or dislike in Your activity recorded before the update now shows its time in the site time zone|:version: :count likes and dislikes in Your activity recorded before the update now show their times in the site time zone', $count, $replace),
                    str_starts_with($m['what'], 'video') => trans_choice(':version: 1 video added to a playlist recorded before the update now shows its time in the site time zone|:version: :count videos added to playlists recorded before the update now show their times in the site time zone', $count, $replace),
                    default => trans_choice(':version: 1 saved playlist recorded before the update now shows its time in the site time zone|:version: :count saved playlists recorded before the update now show their times in the site time zone', $count, $replace),
                };

                return trim($m['rest']) === '' ? $text : $text.' '.$value(trim($m['rest']));
            }],
            // ... and since R2A-001 (lane CL) the line of a column whose old rows needed no change, with the zone, in the
            // words and with the translations of LegacyRowTimes::auditDetails() (ROW_TIMES_ALREADY; judge R2CD-RV-07)
            [['update.row_times'], '/^'.$v.': the (?<n>[\d,]+) (?<what>views? in History|subscriptions?|saved playlists?|likes and dislikes in Your activity|like or dislike in Your activity|videos? added to (?:a )?playlists?) recorded before the update already showed (?:its time|their times) in the site time zone \((?<zone>[^()]+)\), nothing was changed$/su', static function (array $m, Closure $value): string {
                $count = self::number($m['n']);
                $kind = match (true) {
                    str_starts_with($m['what'], 'view') => 'views',
                    str_starts_with($m['what'], 'subscription') => 'subscriptions',
                    str_starts_with($m['what'], 'like') => 'likes',
                    str_starts_with($m['what'], 'video') => 'playlist_videos',
                    default => 'saved_playlists',
                };

                return trans_choice(self::ROW_TIMES_ALREADY[$kind], $count, ['version' => $value($m['v']), 'count' => number_format($count), 'zone' => $value($m['zone'])]);
            }],
            [['update.upload18_key'], '/^The Upload18 key that came with AVDBCMS 1\.2\.0 to 1\.2\.2 no longer works: the site now uses the new shared AVDBCMS key \(Upload \+ Read only\)\. For your own site, create your own key in Upload18 and paste it in Settings > Videos & uploads\.$/u',
                static fn (): string => __('The Upload18 key that came with AVDBCMS 1.2.0 to 1.2.2 no longer works: the site now uses the new shared AVDBCMS key (Upload + Read only). For your own site, create your own key in Upload18 and paste it in Settings > Videos & uploads.')],
            [['security.admin_ip_lock.off'], '/^Turned off from the command line: (?<n>\d+) (?:entry|entries) removed\.$/u', static fn (array $m): string => trans_choice(
                'Turned off from the command line: 1 address removed.|Turned off from the command line: :count addresses removed.', (int) $m['n'])],
            // App\Support\BlockedWordsPass::finish() (R3-069): the counts as values, so no language has to make a verb
            // agree with three numbers; the ban's reason as Content > All videos names it.
            [['update.blocked_words'], '/^'.$v.': (?<videos>[\d,]+) videos? banned \(reason "(?<reason>[^"]+)"\) because their titles, tags or categories use words that are always blocked; (?<tags>[\d,]+) tags? and (?<categories>[\d,]+) categor(?:y|ies) hidden from visitors$/u', static fn (array $m, Closure $value): string => __(
                ':version: videos, tags and categories that use words that are always blocked are hidden from visitors (videos banned: :videos, tags hidden: :tags, categories hidden: :categories; ban reason: :reason)',
                [
                    'version' => $value($m['v']),
                    'videos' => $value(number_format(self::number($m['videos']))),
                    'tags' => $value(number_format(self::number($m['tags']))),
                    'categories' => $value(number_format(self::number($m['categories']))),
                    'reason' => $value(BlockedWordsPass::label($m['reason']), 'auto'),
                ])],

            // ---- the one-click updater (App\Support\Updater, ReleaseCheck)
            [['update.check'], '/^(?<state>available|current|error|none) ?(?<detail>\S*) \((?<source>api|web)\)$/u', static fn (array $m, Closure $value): string => match ($m['state']) {
                'available' => __('Version :version is available (source: :source)', ['version' => $value($m['detail']), 'source' => $value($m['source'])]),
                'current' => __('This site is up to date (:version, source: :source)', ['version' => $value($m['detail']), 'source' => $value($m['source'])]),
                'none' => __('No release was found (source: :source)', ['source' => $value($m['source'])]),
                default => __('The check failed: :error (source: :source)', ['error' => $value($m['detail'] !== '' ? $m['detail'] : '?'), 'source' => $value($m['source'])]),
            }],
            [['update.started'], '/^(?<from>\S+) -> (?<to>\S+)$/u', static fn (array $m, Closure $value): string => __(':from to :to', ['from' => $value($m['from']), 'to' => $value($m['to'])])],
            [['update.finished'], '/^(?<from>\S+) -> (?<to>\S+) \((?<n>\d+) database changes?\)$/u', static fn (array $m, Closure $value): string => trans_choice(
                ':from to :to (1 database change)|:from to :to (:count database changes)', (int) $m['n'], ['from' => $value($m['from']), 'to' => $value($m['to'])])],
            [['update.undone'], '/^(?<from>\S+) -> (?<to>\S+) \(files only\)$/u', static fn (array $m, Closure $value): string => __(
                ':from to :to (the files only)', ['from' => $value($m['from']), 'to' => $value($m['to'])])],
            [['update.cancelled'], '/^(?<from>\S+) -> (?<to>\S+) \((?<undo>undo, )?at (?<step>[a-z_]+)\)$/u', static fn (array $m, Closure $value): string => ($m['undo'] ?? '') !== ''
                ? __(':from to :to (undo, stopped at the step :step)', ['from' => $value($m['from']), 'to' => $value($m['to']), 'step' => $value($m['step'])])
                : __(':from to :to (stopped at the step :step)', ['from' => $value($m['from']), 'to' => $value($m['to']), 'step' => $value($m['step'])])],
            [['update.files'], '/^(?<replaced>\d+) replaced, (?<added>\d+) added, (?<removed>\d+) removed(?:, (?<merged>\d+) language files? merged)? \(zip engine (?<engine>[^,]+), OPcache (?<opcache>[^)]+)\)$/u', static function (array $m, Closure $value): string {
                $text = __('Files replaced: :replaced, added: :added, removed: :removed (zip engine :engine, OPcache :opcache)', [
                    'replaced' => $value($m['replaced']), 'added' => $value($m['added']), 'removed' => $value($m['removed']),
                    'engine' => $value($m['engine']), 'opcache' => $value($m['opcache']),
                ]);

                return ($m['merged'] ?? '') !== ''
                    ? self::joinGroups([$text, trans_choice('1 language file merged with your own texts|:count language files merged with your own texts', (int) $m['merged'])])
                    : $text;
            }],
            // "kept" is the owner's own wording of texts this version corrects, never every text of the owner (all of
            // them stay): said so, and left out when there is none (judge R3-028); rows written before said "N own texts
            // kept". Since R3-026 also the texts this version no longer uses that left the file.
            [['update.texts'], '/^(?<file>lang\/[a-z]{2,3}\.json)(?: \((?<name>[^)]*)\))?: (?:(?<corrected>\d+) texts? corrected to the wording of this version|(?<failed>not corrected \(it could not be saved\)))(?:, (?<kept>\d+) own texts? kept|, your own wording kept for (?<wording>\d+) texts? this version corrects)?(?:, (?<removed>\d+) texts? this version no longer uses removed)?$/u', static function (array $m, Closure $value): string {
                $file = $value($m['file']).(($m['name'] ?? '') !== '' ? ' ('.$value($m['name'], 'auto').')' : '');
                $kept = (int) (($m['wording'] ?? '') !== '' ? $m['wording'] : ($m['kept'] ?? 0));
                $removed = (int) ($m['removed'] ?? 0);
                $parts = [($m['failed'] ?? '') !== ''
                    ? __('not corrected (the file could not be saved)')
                    : trans_choice('1 text corrected to the wording of this version|:count texts corrected to the wording of this version', (int) $m['corrected'])];

                if ($kept > 0) {
                    $parts[] = trans_choice('your own wording kept for 1 text this version corrects|your own wording kept for :count texts this version corrects', $kept);
                }

                if ($removed > 0) {
                    $parts[] = trans_choice('1 text this version no longer uses removed|:count texts this version no longer uses removed', $removed);
                }

                return self::labelled($file, self::join($parts));
            }],

            // ---- Tools > Scheduled tasks (R2A-051)
            [['cron.scheduler.run'], '/^Started schedule:run in the background\.$/u', static fn (array $m, Closure $value): string => __('Started :command in the background.', ['command' => $value('schedule:run')])],
            [['cron.scheduler.interrupt'], '/^Sent schedule interrupt signal\.$/u', static fn (): string => __('The running scheduled tasks were asked to stop.')],
            [['cron.task.run', 'cron.task.pause', 'cron.task.resume'], '/^(?:Started|Paused|Resumed) scheduled task: (?<command>.+)$/u', static fn (array $m, Closure $value): string => __('Task: :command', ['command' => $value($m['command'])])],
            [['cron.logs.clear'], '/^Cleared cron logs\.$/u', static fn (): string => __('The logs of the scheduled tasks were cleared.')],
            [['cron.duration.reset'], '/^Reset duration retry tracking for (?<n>\d+) missing-duration AVDB videos?\.$/u', static fn (array $m): string => trans_choice(
                '1 AVDB video without a duration is tried again.|:count AVDB videos without a duration are tried again.', (int) $m['n'])],
            // the message was written in the admin's language of the moment; the way it ran is a code
            [['cron.duration.start'], '/^(?<mode>smart|background|queue|inline): (?<message>.+)$/su', static fn (array $m, Closure $value): string => $value($m['message'], 'plain').' ('.$value($m['mode']).')'],

            // ---- Tools > Background jobs (R2A-051)
            [['queue.worker.start'], '/^Started worker: (?<command>.+)$/u', static fn (array $m, Closure $value): string => __('Command: :command', ['command' => $value($m['command'])])],
            [['queue.worker.restart'], '/^Requested queue worker restart\.$/u', static fn (): string => __('Every queue worker starts again after its current job.')],
            [['queue.cleanup.stale'], '/^Cleaned stale queue rows\.$/u', static fn (): string => __('Stale queue rows cleaned.')],
            // the lines of queue:cleanup-stale (App\Console\Commands\PruneQueueTables), one count each
            [['queue.cleanup.stale'], '/^Deleted \d+ .+$/su', static fn (array $m): string => self::lines($m[0], [
                '/^Deleted (?<n>\d+) obsolete AVDB import queue stubs\.$/u' => static fn (array $l): string => __('Obsolete AVDB import queue entries deleted: :count', ['count' => $l['n']]),
                '/^Deleted (?<n>\d+) stale realtime broadcast jobs? older than (?<h>\d+) hours?\.$/u' => static fn (array $l): string => __('Realtime broadcast jobs older than :age deleted: :count', ['age' => self::hours((int) $l['h']), 'count' => $l['n']]),
                '/^Deleted (?<n>\d+) queued jobs? older than (?<d>\d+) days?\.$/u' => static fn (array $l): string => __('Queued jobs older than :age deleted: :count', ['age' => self::days((int) $l['d']), 'count' => $l['n']]),
                '/^Deleted (?<n>\d+) failed jobs? older than (?<h>\d+) hours?\.$/u' => static fn (array $l): string => __('Failed jobs older than :age deleted: :count', ['age' => self::hours((int) $l['h']), 'count' => $l['n']]),
                '/^Deleted (?<n>\d+) batch rows? older than (?<h>\d+) hours?\.$/u' => static fn (array $l): string => __('Job batches older than :age deleted: :count', ['age' => self::hours((int) $l['h']), 'count' => $l['n']]),
                '/^Deleted (?<n>\d+) old AVDB import run rows? and (?<f>\d+) import log or export files?\.$/u' => static fn (array $l): string => __('Old AVDB import runs deleted: :count (their log and export files: :files)', ['count' => $l['n'], 'files' => $l['f']]),
                '/^Deleted (?<n>\d+) stale DB session rows? older than (?<d>\d+) days?\.$/u' => static fn (array $l): string => __('Database sessions older than :age deleted: :count', ['age' => self::days((int) $l['d']), 'count' => $l['n']]),
            ])],
            [['queue.job.pause'], '/^Paused job #(?<id>\d+) for (?<n>\d+) seconds?\.$/u', static fn (array $m, Closure $value): string => trans_choice(
                'Job :job waits 1 second.|Job :job waits :count seconds.', (int) $m['n'], ['job' => $value('#'.$m['id'])])],
            [['queue.job.resume'], '/^Released job #(?<id>\d+) to run now\.$/u', static fn (array $m, Closure $value): string => __('Job :job runs now.', ['job' => $value('#'.$m['id'])])],
            [['queue.job.retry'], '/^Reset queued job #(?<id>\d+)\.$/u', static fn (array $m, Closure $value): string => __('Job :job starts over.', ['job' => $value('#'.$m['id'])])],
            [['queue.job.delete'], '/^Deleted queued job #(?<id>\d+)\.$/u', static fn (array $m, Closure $value): string => __('Job :job', ['job' => $value('#'.$m['id'])])],
            [['queue.job.delete.blocked'], '/^Blocked delete for protected job #(?<id>\d+): (?<reason>.+)\.$/su', static fn (array $m, Closure $value): string => __('Job :job is kept: :reason', ['job' => $value('#'.$m['id']), 'reason' => self::jobReason($m['reason'], $value)])],
            [['queue.jobs.pause_all', 'queue.jobs.resume_all'], '/^(?:Paused|Released) (?<n>\d+) queued jobs?(?: on (?<queue>.+))?\.$/u', static fn (array $m, Closure $value): string => ($m['queue'] ?? '') !== ''
                ? trans_choice('1 job of the queue :queue|:count jobs of the queue :queue', (int) $m['n'], ['queue' => $value($m['queue'])])
                : trans_choice('1 job|:count jobs', (int) $m['n'])],
            [['queue.jobs.delete_all'], '/^Deleted (?<n>\d+) deletable queued jobs?(?: on (?<queue>.+?))?; kept (?<kept>\d+) protected jobs?\.$/u', static fn (array $m, Closure $value): string => ($m['queue'] ?? '') !== ''
                ? trans_choice('Deleted: 1 job of the queue :queue.|Deleted: :count jobs of the queue :queue.', (int) $m['n'], ['queue' => $value($m['queue'])]).' '.trans_choice('Kept: 1 protected job.|Kept: :count protected jobs.', (int) $m['kept'])
                : trans_choice('Deleted: 1 job.|Deleted: :count jobs.', (int) $m['n']).' '.trans_choice('Kept: 1 protected job.|Kept: :count protected jobs.', (int) $m['kept'])],
            [['queue.failed.retry'], '/^Retried failed job (?<uuid>\S+)\.$/u', static fn (array $m, Closure $value): string => __('Job :job', ['job' => $value($m['uuid'])])],
            [['queue.failed.retry_all'], '/^Retried all failed jobs\.$/u', static fn (): string => __('Every failed job runs again.')],
            [['queue.failed.delete'], '/^Deleted failed job (?<uuid>\S+)\.$/u', static fn (array $m, Closure $value): string => __('Job :job', ['job' => $value($m['uuid'])])],
            [['queue.failed.delete.blocked'], '/^Blocked delete for protected failed job (?<uuid>\S+): (?<reason>.+)\.$/su', static fn (array $m, Closure $value): string => __('Job :job is kept: :reason', ['job' => $value($m['uuid']), 'reason' => self::jobReason($m['reason'], $value)])],
            [['queue.failed.flush'], '/^Cleared (?<n>\d+) deletable failed jobs?; kept (?<kept>\d+) protected jobs?\.$/u', static fn (array $m): string => trans_choice('Deleted: 1 job.|Deleted: :count jobs.', (int) $m['n']).' '.trans_choice('Kept: 1 protected job.|Kept: :count protected jobs.', (int) $m['kept'])],
            [['queue.batch.delete'], '/^Deleted batch (?<id>\S+)\.$/u', static fn (array $m, Closure $value): string => __('Batch :batch', ['batch' => $value($m['id'])])],
            [['queue.batch.delete.blocked'], '/^Blocked delete for active batch (?<id>\S+)\.$/u', static fn (array $m, Closure $value): string => __('Batch :batch is still running.', ['batch' => $value($m['id'])])],

            // ---- what administrators change (R2A-020): names, ids and keys, and a few words
            [['member.blocked'], '/^(?<member>.+), with their (?<what>videos and comments|videos|comments)$/su', static fn (array $m, Closure $value): string => match ($m['what']) {
                'videos' => __(':member, with their videos', ['member' => $value($m['member'], 'auto')]),
                'comments' => __(':member, with their comments', ['member' => $value($m['member'], 'auto')]),
                default => __(':member, with their videos and comments', ['member' => $value($m['member'], 'auto')]),
            }],
            [['member.updated', 'video.updated'], '/^(?<item>.+): (?<fields>(?:username|email|password|description|country|title|category|visibility|language|ads|comments|likes|short)(?:, (?:username|email|password|description|country|title|category|visibility|language|ads|comments|likes|short))*)$/su', static fn (array $m, Closure $value): string => self::labelled($value($m['item'], 'auto'), self::words($m['fields'], static fn (string $field): string => self::fieldName($field)))],
            [['video.category_changed', 'videos.category_changed'], '/^(?<videos>.+) to the category (?<category>.+)$/su', static fn (array $m, Closure $value): string => __(':videos, to the category :category', [
                'videos' => self::moreList($m['videos'], $value), 'category' => $value($m['category'], 'auto'),
            ])],
            [['settings.saved', 'appearance.published', 'appearance.restored'], '/^(?<keys>[A-Za-z0-9_.-]+(?:, [A-Za-z0-9_.-]+)*(?: and \d+ more)?)$/u', static fn (array $m, Closure $value): string => self::settingNames($m['keys'], $value)],
            [['appearance.theme_applied', 'appearance.look_applied'], '/^(?<name>.+)$/su', static fn (array $m, Closure $value): string => $value(__($m['name']), 'auto')],
            [['notifications.broadcast'], '/^(?<title>.+), to (?<n>\d+) members?$/su', static fn (array $m, Closure $value): string => trans_choice(
                ':title, to 1 member|:title, to :count members', (int) $m['n'], ['title' => $value($m['title'], 'auto')])],
            [['messages.all_read'], '/^(?<n>\d+)$/u', static fn (array $m): string => trans_choice('1 message|:count messages', (int) $m['n'])],
            [['security.address.blocks_cleared'], '/^(?<n>\d+)$/u', static fn (array $m): string => trans_choice('1 ended block|:count ended blocks', (int) $m['n'])],
            [['tools.env.saved'], '/^(?<names>.+)$/su', static fn (array $m, Closure $value): string => self::moreList($m['names'], $value, 'ltr')],
            [[
                'members.admin.granted', 'members.admin.removed', 'members.blocked', 'members.unblocked', 'members.email_confirmed', 'members.deleted',
                'videos.banned', 'videos.unbanned', 'videos.made_public', 'videos.made_private', 'videos.made_unlisted', 'videos.duration_check', 'videos.deleted',
                'comments.hidden', 'comments.shown', 'comments.deleted', 'playlists.deleted', 'notifications.deleted', 'notifications.read',
                'activities.deleted', 'likes.deleted', 'devices.signed_out', 'messages.deleted', 'messages.read', 'reports.deleted', 'reports.resolved',
                'queue.failed.delete_some', 'queue.failed.retry_some',
            ], '/^(?<names>.+) and (?<more>\d+) more$/su', static fn (array $m, Closure $value): string => trans_choice(':list and 1 more|:list and :count more', (int) $m['more'], ['list' => $value($m['names'], 'auto')])],

            // The database backup an update made, by the name Tools > Backups lists the file under now (judge R3-031): the
            // row keeps the name without its random part (DatabaseBackup::shownName()), and the update to 1.2.3 gave the
            // backups of 1.2.2 and older their random part after the old updater had named them.
            [['update.database_backup'], '/^(?<name>[A-Za-z0-9._-]+\.sql(?:\.gz)?)(?<rest>(?: \([^()]*\))?)$/u', static fn (array $m, Closure $value): string => $value(self::backupName($m['name']).$m['rest'])],

            // ---- every other admin change that changes the site (R3-019): the fields a save changed by the names its
            // form shows them, counts as "Views: 1,204", the sentences of the pages' own messages, and names, codes
            // and lists as above
            [['category.updated'], '/^(?<item>.+): (?<fields>[a-z_]+(?:, [a-z_]+)*)$/su', static fn (array $m, Closure $value): string => self::labelled($value($m['item'], 'auto'), self::fieldList($m['fields'], static fn (string $field): ?string => match ($field) {
                'name' => __('Name'), 'address' => __('Address'), 'icon' => __('Icon'), 'menu' => __('Show in the menu'), default => null,
            }))],
            [['plan.updated'], '/^(?<item>.+): (?<fields>[a-z_]+(?:, [a-z_]+)*)$/su', static fn (array $m, Closure $value): string => self::labelled($value($m['item'], 'auto'), self::fieldList($m['fields'], static fn (string $field): ?string => match ($field) {
                'name' => __('Name'), 'price' => __('Price'), 'billing' => __('Billed'), 'stripe' => __('Stripe price ID'), 'paypal' => __('PayPal plan ID'), default => null,
            }))],
            [['page.updated'], '/^(?<item>.+): (?<fields>[a-z_]+(?:, [a-z_]+)*)$/su', static fn (array $m, Closure $value): string => self::labelled($value($m['item'], 'auto'), self::fieldList($m['fields'], static fn (string $field): ?string => match ($field) {
                'title' => __('Title'), 'address' => __('Address'), 'text' => __('Text'), 'search_title' => __('Title in search results'),
                'search_description' => __('Description in search results'), 'published' => __('Published'), 'footer_link' => __('Link in the footer'),
                'header_link' => __('Link in the header'), 'order' => __('Order'), default => null,
            }))],
            [['language.updated'], '/^(?<item>.+): (?<fields>[a-z_]+(?:, [a-z_]+)*)$/su', static fn (array $m, Closure $value): string => self::labelled($value($m['item'], 'auto'), self::fieldList($m['fields'], static fn (string $field): ?string => match ($field) {
                'name' => __('Name'), 'flag' => __('Flag'), 'direction' => __('Text direction'), 'active' => __('Visitors can pick it'), 'order' => __('Order'), default => null,
            }))],
            [['spam.updated'], '/^(?<lists>(?:words|emails)(?:, (?:words|emails))?)$/u', static fn (array $m): string => self::fieldList($m['lists'], static fn (string $list): ?string => match ($list) {
                'words' => __('Blocked words'), 'emails' => __('Blocked email addresses'), default => null,
            })],
            [['tag.renamed', 'tags.merged'], '/^(?<from>.+) -> (?<to>.+)$/su', static fn (array $m, Closure $value): string => __(':from to :to', ['from' => $value($m['from'], 'auto'), 'to' => $value($m['to'], 'auto')])],
            [['language.texts.saved', 'language.texts.imported'], '/^(?<language>.+): (?<n>\d+) texts?$/su', static fn (array $m, Closure $value): string => self::labelled(
                $value($m['language'], 'auto'), trans_choice('1 text changed|:count texts changed', (int) $m['n'], ['count' => number_format((int) $m['n'])]))],
            [['premium.extended'], '/^(?<member>.+), \+(?<n>\d+) days?$/su', static fn (array $m, Closure $value): string => self::labelled($value($m['member'], 'auto'), '+'.self::days((int) $m['n']))],
            [['views.cleared'], '/^(?<n>\d+)$/u', static fn (array $m): string => self::labelled(__('Views'), number_format((int) $m['n']))],
            [['searches.cleared'], '/^(?<n>\d+)$/u', static fn (array $m): string => self::labelled(__('Searches'), number_format((int) $m['n']))],
            [['security.password_resets.cleared'], '/^(?<n>\d+)$/u', static fn (array $m): string => self::labelled(__('Password resets'), number_format((int) $m['n']))],
            [['tools.database.migrated'], '/^(?<n>\d+) database changes?$/u', static fn (array $m): string => trans_choice('1 database change|:count database changes', (int) $m['n'], ['count' => number_format((int) $m['n'])])],
            [['tools.storage.cleared'], '/^(?<folder>\S+): (?<n>\d+) files?$/u', static fn (array $m, Closure $value): string => self::labelled($value($m['folder']), trans_choice('1 file|:count files', (int) $m['n'], ['count' => number_format((int) $m['n'])]))],
            // Auto-fix: each line the repairer wrote, in the words of the Health check page (PreflightController), as the
            // parts of one line ("a; b"): a line's own full stop is left out there (no ".;" between two of them)
            [['tools.health.fixed'], '/^(?<lines>.+)$/su', static fn (array $m, Closure $value): string => self::joinGroups(array_map(static function (string $line) use ($value): string {
                $resolved = \App\Http\Controllers\Admin\PreflightController::repairLine($line);

                return $resolved === null ? $value($line, 'auto') : (string) preg_replace('/[.。।]+$/u', '', __($resolved[0], array_map(static fn (string $part): string => $value($part), $resolved[1])));
            }, array_values(array_filter(array_map('trim', preg_split('/\R/u', $m['lines']) ?: []), static fn (string $line): bool => $line !== ''))))],
            [['import.avdb.started', 'import.xvid.started'], '/^#(?<id>\d+), dry run$/u', static fn (array $m, Closure $value): string => self::join([$value('#'.$m['id']), __('Dry run only')])],
            // Clean All: the sentences the import page answers with
            [['import.avdb.cleaned'], '/^videos: (?<videos>\d+)(?:, tags: (?<tags>\d+))?(?:, imports stopped: (?<stopped>\d+))?(?:, queued jobs: (?<queued>\d+))?$/u', static function (array $m): string {
                [$videos, $tags, $stopped, $queued] = [(int) $m['videos'], (int) ($m['tags'] ?? 0), (int) ($m['stopped'] ?? 0), (int) ($m['queued'] ?? 0)];

                return implode(' ', array_filter([
                    $videos > 0 ? trans_choice('Deleted 1 AVDB video permanently.|Deleted :count AVDB videos permanently.', $videos) : __('No AVDB videos found. Nothing was deleted.'),
                    $videos > 0 && $tags > 0 ? trans_choice('Removed 1 AVDB tag no video uses any more.|Removed :count AVDB tags no video uses any more.', $tags) : null,
                    $stopped > 0 ? trans_choice('Asked 1 running AVDB import to stop.|Asked :count running AVDB imports to stop.', $stopped) : null,
                    $queued > 0 ? trans_choice('Removed 1 queued AVDB import or duration job.|Removed :count queued AVDB import or duration jobs.', $queued) : null,
                ]));
            }],
            [['import.xvid.cleaned'], '/^videos: (?<videos>\d+)(?:, imports stopped: (?<stopped>\d+))?$/u', static fn (array $m): string => implode(' ', array_filter([
                (int) $m['videos'] > 0 ? trans_choice('Deleted 1 XVIDAPI video and its details.|Deleted :count XVIDAPI videos and their details.', (int) $m['videos'], ['count' => number_format((int) $m['videos'])]) : __('No XVIDAPI videos found to delete.'),
                (int) ($m['stopped'] ?? 0) > 0 ? __('All active XVIDAPI import runs force stopped.') : null,
            ]))],
            [['queue.batch.retry'], '/^Retried the failed jobs of batch (?<id>\S+)\.$/u', static fn (array $m, Closure $value): string => __('Batch :batch', ['batch' => $value($m['id'])])],
            [['appearance.new_look'], '/^(?<name>.+)$/su', static fn (array $m, Closure $value): string => $value(__($m['name']), 'auto')],
            [[
                'categories.reordered', 'tags.unused_deleted', 'tools.logs.cleared', 'tools.logs.deleted', 'import.avdb.force_stopped', 'import.avdb.runs_deleted',
                'import.avdb.schedules.run_due', 'import.xvid.force_stopped', 'import.xvid.runs_deleted',
            ], '/^(?<names>.+) and (?<more>\d+) more$/su', static fn (array $m, Closure $value): string => trans_choice(':list and 1 more|:list and :count more', (int) $m['more'], ['list' => $value($m['names'], 'auto')])],
            // codes and addresses (left to right)
            [[
                'redirect.created', 'redirect.on', 'redirect.off', 'redirect.deleted', 'media.deleted', 'members.exported', 'tools.log.deleted',
                'import.avdb.started', 'import.avdb.stopped', 'import.avdb.run_deleted', 'import.xvid.started', 'import.xvid.stopped', 'import.xvid.run_deleted',
                'search.deleted', 'view.deleted',
            ], '/^(?<code>.+)$/su', static fn (array $m, Closure $value): string => $value($m['code'])],
            // names (in their own direction)
            [[
                'category.created', 'category.deleted', 'categories.reordered', 'tag.created', 'tag.deleted', 'tags.unused_deleted',
                'featured.created', 'featured.on', 'featured.off', 'featured.deleted', 'page.created', 'page.published', 'page.unpublished', 'page.deleted',
                'thumbnail.chosen', 'thumbnail.deleted', 'plan.created', 'plan.deleted', 'premium.deleted', 'subscription.cancelled', 'subscription.activated',
                'subscription.deleted', 'payment.deleted', 'security.password_reset.deleted', 'announcement.created', 'announcement.shown', 'announcement.hidden',
                'announcement.deleted', 'language.added', 'language.on', 'language.off', 'language.deleted', 'tools.logs.cleared', 'tools.logs.deleted',
                'import.avdb.force_stopped', 'import.avdb.runs_deleted', 'import.avdb.schedules.run_due', 'import.xvid.force_stopped', 'import.xvid.runs_deleted',
                'import.avdb.schedule.created', 'import.avdb.schedule.on', 'import.avdb.schedule.off', 'import.avdb.schedule.run', 'import.avdb.schedule.deleted',
                'import.xvid.schedule.created', 'import.xvid.schedule.on', 'import.xvid.schedule.off', 'import.xvid.schedule.run', 'import.xvid.schedule.deleted',
            ], '/^(?<names>.+)$/su', static fn (array $m, Closure $value): string => $value($m['names'], 'auto')],

            // ---- details with no words of their own: set apart so they read in their direction on an Arabic page
            [[
                'tools.command.run', 'tools.database.emptied', 'tools.backup.created', 'tools.backup.deleted', 'security.api_key.revoked',
                'security.address.blocked', 'security.address.unblocked', 'update.backup_deleted', 'update.database_backup',
                'money.payment_key.saved', 'money.payment_key.removed',
            ], '/^(?<code>.+)$/su', static fn (array $m, Closure $value): string => $value($m['code'])],
            [[
                'member.admin.granted', 'members.admin.granted', 'member.admin.removed', 'members.admin.removed', 'member.blocked', 'members.blocked',
                'member.unblocked', 'members.unblocked', 'member.view_only.on', 'member.view_only.off', 'member.email_confirmed', 'members.email_confirmed',
                'member.email_unconfirmed', 'member.deleted', 'members.deleted', 'member.impersonated', 'member.devices.signed_out',
                'video.banned', 'videos.banned', 'video.unbanned', 'videos.unbanned', 'video.made_public', 'videos.made_public', 'video.made_private',
                'videos.made_private', 'video.made_unlisted', 'videos.made_unlisted', 'video.duration_check', 'videos.duration_check', 'video.deleted', 'videos.deleted',
                'comment.hidden', 'comments.hidden', 'comment.shown', 'comments.shown', 'comment.deleted', 'comments.deleted', 'playlist.deleted', 'playlists.deleted',
                'notification.deleted', 'notifications.deleted', 'notification.read', 'notifications.read', 'activity.deleted', 'activities.deleted',
                'like.deleted', 'likes.deleted', 'device.signed_out', 'devices.signed_out', 'message.deleted', 'messages.deleted', 'message.read', 'messages.read',
                'report.deleted', 'reports.deleted', 'report.resolved', 'reports.resolved', 'queue.failed.delete_some', 'queue.failed.retry_some',
                'security.session.signed_out',
            ], '/^(?<names>.+)$/su', static fn (array $m, Closure $value): string => $value($m['names'], 'auto')],
        ];
    }
}
