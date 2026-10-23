<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.transactions.list', $this->listPage($request));
        }

        $transactions = DB::table('transactions')
            ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
            ->leftJoin('premiums', 'transactions.subscription_id', '=', 'premiums.id')
            ->select(
                'transactions.*',
                'users.username',
                'users.email as user_email'
            )
            ->orderByDesc('transactions.date')
            ->paginate(\App\Support\AdminPagination::perPage(null, 25));

        $totals = DB::table('transactions')->selectRaw('
            COUNT(*) as total,
            SUM(amount) / 100 as total_revenue,
            SUM(tax) / 100 as total_tax,
            SUM(fee) / 100 as total_fees,
            SUM(amount - tax - fee) / 100 as net_revenue,
            COUNT(DISTINCT user_id) as unique_customers
        ')->first();

        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $stats = (object) [
            'total' => (int) ($totals->total ?? 0),
            'total_revenue' => (float) ($totals->total_revenue ?? 0),
            'total_tax' => (float) ($totals->total_tax ?? 0),
            'total_fees' => (float) ($totals->total_fees ?? 0),
            'net_revenue' => (float) ($totals->net_revenue ?? 0),
            'today' => DB::table('transactions')->whereDate('date', $today)->count(),
            'today_revenue' => (float) DB::table('transactions')->whereDate('date', $today)->sum('amount') / 100,
            'this_month' => DB::table('transactions')->whereBetween('date', [$monthStart, $monthEnd])->count(),
            'month_revenue' => (float) DB::table('transactions')->whereBetween('date', [$monthStart, $monthEnd])->sum('amount') / 100,
            'unique_customers' => (int) ($totals->unique_customers ?? 0),
        ];

        $topCustomers = DB::table('transactions')
            ->join('users', 'transactions.user_id', '=', 'users.id')
            ->select('users.id', 'users.username', 'users.email', DB::raw('SUM(transactions.amount)/100 as total_spent'), DB::raw('COUNT(*) as transactions'))
            ->groupBy('users.id', 'users.username', 'users.email')
            ->orderByDesc('total_spent')
            ->limit(10)
            ->get();

        $monthExpression = $this->monthExpression();

        $monthlyRevenue = DB::table('transactions')
            ->selectRaw("{$monthExpression} as month, SUM(amount)/100 as revenue, COUNT(*) as count")
            ->groupByRaw($monthExpression)
            ->orderByDesc('month')
            ->limit(12)
            ->get();

        return view('admin.transactions.index', compact('transactions', 'stats', 'topCustomers', 'monthlyRevenue'));
    }

    /**
     * The new admin's list: 25 per page, the newest first, and the old page's numbers (totals, today, this month in
     * one query; the 12 months and the 10 best customers), worked out once a minute.
     */
    private function listPage(Request $request): array
    {
        $transactions = DB::table('transactions')
            ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
            ->select('transactions.id', 'transactions.user_id', 'transactions.payment_provider', 'transactions.stripe_id', 'transactions.date', 'transactions.amount', 'transactions.tax', 'transactions.fee', 'transactions.name', 'transactions.country', 'users.username', 'users.email as user_email')
            ->orderByDesc('transactions.date')
            ->paginate(AdminPagination::perPage($request, 25))
            ->withQueryString();

        $summary = AdminCache::remember('transactions.summary.v1', now()->addMinute(), function (): array {
            $today = Carbon::today();
            $month = Carbon::now()->startOfMonth();
            $row = DB::table('transactions')->selectRaw('
                COUNT(*) as total,
                SUM(amount) / 100 as revenue,
                SUM(amount - tax - fee) / 100 as net,
                SUM(tax) / 100 as tax,
                SUM(fee) / 100 as fees,
                COUNT(DISTINCT user_id) as customers,
                SUM(CASE WHEN date >= ? AND date < ? THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN date >= ? AND date < ? THEN amount ELSE 0 END) / 100 as today_revenue,
                SUM(CASE WHEN date >= ? AND date <= ? THEN amount ELSE 0 END) / 100 as month_revenue
            ', [$today, $today->copy()->addDay(), $today, $today->copy()->addDay(), $month, Carbon::now()->endOfMonth()])->first();
            $monthExpression = $this->monthExpression();

            return [
                'total' => (int) ($row->total ?? 0),
                'revenue' => (float) ($row->revenue ?? 0),
                'net' => (float) ($row->net ?? 0),
                'tax' => (float) ($row->tax ?? 0),
                'fees' => (float) ($row->fees ?? 0),
                'customers' => (int) ($row->customers ?? 0),
                'today' => (int) ($row->today ?? 0),
                'today_revenue' => (float) ($row->today_revenue ?? 0),
                'month_revenue' => (float) ($row->month_revenue ?? 0),
                'months' => DB::table('transactions')
                    ->selectRaw("{$monthExpression} as month, SUM(amount)/100 as revenue, COUNT(*) as count")
                    ->groupByRaw($monthExpression)
                    ->orderByDesc('month')
                    ->limit(12)
                    ->get()
                    ->map(fn ($month): array => ['month' => (string) $month->month, 'revenue' => (float) $month->revenue, 'count' => (int) $month->count])
                    ->all(),
                'customers_top' => DB::table('transactions')
                    ->join('users', 'transactions.user_id', '=', 'users.id')
                    ->select('users.id', 'users.username', DB::raw('SUM(transactions.amount)/100 as total_spent'), DB::raw('COUNT(*) as transactions'))
                    ->groupBy('users.id', 'users.username')
                    ->orderByDesc('total_spent')
                    ->limit(10)
                    ->get()
                    ->map(fn ($customer): array => ['id' => (int) $customer->id, 'username' => (string) $customer->username, 'spent' => (float) $customer->total_spent, 'count' => (int) $customer->transactions])
                    ->all(),
            ];
        });

        return compact('transactions', 'summary');
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('transactions')->where('id', $id)->delete();
        return redirect()->route('admin.transactions.index')->withSuccess('Transaction deleted.');
    }

    private function monthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', date)",
            'pgsql' => "to_char(date, 'YYYY-MM')",
            'sqlsrv' => "FORMAT(date, 'yyyy-MM')",
            default => "DATE_FORMAT(date, '%Y-%m')",
        };
    }
}
