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
                'stats' => AdminCache::remember('invoices.totals.v1', now()->addMinute(), function (): array {
                    $row = DB::table('transactions')->selectRaw('COUNT(*) as total_count, SUM(amount) / 100 as total_amount, SUM(tax) / 100 as total_tax, SUM(fee) / 100 as total_fee')->first();

                    return ['count' => (int) ($row->total_count ?? 0), 'amount' => (float) ($row->total_amount ?? 0), 'tax' => (float) ($row->total_tax ?? 0), 'fee' => (float) ($row->total_fee ?? 0)];
                }),
            ]);
        }

        $query = Transaction::query()->with('user')->latest('date');

        if ($request->filled('user')) {
            $query->whereHas('user', fn($q) => $q->where('username', 'like', '%'.$request->user.'%')
                ->orWhere('email', 'like', '%'.$request->user.'%'));
        }

        $stats = DB::table('transactions')->selectRaw('
            COUNT(*) as total_count,
            SUM(amount) / 100 as total_amount,
            SUM(tax) / 100 as total_tax,
            SUM(fee) / 100 as total_fee
        ')->first();

        return view('admin.invoices.index', [
            'invoices' => $query->paginate(\App\Support\AdminPagination::perPage(null, 20))->withQueryString(),
            'stats' => $stats,
        ]);
    }
}
