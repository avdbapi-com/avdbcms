<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserAdminFlagTest extends TestCase
{
    public function test_admin_flag_is_true_only_for_explicit_true_values(): void
    {
        foreach ([true, 1, '1'] as $value) {
            $user = new User();
            $user->forceFill(['is_admin' => $value]);

            $this->assertTrue($user->is_admin);
        }

        foreach ([false, 0, '0', null, ''] as $value) {
            $user = new User();
            $user->forceFill(['is_admin' => $value]);

            $this->assertFalse($user->is_admin);
        }
    }
}
