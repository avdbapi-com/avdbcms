<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminNav;
use App\Support\AdminPreferences;
use App\Support\AdminSearchIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The admin shell's endpoints: the Ctrl+K search (index + members/videos as you type) and the account
 * menu's preferences (light/dark, admin colour, advanced pages, Developer mode, old/new admin).
 */
class AdminSearchController extends Controller
{
    /**
     * Pages, tabs, settings and synonyms; the URL carries the index version, so browsers may keep it. The route
     * skips the Language middleware (it would mark the answer no-store), so the URL names the language (?l=).
     */
    public function index(Request $request): JsonResponse
    {
        // A query value can be a list (?l[]=x): only a plain string counts.
        $locale = is_string($request->query('l')) ? $request->query('l') : '';

        if ($locale !== '' && array_key_exists($locale, (array) config('languages', []))) {
            App::setLocale($locale);
        }

        $index = AdminSearchIndex::build($request->user());
        $cacheable = $request->query('v') === $index['v'];

        return response()
            ->json($index, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', $cacheable ? 'private, max-age=86400' : 'private, no-cache')
            ->header('X-Robots-Tag', 'noindex');
    }

    /** Members and videos matching what the admin typed (2+ characters). */
    public function search(Request $request): JsonResponse
    {
        $query = trim(Str::limit(is_string($request->query('q')) ? $request->query('q') : '', 80, ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['members' => [], 'videos' => []]);
        }

        return response()
            ->json([
                'members' => AdminSearchIndex::members($query),
                'videos' => AdminSearchIndex::videos($query),
            ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'private, no-store');
    }

    /** "What moved where": every page of the old admin menu and where it lives now. */
    public function moved(Request $request): View
    {
        $developer = AdminPreferences::developerMode($request->user());
        $rows = [];

        foreach ((array) config('admin_nav.old_menu', []) as $entry) {
            $page = AdminNav::page($entry['page'] ?? null);

            if ($page === null) {
                continue;
            }

            $section = AdminNav::section($page['section']);
            $rows[] = [
                'old' => __($entry['old']),
                'section' => __($section['label'] ?? ''),
                'page' => __($page['label']),
                'url' => AdminNav::visibleTo($page, $developer) ? AdminNav::url($page) : null,
            ];
        }

        return view('components.admin.page-moved', ['rows' => $rows]);
    }

    public function preferences(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'in:'.implode(',', AdminPreferences::keys())],
            'value' => ['present'],
        ]);

        // Uploaded over an older version and "Finish update" has not run yet: there is nowhere to keep the choice.
        if (! AdminPreferences::available($request->user())) {
            $message = __('These choices can be saved after the update is finished. Open Home and click Finish update.');

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $message], 409)
                : redirect()->back(fallback: route('admin.index'))->withErrors(['value' => $message]);
        }

        try {
            AdminPreferences::set($request->user(), $data['key'], $data['value']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['value' => __('This choice is not available.')]);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'preferences' => AdminPreferences::all($request->user())]);
        }

        // Back to the page the account menu was opened on.
        return redirect()->back(fallback: route('admin.index'));
    }
}
