<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminArtisan;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ArtisanController
{
    /**
     * The commands Tools > Commands may run, with their description in English (a translation key: the page shows it
     * in the admin's language; scripts/i18n/sources.json lists this constant so the translations are kept).
     */
    public const COMMANDS = [
        'cache:clear' => 'Clear application cache',
        'config:clear' => 'Clear config cache',
        'config:cache' => 'Cache config files',
        'route:clear' => 'Clear route cache',
        'route:cache' => 'Cache routes',
        'view:clear' => 'Clear compiled views',
        'view:cache' => 'Compile all views',
        'event:clear' => 'Clear cached events',
        'optimize' => 'Optimize the framework',
        'optimize:clear' => 'Clear all cached files',
        'storage:link' => 'Create storage symlink',
        'schedule:list' => 'List scheduled commands',
        'queue:restart' => 'Restart queue workers',
        'playback-progress:prune' => 'Prune expired playback progress signals',
        'views:prune-history' => 'Prune expired raw view history',
        'migrate:status' => 'Show migration status',
        'db:show' => 'Show database info',
    ];

    public function index(): View
    {
        return view('admin.artisan.index', [
            'commands' => self::COMMANDS,
            'output' => session('artisan_output'),
            'lastCommand' => session('artisan_last_command'),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $request->validate(['command' => 'required|string']);

        $command = trim($request->command);

        if (!array_key_exists($command, self::COMMANDS)) {
            return redirect()->route('admin.artisan.index')
                ->withErrors(['command' => __('This command cannot be run from here.')]);
        }

        // As on the command line (R1BR-01): config:cache and optimize cache the configuration files only, never the
        // admin's settings, and the page keeps the command's output.
        try {
            $output = AdminArtisan::call($command)['output'];
        } catch (\Throwable $e) {
            $output = __('Error: :error', ['error' => $e->getMessage()]);
        }

        AuditLogController::log('tools.command.run', $command);

        return redirect()->route('admin.artisan.index')
            ->with('artisan_output', $output)
            ->with('artisan_last_command', $command);
    }
}
