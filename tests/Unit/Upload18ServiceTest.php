<?php

namespace Tests\Unit;

use App\Services\Upload18Service;
use PHPUnit\Framework\TestCase;

class Upload18ServiceTest extends TestCase
{
    public function test_duration_to_seconds_supports_common_upload18_formats(): void
    {
        $this->assertSame(3723, Upload18Service::durationToSeconds('1h 2m 3s'));
        $this->assertSame(3723, Upload18Service::durationToSeconds('1:02:03'));
        $this->assertSame(123, Upload18Service::durationToSeconds('02:03'));
        $this->assertSame(46, Upload18Service::durationToSeconds(45.8));
    }

    public function test_duration_to_seconds_rejects_empty_or_invalid_values(): void
    {
        $this->assertNull(Upload18Service::durationToSeconds(null));
        $this->assertNull(Upload18Service::durationToSeconds(''));
        $this->assertNull(Upload18Service::durationToSeconds('not a duration'));
        $this->assertNull(Upload18Service::durationToSeconds(0));
    }
}
