<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_factory_make_does_not_query_database_for_slug(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        User::factory()->make();

        $this->assertSame([], DB::getQueryLog());
    }
}
