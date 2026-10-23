<?php

namespace Tests\Unit;

use App\Console\Commands\ImportAvdbVideos;
use App\Models\AvdbImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class AvdbImportCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_cancels_when_tracking_run_was_deleted(): void
    {
        $run = AvdbImportRun::query()->create([
            'status' => 'running',
            'settings' => [],
            'started_at' => now(),
        ]);

        $command = new ImportAvdbVideos;
        $reflection = new ReflectionClass($command);
        $property = $reflection->getProperty('run');
        $property->setAccessible(true);
        $property->setValue($command, $run);

        $run->delete();

        $method = $reflection->getMethod('shouldCancel');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($command));
    }
}
