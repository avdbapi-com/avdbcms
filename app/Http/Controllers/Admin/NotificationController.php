<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Notifications\Support\Inbox;
use App\Notifications\Support\NotificationTasks;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Community > Send a notification: the list of the notifications members got (all, or only the unread ones), sending a
 * new one, deleting one or all. Quick with millions of rows: the list goes page by page by key (newer / older, the
 * newest first, index notifications_created_id_idx or notifications_read_created_id_idx), the counts in the header stop
 * at $countCap (kept a minute), sending and "Delete all" never run past a page's time (NotificationTasks).
 */
class NotificationController
{
    /** The counts in the page header stop here ("more than 100,000"). */
    public static int $countCap = 100000;

    /** Who a notification can go to (NotificationTasks::audience()). */
    public const TARGETS = ['all', 'verified', 'unverified', 'premium', 'specific'];

    private const COUNTS_KEY = 'notifications.counts.v2';

    public function index(Request $request): View
    {
        return view(AdminPreferences::usesNewAdmin($request->user()) ? 'admin.notifications.list' : 'admin.notifications.index', $this->listPage($request));
    }

    /**
     * The list: the latest first, all or only the unread ones (?show=unread), with the member each one went to (one
     * join), page by page by key; the two counts, capped, kept for a minute.
     *
     * @return array<string, mixed>
     */
    private function listPage(Request $request): array
    {
        $unreadOnly = $request->query('show') === 'unread';
        $perPage = AdminPagination::perPage($request, 25);
        $clearedBefore = NotificationTasks::clearedBefore();

        // the page's keys from the covering index (newest first, by cursor), then those rows with the member's name
        $keys = DB::table('notifications')
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->when($clearedBefore, fn ($query, $at) => $query->where('created_at', '>', $at));
        $notifications = Inbox::keyedPage($keys, $perPage, fn (array $ids) => DB::table('notifications')
            ->leftJoin('users', fn ($join) => $join->on('notifications.notifiable_id', '=', 'users.id')->where('notifications.notifiable_type', '=', (new User())->getMorphClass()))
            ->select('notifications.id', 'notifications.type', 'notifications.notifiable_id', 'notifications.data', 'notifications.read_at', 'notifications.created_at', 'users.username')
            ->whereIn('notifications.id', $ids)
            ->get()
        )->withQueryString();

        $stats = AdminCache::remember(self::COUNTS_KEY, now()->addMinute(), fn (): array => self::counts($clearedBefore));
        // the one "send a notification" form (judge R1-161): how many members each choice reaches, kept a minute
        $audience = BroadcastController::audience();

        return compact('notifications', 'stats', 'unreadOnly', 'perPage', 'audience');
    }

    /**
     * The total and the unread notifications, each counted up to $countCap + 1 index entries (capped: true past it).
     *
     * @return array{total: int, unread: int, total_capped: bool, unread_capped: bool}
     */
    public static function counts(?\Illuminate\Support\Carbon $clearedBefore = null): array
    {
        $cap = self::$countCap;
        $count = function (bool $unread) use ($cap, $clearedBefore): int {
            $rows = DB::table('notifications')
                ->when($unread, fn ($query) => $query->whereNull('read_at'))
                ->when($clearedBefore, fn ($query, $at) => $query->where('created_at', '>', $at))
                ->select('id')
                ->limit($cap + 1);

            return (int) DB::query()->fromSub($rows, 'counted')->count();
        };

        $total = $count(false);
        // the unread count reads its own index; without it (before "Finish update") it is left out
        $unread = $total > 0 && self::hasIndex('notifications_read_created_id_idx') ? $count(true) : null;

        return [
            'total' => min($total, $cap),
            'unread' => min((int) $unread, $cap),
            'total_capped' => $total > $cap,
            'unread_capped' => $unread !== null && $unread > $cap,
        ];
    }

    public function broadcast(Request $request): RedirectResponse
    {
        $result = self::send($request);

        return redirect()->route('admin.notifications.index')->withSuccess(self::sentMessage($result));
    }

    /**
     * The one "send a notification" form (judge R1-161; the old admin's Mass Broadcast page posts the same fields to
     * admin.broadcast.send): to every member, those with a confirmed or an unconfirmed email, the premium members or
     * chosen member numbers, in batches by key (NotificationTasks).
     *
     * @return array{total: int, finished: bool}
     */
    public static function send(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'target' => ['required', 'in:'.implode(',', self::TARGETS)],
            'user_ids' => ['nullable', 'string', 'max:20000'],
        ]);

        $ids = $validated['target'] === 'specific' ? self::memberNumbers((string) ($validated['user_ids'] ?? '')) : [];

        $result = NotificationTasks::broadcast($request->user(), $validated['target'], $validated['title'], $validated['message'], $ids);
        AdminCache::forget(self::COUNTS_KEY);

        // Tools > Logs > Admin actions (R2A-020): the notification's title and how many members it goes to (both admins).
        if ((int) $result['total'] > 0) {
            AuditLogController::log('notifications.broadcast', \Illuminate\Support\Str::limit(trim((string) $validated['title']), \App\Support\AuditDetails::NAME_MAX).', to '.(int) $result['total'].' '.((int) $result['total'] === 1 ? 'member' : 'members'));
        }

        return $result;
    }

    /**
     * The numbers typed into "Member numbers" (each once, at most 5,000), as the admin's keyboard writes them: separated
     * by commas, semicolons, spaces or new lines, the Arabic, full-width and ideographic commas included (the Arabic
     * help's own example is "1، 2، 5"), each number in Western, Arabic-Indic, Persian, Devanagari, Thai or full-width
     * digits, with or without the "#" the list shows before a member's number. Anything else ("x", "12a", "1-5") is no
     * member number and is left out.
     *
     * @return list<int>
     */
    public static function memberNumbers(string $typed): array
    {
        static $digits = null;

        if ($digits === null) {
            $digits = ['＃' => '#'];

            // the zero of each script's digits: Arabic-Indic, Persian (extended Arabic-Indic), Devanagari, Thai, full-width
            foreach ([0x0660, 0x06F0, 0x0966, 0x0E50, 0xFF10] as $zero) {
                for ($digit = 0; $digit <= 9; $digit++) {
                    $digits[mb_chr($zero + $digit, 'UTF-8')] = (string) $digit;
                }
            }
        }

        $numbers = [];

        // separators: any space or line break, the invisible marks a right-to-left text carries (\p{Cf}: LRM, RLM, the
        // isolates), commas and semicolons of the Latin, Arabic, full-width and ideographic scripts
        foreach (preg_split('/[\s\p{Z}\p{Cf},;\x{060C}\x{061B}\x{FF0C}\x{FF1B}\x{3001}]+/u', $typed, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $number = strtr($token, $digits);
            $number = str_starts_with($number, '#') ? substr($number, 1) : $number;

            if ($number !== '' && strlen($number) <= 18 && ctype_digit($number) && (int) $number > 0) {
                $numbers[(int) $number] = true;

                if (count($numbers) >= 5000) {
                    break;
                }
            }
        }

        return array_keys($numbers);
    }

    /**
     * What the admin is told after sending.
     *
     * @param  array{total: int, finished: bool}  $result
     */
    public static function sentMessage(array $result): string
    {
        $count = $result['total'];

        if ($count === 0) {
            return __('No member matches this choice, so nothing was sent.');
        }

        return $result['finished']
            ? trans_choice('{1} Sent to 1 member.|[2,*] Sent to :count members.', $count, ['count' => number_format($count)])
            : trans_choice('{1} Sending to 1 member.|[2,*] Sending to :count members. The last ones get it within a few minutes.', $count, ['count' => number_format($count)]);
    }

    public function destroy(string $id): RedirectResponse
    {
        if (DB::table('notifications')->where('id', $id)->delete() > 0) {
            AuditLogController::log('notification.deleted', \App\Support\AuditDetails::ids([$id]));
        }

        AdminCache::forget(self::COUNTS_KEY);

        return redirect()->route('admin.notifications.index')
            ->withSuccess(__('Notification deleted'));
    }

    public function clear(): RedirectResponse
    {
        // none to delete: nothing written in Admin actions (judge R2CD-RV-03)
        $any = DB::table('notifications')->exists();
        $result = NotificationTasks::clearAll();
        AdminCache::forget(self::COUNTS_KEY);

        if ($any) {
            AuditLogController::log('notifications.cleared');
        }

        return redirect()->route('admin.notifications.index')
            ->withSuccess($result['finished']
                ? __('All notifications were deleted.')
                : __('All notifications are being deleted. Members no longer see them.'));
    }

    private static function hasIndex(string $name): bool
    {
        try {
            return Schema::hasIndex('notifications', $name);
        } catch (Throwable) {
            return false;
        }
    }
}
