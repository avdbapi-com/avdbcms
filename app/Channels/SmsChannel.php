<?php

namespace App\Channels;

use App\Models\User;
use App\Services\BrevoService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class SmsChannel
{
    /**
     * Send the given notification.
     */
    public function send(User $notifiable, Notification $notification): void
    {
        /**
         * @var SmsMessage $sms
         */
        $sms = $notification->toSms($notifiable);
        $phone = $notifiable->getPhone();

        if (! $phone) {
            Log::warning('SMS notification skipped because the user has no phone number.', [
                'user_id' => $notifiable->getKey(),
                'notification' => $notification::class,
            ]);

            return;
        }

        $brevoService = app(BrevoService::class);

        $brevoService->sendTransactionalSMS((string) $phone, $sms->getLines());
    }
}
