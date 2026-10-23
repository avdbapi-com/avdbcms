<?php

namespace App\Http\Controllers\Admin;

use App\Support\AppVersion;
use App\Support\MediaBinary;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

class SystemController
{
    public function phpinfo(): View
    {
        // PHP Info
        ob_start();
        phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES | INFO_ENVIRONMENT);
        // The environment table holds .env values on hosts where putenv() reaches it: secrets are masked.
        $phpinfo = PhpInfoController::withoutSecrets((string) ob_get_clean());
        // Extract body only
        preg_match('/<body[^>]*>(.*?)<\/body>/si', $phpinfo, $matches);
        $phpinfoBody = $matches[1] ?? $phpinfo;

        // Extensions
        $extensions = get_loaded_extensions();
        sort($extensions);

        // PHP Settings
        $phpSettings = [
            'PHP Version' => PHP_VERSION,
            'PHP SAPI' => php_sapi_name(),
            'Server OS' => PHP_OS_FAMILY . ' (' . php_uname() . ')',
            'Max Execution Time' => ini_get('max_execution_time') . 's',
            'Memory Limit' => ini_get('memory_limit'),
            'Upload Max Filesize' => ini_get('upload_max_filesize'),
            'Post Max Size' => ini_get('post_max_size'),
            'Max Input Vars' => ini_get('max_input_vars'),
            'Max File Uploads' => ini_get('max_file_uploads'),
            'Display Errors' => ini_get('display_errors') ? 'On' : 'Off',
            'Error Reporting' => error_reporting(),
            'OPcache' => function_exists('opcache_get_status') ? 'Enabled' : 'Disabled',
            'Timezone' => date_default_timezone_get(),
            'Open Basedir' => ini_get('open_basedir') ?: 'None',
            'Disable Functions' => ini_get('disable_functions') ?: 'None',
            'Session Handler' => ini_get('session.save_handler'),
            'Session Path' => ini_get('session.save_path'),
            'cURL' => function_exists('curl_version') ? curl_version()['version'] : 'N/A',
            'GD' => extension_loaded('gd') ? 'Enabled' : 'Disabled',
            'Imagick' => extension_loaded('imagick') ? 'Enabled' : 'Disabled',
            'FFmpeg' => $this->ffmpegVersion(),
        ];

        // Laravel Info (AVDBCMS's own version first: Tools > Updates installs the next one)
        $laravelInfo = [
            'AVDBCMS version' => AppVersion::CURRENT,
            'Version' => app()->version(),
            'Environment' => config('app.env'),
            'Debug Mode' => config('app.debug') ? 'ON' : 'OFF',
            'URL' => config('app.url'),
            'Timezone' => config('app.timezone'),
            'Locale' => config('app.locale'),
            'Cache Driver' => config('cache.default'),
            'Session Driver' => config('session.driver'),
            'Queue Driver' => config('queue.default'),
            'Mail Driver' => config('mail.default'),
            'Log Channel' => config('logging.default'),
            'Filesystem' => config('filesystems.default'),
            'DB Connection' => config('database.default'),
            'DB Host' => config('database.connections.mysql.host'),
            'DB Name' => config('database.connections.mysql.database'),
            'Scout Driver' => config('scout.driver'),
            'Broadcast Driver' => config('broadcasting.default'),
        ];

        // Database Info
        $dbInfo = [];
        try {
            $version = DB::select('SELECT VERSION() as version');
            $dbInfo['Version'] = $version[0]->version ?? 'N/A';
            $dbInfo['Database'] = config('database.connections.mysql.database');
            $tables = DB::select('SHOW TABLE STATUS');
            $dbInfo['Tables'] = count($tables);
            $totalSize = 0;
            foreach ($tables as $t) {
                $totalSize += $t->Data_length + $t->Index_length;
            }
            $dbInfo['Total Size'] = round($totalSize / 1048576, 2) . ' MB';
            $dbInfo['Charset'] = DB::select("SHOW VARIABLES LIKE 'character_set_database'")[0]->Value ?? 'N/A';
            $dbInfo['Collation'] = DB::select("SHOW VARIABLES LIKE 'collation_database'")[0]->Value ?? 'N/A';
            $dbInfo['Max Connections'] = DB::select("SHOW VARIABLES LIKE 'max_connections'")[0]->Value ?? 'N/A';
        } catch (\Exception $e) {
            $dbInfo['Error'] = $e->getMessage();
        }

        // Disk Info
        $diskInfo = [
            'Total Space' => round(disk_total_space(base_path()) / 1073741824, 2) . ' GB',
            'Free Space' => round(disk_free_space(base_path()) / 1073741824, 2) . ' GB',
            'Used Space' => round((disk_total_space(base_path()) - disk_free_space(base_path())) / 1073741824, 2) . ' GB',
            'Usage' => round((1 - disk_free_space(base_path()) / disk_total_space(base_path())) * 100, 1) . '%',
        ];

        // Storage folders
        $storageFolders = [];
        $dirs = ['app/public', 'logs', 'framework/cache', 'framework/sessions', 'framework/views'];
        foreach ($dirs as $dir) {
            $path = storage_path($dir);
            if (is_dir($path)) {
                $size = 0;
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
                foreach ($files as $file) {
                    if ($file->isFile()) $size += $file->getSize();
                }
                $storageFolders[$dir] = round($size / 1048576, 2) . ' MB';
            }
        }

        return view('admin.system.index', compact(
            'phpSettings', 'extensions', 'laravelInfo', 'dbInfo', 'diskInfo', 'storageFolders', 'phpinfoBody'
        ));
    }

    private function ffmpegVersion(): string
    {
        if (!MediaBinary::canRunProcesses()) {
            return 'Not Available (proc_open disabled)';
        }

        try {
            $process = new Process([MediaBinary::ffmpeg(), '-version']);
            $process->setTimeout(8);
            $process->run();

            if (!$process->isSuccessful()) {
                return 'Not Found';
            }

            $line = strtok(trim($process->getOutput()), PHP_EOL);

            return $line ?: 'Not Found';
        } catch (\Throwable) {
            return 'Not Found';
        }
    }

    public function clearCache(string $type): RedirectResponse
    {
        switch ($type) {
            case 'all':
                Artisan::call('cache:clear');
                Artisan::call('config:clear');
                Artisan::call('route:clear');
                Artisan::call('view:clear');
                $msg = 'All caches cleared.';
                break;
            case 'config':
                Artisan::call('config:clear');
                $msg = 'Config cache cleared.';
                break;
            case 'route':
                Artisan::call('route:clear');
                $msg = 'Route cache cleared.';
                break;
            case 'view':
                Artisan::call('view:clear');
                $msg = 'View cache cleared.';
                break;
            case 'app':
                Artisan::call('cache:clear');
                $msg = 'Application cache cleared.';
                break;
            default:
                $msg = 'Unknown cache type.';
        }

        return redirect()->route('admin.system.index')->withSuccess($msg);
    }
}
