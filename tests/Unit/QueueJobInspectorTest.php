<?php

namespace Tests\Unit;

use App\Support\QueueJobInspector;
use Tests\TestCase;

class QueueJobInspectorTest extends TestCase
{
    public function test_video_processing_job_is_delete_protected(): void
    {
        $inspection = QueueJobInspector::inspectPayload([
            'displayName' => 'App\\Jobs\\ProcessVideo',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'command' => 'O:21:"App\\Jobs\\ProcessVideo":0:{}',
            ],
        ]);

        $this->assertTrue($inspection['delete_protected']);
        $this->assertSame('protected', $inspection['risk_level']);
    }

    public function test_realtime_broadcast_job_can_be_deleted(): void
    {
        $inspection = QueueJobInspector::inspectPayload([
            'displayName' => 'Illuminate\\Broadcasting\\BroadcastEvent',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'command' => 'O:39:"Illuminate\\Broadcasting\\BroadcastEvent":1:{s:5:"event";O:30:"App\\Events\\Video\\VideoUploaded":0:{}}',
            ],
        ]);

        $this->assertFalse($inspection['delete_protected']);
        $this->assertSame('low', $inspection['risk_level']);
        $this->assertSame('realtime', $inspection['cleanup_group']);
    }

    public function test_unknown_job_is_delete_protected(): void
    {
        $inspection = QueueJobInspector::inspectPayload([
            'displayName' => 'Vendor\\Package\\UnknownJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        ]);

        $this->assertTrue($inspection['delete_protected']);
        $this->assertSame('protected', $inspection['risk_level']);
    }
}
