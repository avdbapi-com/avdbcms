<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseController
{
    public function index(): View
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $dbName = $connection->getDatabaseName();

        try {
            $rawTables = $driver === 'mysql'
                ? $this->mysqlTableStats($dbName)
                : $this->portableTableStats($driver);
        } catch (\Throwable $e) {
            $rawTables = $this->portableTableStats($driver);
        }

        $tables = collect($rawTables)->map(function ($item) {
            $arr = (array) $item;
            $lower = [];
            foreach ($arr as $k => $v) {
                $lower[strtolower($k)] = $v;
            }
            return (object) [
                'table_name' => (string) ($lower['table_name'] ?? 'unknown'),
                'table_rows' => (int) ($lower['table_rows'] ?? 0),
                'data_mb' => (float) ($lower['data_mb'] ?? 0),
                'index_mb' => (float) ($lower['index_mb'] ?? 0),
                'total_mb' => (float) ($lower['total_mb'] ?? 0),
                'table_collation' => $lower['table_collation'] ?? null,
                'engine' => $lower['engine'] ?? 'InnoDB',
            ];
        })->all();

        $totalSize = collect($tables)->sum('total_mb');

        return view('admin.database.index', compact('tables', 'totalSize', 'dbName'));
    }

    public function optimize(): RedirectResponse
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('VACUUM');

            return redirect()->route('admin.database.index')->withSuccess('SQLite database vacuumed.');
        }

        if ($driver !== 'mysql') {
            return redirect()->route('admin.database.index')->withError("Optimize is not supported for the {$driver} driver.");
        }

        foreach (Schema::getTableListing() as $tableName) {
            DB::statement("OPTIMIZE TABLE `{$tableName}`");
        }

        return redirect()->route('admin.database.index')->withSuccess('All tables optimized.');
    }

    public function truncate(string $table): RedirectResponse
    {
        // Safety: only allow known tables to be truncated
        $allowed = ['activities', 'views', 'searches', 'notifications', 'failed_jobs', 'password_reset_tokens'];
        if (!in_array($table, $allowed)) {
            return redirect()->route('admin.database.index')->withError("Truncate not allowed for: {$table}");
        }
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table($table)->delete();
        } else {
            DB::statement("TRUNCATE TABLE `{$table}`");
        }

        return redirect()->route('admin.database.index')->withSuccess("Table `{$table}` truncated.");
    }

    public function migrate(): RedirectResponse
    {
        Artisan::call('migrate', ['--force' => true]);
        return redirect()->route('admin.database.index')->withSuccess('Migrations run successfully.');
    }

    private function mysqlTableStats(string $dbName): array
    {
        return DB::select("
            SELECT
                table_name,
                table_rows,
                ROUND(data_length / 1024 / 1024, 2) as data_mb,
                ROUND(index_length / 1024 / 1024, 2) as index_mb,
                ROUND((data_length + index_length) / 1024 / 1024, 2) as total_mb,
                table_collation,
                engine
            FROM information_schema.tables
            WHERE table_schema = ?
            ORDER BY (data_length + index_length) DESC
        ", [$dbName]);
    }

    private function portableTableStats(string $driver): array
    {
        return collect(Schema::getTableListing())
            ->map(function (string $table) use ($driver): object {
                return (object) [
                    'table_name' => $table,
                    'table_rows' => DB::table($table)->count(),
                    'data_mb' => 0,
                    'index_mb' => 0,
                    'total_mb' => 0,
                    'table_collation' => null,
                    'engine' => $driver,
                ];
            })
            ->sortByDesc('table_rows')
            ->values()
            ->all();
    }
}
