<?php

namespace App\Providers;

use App\Logs\CronLog;
use App\Logs\ReadableLogViewer;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\LogViewerService;
use Illuminate\Support\ServiceProvider;

class LogServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Tools > Logs lists only the log files PHP can read: a web server log the site may not open answered 500 on
        // Linux hosts (R1BR-02). The package's 'log-viewer' binding builds this class through the container.
        $this->app->bind(LogViewerService::class, ReadableLogViewer::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        LogViewer::auth(function ($request) {
            return $request->user() && $request->user()->is_admin;
        });

        LogViewer::extend('cron', CronLog::class);
    }
}
