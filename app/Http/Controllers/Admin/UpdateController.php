<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPreferences;
use App\Support\AppearanceApplier;
use App\Support\PendingMigrations;
use App\Support\SettingsRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * "Finish update" on Home and the look choices that follow it. A new version uploaded over an older one works at once;
 * its database changes wait for one click here. The upgrade moves the look to the new theme collection and keeps the
 * previous look for 30 days (revision #0), so the administrator can go back - and forward again.
 */
class UpdateController
{
    public function finish(Request $request): JsonResponse|RedirectResponse
    {
        $result = PendingMigrations::run();

        return $this->answer($request, $result['ok'], $result['message'], ['ran' => $result['ran'], 'busy' => $result['busy']]);
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

    private function answer(Request $request, bool $ok, string $message, array $extra = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message] + $extra, $ok ? 200 : 409);
        }

        $redirect = redirect()->route('admin.index');

        return $ok ? $redirect->with('success', $message) : $redirect->withErrors(['update' => $message]);
    }
}
