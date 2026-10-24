<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.1: the @size Blade directive (Premium page plan limits, member video sizes, admin Exports) prints whole
 * numbers with their zeros: the free plan's 500 MB max file size read "5 MB" before 1.2.1.
 *
 * Proposed for tests/Feature/ together with proposed/NumberFormat.patch (lane A does not own app/Support or tests/).
 */
class SizeDirectiveTest extends TestCase
{
    public function test_the_size_directive_prints_the_plan_limits_with_their_zeros(): void
    {
        config(['plans.free.max_file_size' => 524288000, 'plans.premium.max_file_size' => 2147483648, 'plans.free.max_videos_storage' => 5368709120]);

        $this->assertSame('500 MB', trim(Blade::render("@size(config('plans.free.max_file_size'))")));
        $this->assertSame('2 GB', trim(Blade::render("@size(config('plans.premium.max_file_size'))")));
        $this->assertSame('5 GB', trim(Blade::render("@size(config('plans.free.max_videos_storage'))")));
    }

    public function test_the_size_directive_keeps_small_whole_numbers_and_trims_decimals(): void
    {
        $this->assertSame('100 KB', trim(Blade::render('@size($bytes)', ['bytes' => 102400])));
        $this->assertSame('1,000 B', trim(Blade::render('@size($bytes)', ['bytes' => 1000])));
        $this->assertSame('2.5 MB', trim(Blade::render('@size($bytes)', ['bytes' => 2621440])));
        $this->assertSame('', trim(Blade::render('@size($bytes)', ['bytes' => null])));
    }
}
