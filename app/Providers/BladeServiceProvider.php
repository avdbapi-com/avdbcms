<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class BladeServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        Blade::directive('size', function ($expression) {
            return "<?php echo is_null($expression) ? '' : \\App\\Support\\NumberFormat::fileSize($expression) ?>";
        });

        Blade::directive('money', function ($centimes) {
            return "<?php echo \\App\\Support\\NumberFormat::currency($centimes / 100, in: 'EUR', locale: 'fr') ?>";
        });

        Blade::directive('abbreviate', function ($number) {
            return "<?php echo \\App\\Support\\NumberFormat::abbreviate($number, maxPrecision: 1) ?>";
        });
    }
}
