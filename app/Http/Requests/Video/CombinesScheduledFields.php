<?php

namespace App\Http\Requests\Video;

use App\Enums\VideoStatus;

/**
 * Scheduling on Video details and the draft page (member design 6.4, studio-8).
 *
 * The page sends two native fields, scheduled_date (Y-m-d) and scheduled_time (H:i), drawn by every browser (no
 * datetime-local, which showed nothing in Safari). They are combined into scheduled_at in the SITE's time zone
 * (config app.timezone: the time the member picked is the time on the site's clock, as the page says next to the
 * fields), then the existing rules check it (required, a date, after now). A request that sends scheduled_at itself
 * (older forms, the API, the tests) keeps it. The two fields are never part of the validated data (no column).
 */
trait CombinesScheduledFields
{
    protected function combineScheduledFields(): void
    {
        if ((string) $this->input('status') !== (string) VideoStatus::PLANNED->value) {
            return;
        }

        $date = $this->input('scheduled_date');

        if (! is_string($date) || trim($date) === '') {
            return;
        }

        $date = trim($date);
        $time = $this->input('scheduled_time');
        $time = is_string($time) ? trim($time) : '';

        // A browser may send the seconds ("20:00:00"); a missing or broken part makes the date rule answer.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $m) !== 1) {
            $this->merge(['scheduled_at' => 'invalid']);

            return;
        }

        $this->merge(['scheduled_at' => $date.' '.$m[1].':'.$m[2]]);
    }

    /** @return array<string, string> */
    protected function scheduledMessages(): array
    {
        return [
            'scheduled_at.required' => __('Pick the day and the time it goes public.'),
            'scheduled_at.date' => __('Pick a day and a time, for example 1 November at 20:00.'),
            'scheduled_at.after' => __('Pick a day and a time that are still to come.'),
        ];
    }

    /** @return array<string, string> */
    protected function scheduledAttributes(): array
    {
        return ['scheduled_at' => __('Day and time')];
    }
}
