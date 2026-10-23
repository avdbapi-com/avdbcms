<?php

namespace App\Charts;

use App\Models\Transaction;
use App\Support\SafeCache;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use IcehouseVentures\LaravelChartjs\Builder;
use IcehouseVentures\LaravelChartjs\Facades\Chartjs;
use Illuminate\Support\Facades\DB;

class PremiumEarningsChart
{
    public string $name = 'premium_earnings_chart';
    public string $type = 'line';

    private const int CACHE_TTL_SECONDS = 600;

    private ?Carbon $firstTransactionDate = null;

    public function build(): Builder
    {
        $this->firstTransactionDate = $this->loadFirstTransactionDate();

        return Chartjs::build()
            ->name($this->name)
            ->type($this->type)
            ->labels($this->labels())
            ->datasets($this->data())
            ->options($this->options())
            ->optionsRaw($this->options());
    }

    private function data(): array
    {
        if (!$this->firstTransactionDate) {
            return [];
        }

        $cacheKey = 'admin.chart.premium_earnings.data.' . $this->firstTransactionDate->format('Y-m');
        $dataset = SafeCache::remember(
            $cacheKey,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn () => $this->loadDataPoints()
        );

        return [
            [
                'backgroundColor' => 'rgba(53, 162, 235, 0.5)',
                'borderColor' => 'rgb(53, 162, 235)',
                'pointRadius' => 1,
                'fill' => true,
                'data' => $dataset,
            ]
        ];
    }

    private function labels(): array
    {
        if (!$this->firstTransactionDate) {
            return [];
        }

        $cacheKey = 'admin.chart.premium_earnings.labels.' . $this->firstTransactionDate->format('Y-m');

        return SafeCache::remember(
            $cacheKey,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn () => collect(CarbonPeriod::since($this->firstTransactionDate)->month()->until(now()->endOfMonth()))
                ->map(fn ($date) => $date->format('M Y'))
                ->toArray()
        );
    }

    private function options(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
                'tooltip' => [
                    'displayColors' => false,
                    'borderColor' => 'black',
                    'bodyFont' => [
                        'weight' => 'bold',
                        'size' => 17,
                    ],
                    'borderWidth' => 2,
                ],
            ],
        ];
    }

    private function loadDataPoints(): array
    {
        return DB::query()
            ->selectRaw("DATE_FORMAT(m.month_start, '%Y-%m') AS month")
            ->selectRaw('COALESCE(SUM(t.amount), 0) / 100 AS total_amount')
            ->from('months', 'm')
            ->leftJoin('transactions as t', DB::raw("DATE_FORMAT(t.date, '%Y-%m')"), '=', DB::raw("DATE_FORMAT(m.month_start, '%Y-%m')"))
            ->groupBy('m.month_start')
            ->orderBy('m.month_start')
            ->withRecursiveExpression(
                'months',
                DB::query()
                    ->selectRaw("DATE('" . $this->firstTransactionDate->format('Y-m-d') . "') AS month_start")
                    ->unionAll(
                        DB::query()
                            ->selectRaw('DATE_ADD(month_start, INTERVAL 1 MONTH)')
                            ->from('months')
                            ->where('month_start', '<', now()->format('Y-m-d'))
                    ),
            )
            ->get()
            ->pluck('total_amount')
            ->toArray();
    }

    private function loadFirstTransactionDate(): ?Carbon
    {
        if ($this->firstTransactionDate) {
            return $this->firstTransactionDate;
        }

        $cached = SafeCache::remember(
            'admin.chart.premium_earnings.first_date',
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn () => Transaction::query()->oldest('date')->value('date')
        );

        return $cached !== null ? Carbon::parse($cached) : null;
    }
}
