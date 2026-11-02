<?php

namespace App\Http\Controllers\Admin;

use App\Models\Transaction;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            // The new admin: the same member search, 25 per page, the members in one query; the totals in one query,
            // kept for a minute.
            $query = Transaction::query()->with('user:id,username,email')->latest('date');

            if ($request->filled('user')) {
                $query->whereHas('user', fn($q) => $q->where('username', 'like', '%'.$request->user.'%')
                    ->orWhere('email', 'like', '%'.$request->user.'%'));
            }

            return view('admin.invoices.list', [
                'invoices' => $query->paginate(AdminPagination::perPage($request, 25))->withQueryString(),
                // In cents, as stored: the page writes them in the site's currency (R1B-024; v2 of the key: v1 held units).
                'stats' => AdminCache::remember('invoices.totals.v2', now()->addMinute(), function (): array {
                    $row = DB::table('transactions')->selectRaw('COUNT(*) as total_count, SUM(amount) as total_amount, SUM(tax) as total_tax, SUM(fee) as total_fee')->first();

                    return ['count' => (int) ($row->total_count ?? 0), 'amount' => (int) ($row->total_amount ?? 0), 'tax' => (int) ($row->total_tax ?? 0), 'fee' => (int) ($row->total_fee ?? 0)];
                }),
            ]);
        }

        $query = Transaction::query()->with('user')->latest('date');

        if ($request->filled('user')) {
            $query->whereHas('user', fn($q) => $q->where('username', 'like', '%'.$request->user.'%')
                ->orWhere('email', 'like', '%'.$request->user.'%'));
        }

        // The totals in cents, 0 without any payment (SUM() of no rows is NULL, which the page printed through
        // number_format(null): a deprecation notice each, an error in PHP 9; R1B-024). The page writes them in the
        // site's currency, like the member's invoices.
        $row = DB::table('transactions')->selectRaw('COUNT(*) as total_count, SUM(amount) as total_amount, SUM(tax) as total_tax, SUM(fee) as total_fee')->first();
        $stats = (object) [
            'total_count' => (int) ($row->total_count ?? 0),
            'total_amount' => (int) ($row->total_amount ?? 0),
            'total_tax' => (int) ($row->total_tax ?? 0),
            'total_fee' => (int) ($row->total_fee ?? 0),
        ];

        return view('admin.invoices.index', [
            'invoices' => $query->paginate(\App\Support\AdminPagination::perPage(null, 20))->withQueryString(),
            'stats' => $stats,
        ]);
    }
}
