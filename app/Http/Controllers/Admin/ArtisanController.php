<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class ArtisanController
{
    private array $allowedCommands = [
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
            'commands' => $this->allowedCommands,
            'output' => session('artisan_output'),
            'lastCommand' => session('artisan_last_command'),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $request->validate(['command' => 'required|string']);

        $command = trim($request->command);

        if (!array_key_exists($command, $this->allowedCommands)) {
            return redirect()->route('admin.artisan.index')
                ->withErrors(['command' => 'This command is not in the allowed list.']);
        }

        try {
            Artisan::call($command);
            $output = Artisan::output();
        } catch (\Exception $e) {
            $output = 'Error: ' . $e->getMessage();
        }

        return redirect()->route('admin.artisan.index')
            ->with('artisan_output', $output)
            ->with('artisan_last_command', $command);
    }
}
