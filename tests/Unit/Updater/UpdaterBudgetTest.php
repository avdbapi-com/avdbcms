<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\Updater;
use Tests\TestCase;

/**
 * JUDGE-1: the deadline of one pre-swap step is at most BUDGET seconds from now and never nearer than 8 s to when PHP's
 * own time limit would end the request. The old budget() subtracted 8 s from the whole max_execution_time without the
 * time the request had already spent before the step began, so a step could run past the limit where PHP cannot raise
 * it (one auto-retried 500 during an Undo in the e2e). stepDeadline() counts that boot time, because phpLimitEnds() is
 * the moment the limit ends, not a span from now.
 */
class UpdaterBudgetTest extends TestCase
{
    public function test_a_step_stops_8_seconds_before_php_s_limit_when_little_time_is_left(): void
    {
        $now = 1_000.0;

        // 15 s of the request's limit are left (e.g. a 30 s limit, 15 s already spent on boot): the step gets 15 - 8 = 7,
        // not the full BUDGET, so it stops before the host kills the request.
        $this->assertEqualsWithDelta($now + 7.0, Updater::stepDeadline($now, $now + 15.0), 0.0001);

        // Only 5 s left: the formula wants to stop 3 s ago; budget()'s own floor (not this method) keeps a minimum.
        $this->assertEqualsWithDelta($now - 3.0, Updater::stepDeadline($now, $now + 5.0), 0.0001);
    }

    public function test_a_whole_budget_when_php_s_limit_is_far_or_unknown(): void
    {
        $now = 1_000.0;

        // No limit PHP can tell (the console, a host that set none, or set_time_limit pushed it far out): the full budget.
        $this->assertEqualsWithDelta($now + Updater::BUDGET, Updater::stepDeadline($now, null), 0.0001);

        // A limit far away (100 s left): BUDGET still caps it, not 100 - 8.
        $this->assertEqualsWithDelta($now + Updater::BUDGET, Updater::stepDeadline($now, $now + 100.0), 0.0001);

        // Exactly BUDGET + 8 s left: the two caps meet at BUDGET.
        $this->assertEqualsWithDelta($now + Updater::BUDGET, Updater::stepDeadline($now, $now + Updater::BUDGET + 8.0), 0.0001);
    }
}
