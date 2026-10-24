<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * 1.2.2: administrators (the site's owners) upload with the premium limits. The member plans are for members: with the
 * bundled free limits (10 videos, 500 MB per file, 5 GB) an owner whose imports put videos on the admin account would
 * otherwise be stopped on their own site.
 */
class AdminUploadLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user->fresh();
    }

    public function test_an_administrator_uploads_with_the_premium_limits(): void
    {
        config(['site.allow_uploads' => true, 'plans.free.max_uploads' => 2, 'plans.free.max_file_size' => 524288000,
            'plans.premium.max_file_size' => 2147483648]);
        $admin = $this->owner();
        Video::factory()->count(3)->create(['user_id' => $admin->id]);

        $this->assertFalse((bool) $admin->is_premium, 'no premium subscription');
        $this->assertSame('premium', $admin->plan);
        $this->assertTrue(Gate::forUser($admin)->allows('upload', Video::class), 'more videos than the free limit');
        $this->assertSame(2147483648, (int) config('plans.'.$admin->plan.'.max_file_size'));
    }

    public function test_a_free_member_keeps_the_free_limits(): void
    {
        config(['site.allow_uploads' => true, 'plans.free.max_uploads' => 2]);
        $member = User::factory()->create();
        Video::factory()->count(3)->create(['user_id' => $member->id]);

        $this->assertSame('free', $member->fresh()->plan);
        $this->assertFalse(Gate::forUser($member->fresh())->allows('upload', Video::class));
    }

    public function test_uploads_switched_off_stop_administrators_too(): void
    {
        config(['site.allow_uploads' => false]);

        $this->assertFalse(Gate::forUser($this->owner())->allows('upload', Video::class));
    }
}
