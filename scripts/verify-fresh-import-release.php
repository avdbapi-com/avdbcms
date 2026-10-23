<?php

// Run against an isolated, unpacked release with process functions disabled.
// No .env, persistent database, import request, or queue worker is used.
$release = realpath($argv[1] ?? '');
if (! $release || ! is_file($release.'/vendor/autoload.php') || is_file($release.'/.env')) {
    throw new RuntimeException('An isolated release without .env is required.');
}
if (function_exists('proc_open') || function_exists('exec') || function_exists('popen')) {
    throw new RuntimeException('Disable process execution before running this verification.');
}

foreach ([
    'APP_BASE_PATH' => $release,
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'CACHE_DRIVER' => 'array',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'database',
    'MAIL_MAILER' => 'array',
    'XVID_IMPORT_MODE' => 'queue',
    'AVDB_IMPORT_MODE' => 'queue',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require $release.'/vendor/autoload.php';
$app = require $release.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:' || config('queue.default') !== 'database') {
    throw new RuntimeException('Verification isolation settings were not applied.');
}

$metadataRequests = 0;
$importRequests = 0;
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function (Illuminate\Http\Client\Request $request) use (&$metadataRequests, &$importRequests) {
    $url = parse_url($request->url());
    $isMetadataRead = $request->method() === 'GET'
        && ($url['host'] ?? null) === 'xvidapi.com'
        && rtrim($url['path'] ?? '', '/') === '/api.php/provide/vod'
        && $request['ac'] === 'list'
        && $request['at'] === 'json'
        && (string) $request['pagesize'] === '100';

    if ($isMetadataRead) {
        $metadataRequests++;

        return Illuminate\Support\Facades\Http::response([
            'code' => 1,
            'total' => 250,
            'pagecount' => 3,
            'limit' => 100,
        ]);
    }

    $importRequests++;

    return Illuminate\Support\Facades\Http::response([], 503);
});

foreach (['migrate', 'db:seed'] as $command) {
    if (Illuminate\Support\Facades\Artisan::call($command, ['--force' => true]) !== 0) {
        throw new RuntimeException('Fresh database setup failed: '.$command);
    }
}

App\Http\Controllers\Admin\XvidImportController::ensureDefaultScheduleExists();
for ($attempt = 0; $attempt < 3; $attempt++) {
    (new App\Http\Controllers\Admin\XvidImportController())->index();
    foreach (['xvid:schedules:run', 'avdb:schedules:run'] as $command) {
        if (Illuminate\Support\Facades\Artisan::call($command) !== 0) {
            throw new RuntimeException('Scheduler verification failed: '.$command);
        }
    }
}

$result = [
    'database' => 'sqlite :memory:',
    'scheduler_passes_per_provider' => 3,
    'active_xvid_schedules' => App\Models\XvidImportSchedule::where('is_active', true)->count(),
    'active_avdb_schedules' => App\Models\AvdbImportSchedule::where('enabled', true)->count(),
    'xvid_runs' => App\Models\XvidImportRun::count(),
    'avdb_runs' => App\Models\AvdbImportRun::count(),
    'queued_jobs' => $db->table('jobs')->count(),
    'videos' => App\Models\Video::count(),
    'metadata_requests' => $metadataRequests,
    'import_requests' => $importRequests,
];
foreach (['active_xvid_schedules', 'active_avdb_schedules', 'xvid_runs', 'avdb_runs', 'queued_jobs', 'videos', 'import_requests'] as $field) {
    if ($result[$field] !== 0) {
        throw new RuntimeException('Fresh release was not idle: '.json_encode($result));
    }
}
if ($result['metadata_requests'] !== 1) {
    throw new RuntimeException('The explicit admin view did not make exactly one cached metadata read: '.json_encode($result));
}
$schedule = App\Models\XvidImportSchedule::firstOrFail();
$schedule->update(['is_active' => true]);
if (Illuminate\Support\Facades\Artisan::call('xvid:schedules:run') !== 0) {
    throw new RuntimeException('Explicitly enabled schedule failed.');
}
$optIn = [
    'xvid_runs' => App\Models\XvidImportRun::count(),
    'queued_jobs' => $db->table('jobs')->count(),
    'avdb_runs' => App\Models\AvdbImportRun::count(),
    'videos' => App\Models\Video::count(),
    'metadata_requests' => $metadataRequests,
    'import_requests' => $importRequests,
];
if ($optIn !== ['xvid_runs' => 1, 'queued_jobs' => 1, 'avdb_runs' => 0, 'videos' => 0, 'metadata_requests' => 1, 'import_requests' => 0]) {
    throw new RuntimeException('Explicit opt-in did not queue exactly one import: '.json_encode($optIn));
}
echo json_encode(['passed' => true, 'fresh_install' => $result, 'after_explicit_enable' => $optIn], JSON_PRETTY_PRINT).PHP_EOL;
