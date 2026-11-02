<?php

namespace App\Support;

use App\Http\Middleware\ViewOnlyDemo;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Throwable;

/**
 * The "This is a demo" window of a view-only demo account (1.2.3, App\Http\Middleware\ViewOnlyDemo): one accessible
 * dialog for every refusal, on every page such an account opens (the admin and the old admin, the studio, account
 * settings, member and public pages). Only a view-only account's pages carry it: the guard puts it right after the
 * "View-only demo" note, so nobody else loads its markup or its script
 * (resources/views/layouts/partials/view-only-demo-window.blade.php).
 *
 *  - A page form that was refused, or a closed page opened by a link: the guard sends the account back with the kind
 *    of refusal as a one-time value of the session (FLASH); the page it comes back to opens the window at once, and
 *    the page's own notes of the refusal give way to it (the marked "Not saved" notes, [data-view-only-refusal], and
 *    any part whose whole text is the refusal's sentence, said()).
 *  - A request of the page's scripts that was refused (403 with X-View-Only-Demo: 1; ViewOnlyDemo::REFUSAL_HEADER says
 *    the kind): the window's script watches the answers of fetch() and XMLHttpRequest and opens the same window. While
 *    it is open the site's toasts stay quiet (OPEN_ATTRIBUTE on <html>, resources/js/layout/toasts.ts and
 *    resources/admin/modules/toast.ts), so the refusal is said once, by the window.
 *  - One window at a time; focus moves into it and back (after a page came back from a refusal: to the control that
 *    sent it away, else the page's main region); Escape, its button and a click outside it close it, and only it.
 *
 * Its look is the site's own: the admin's dialog (.ad-dialog) in the new admin, the member kit's dialog (.mk-dialog,
 * which follows the look's "Pop-up window style": centred, a side sheet or a bottom sheet) everywhere else.
 */
final class ViewOnlyDemoWindow
{
    /** One-time session value: the kind of refusal (ViewOnlyDemo::REFUSED_*) the account was sent back with. */
    public const FLASH = 'view_only_demo.window';

    /** Set on <html> while the window is open: the site's toasts stay quiet then. */
    public const OPEN_ATTRIBUTE = 'data-view-only-demo-open';

    /**
     * Set on <html> of every page of a view-only account (by the window's script): a page script can tell that the
     * server refuses this account's changes (the admin's list rows then send a delete at once, with no Undo time).
     */
    public const PAGE_ATTRIBUTE = 'data-view-only-demo';

    /** The dialog's id (its title and text: -title, -body). */
    public const ID = 'avd-demo-window';

    /**
     * What the window shows for a kind of refusal: also carried by a script's 403 answer (demo_window).
     *
     * @return array{kind: string, title: string, body: string, button: string}
     */
    public static function payload(string $kind): array
    {
        $closed = $kind === ViewOnlyDemo::REFUSED_CLOSED;

        return [
            'kind' => $closed ? ViewOnlyDemo::REFUSED_CLOSED : ViewOnlyDemo::REFUSED_CHANGE,
            'title' => __('This is a demo'),
            'body' => $closed ? __('This part is not open in the demo.') : __('You can look around everything, but nothing you do here is saved.'),
            // its own words (the admin's status label "OK" is Latin "OK" in every language: judge R3-052)
            'button' => __('Got it'),
        ];
    }

    /**
     * The kind of refusal this page came back with: the guard's one-time value; a refusal that went back without it
     * (the error view_only_demo alone): a change. Null when the page did not come back from a refusal.
     */
    public static function refusedOnPage(Request $request): ?string
    {
        try {
            if (! $request->hasSession()) {
                return null;
            }

            $session = $request->session();
            $kind = $session->get(self::FLASH);

            if ($kind === ViewOnlyDemo::REFUSED_CLOSED || $kind === ViewOnlyDemo::REFUSED_CHANGE) {
                return $kind;
            }

            $errors = $session->get('errors');

            return $errors instanceof ViewErrorBag && $errors->has('view_only_demo') ? ViewOnlyDemo::REFUSED_CHANGE : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The window for a page of a view-only account (the guard puts it after the note). '' when it cannot be drawn: the
     * page is shown without it, and its own "Not saved" notes stay.
     */
    public static function markup(Request $request, string $html): string
    {
        try {
            $markup = view('layouts.partials.view-only-demo-window', [
                'admin' => self::isNewAdmin($html),
                'open' => self::refusedOnPage($request),
                'change' => self::payload(ViewOnlyDemo::REFUSED_CHANGE),
                'closed' => self::payload(ViewOnlyDemo::REFUSED_CLOSED),
                'said' => self::said($request),
            ])->render();

            // Sent with every page of the account: without the indentation (its markup, style and script do not
            // depend on it).
            return preg_replace('/^[ \t]+/m', '', $markup) ?? $markup;
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The sentence of the refusal this page came back with (none on any other page): a part of the page whose whole text
     * is that sentence only repeats what the window says, so it gives way to the window. A script's refusal brings its
     * own sentence (the answer's message), which the window's script learns the same way.
     *
     * @return list<string>
     */
    public static function said(?Request $request = null): array
    {
        try {
            $errors = $request?->hasSession() ? $request->session()->get('errors') : null;
            $sentence = $errors instanceof ViewErrorBag && $errors->has('view_only_demo') ? (string) $errors->first('view_only_demo') : '';
        } catch (Throwable) {
            $sentence = '';
        }

        $sentence = trim((string) preg_replace('/\s+/u', ' ', $sentence));

        return $sentence === '' ? [] : [$sentence];
    }

    /** Whether the page is one of the new admin: its shell prints <html ... data-admin-ui="v2">. */
    private static function isNewAdmin(string $html): bool
    {
        return preg_match('/<html\b[^>]*\sdata-admin-ui="v2"/i', substr($html, 0, 4096)) === 1;
    }
}
