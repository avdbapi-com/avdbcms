<?php

namespace App\Notifications\Account;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Http\Controllers\Auth\PhoneVerification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The phone code (member design 6.17). Queued (account-11): saving the number or asking for a new code never waits
 * for the SMS service in the request. In the member's language (User::preferredLocale), with the code's
 * lifetime. On a local install the code goes by e-mail instead (no SMS costs while developing).
 */
class VerifyPhone extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's channels.
     *
     * @param User $notifiable
     * @return array|string
     */
    public function via(User $notifiable): array|string
    {
        return app()->isLocal() ? 'mail' : SmsChannel::class;
    }

    /**
     * Build the mail representation of the notification (local installs only).
     *
     * @param User $notifiable
     * @return MailMessage
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your :site phone code', ['site' => config('app.name')]))
            ->line($this->text($notifiable));
    }

    /**
     * Get the sms representation of the notification.
     */
    public function toSms(User $notifiable): SmsMessage
    {
        return (new SmsMessage)->line($this->text($notifiable));
    }

    /**
     * "Your Sandbox Site code is 12345. It works for 10 minutes." A counted line: Arabic says 10 minutes with another
     * word than 1, 2 or 11 minutes (R2A-040).
     */
    private function text(User $notifiable): string
    {
        return trans_choice('{1} Your :site code is :code. It works for :count minute.|[2,*] Your :site code is :code. It works for :count minutes.', PhoneVerification::CODE_MINUTES, [
            'site' => config('app.name'),
            'code' => (string) $notifiable->getPhoneCodeVerification(),
            'count' => PhoneVerification::CODE_MINUTES,
        ]);
    }
}
