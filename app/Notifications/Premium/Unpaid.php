<?php

namespace App\Notifications\Premium;

use App\Models\User;
use App\Support\NumberFormat;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

class Unpaid extends Notification
{

    /**
     * The invoice transaction.
     *
     * @var integer
     */
    protected int $amount;

    /**
     * Create a new message instance.
     *
     * @param integer $amount
     * @return void
     */
    public function __construct(int $amount)
    {
        $this->amount = $amount;
    }

    /**
     * Get the notification's channels.
     *
     * @param User $notifiable
     * @return array|string
     */
    public function via(User $notifiable): array|string
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     *
     * @param User $notifiable
     * @return MailMessage
     */
    public function toMail(User $notifiable) : MailMessage
    {
        return $this->buildMailMessage($notifiable);
    }

    /**
     * Get the reset email notification mail message for the given URL.
     *
     * @param User $notifiable
     * @return MailMessage
     */
    protected function buildMailMessage(User $notifiable) : MailMessage
    {
        $formattedAmount = $this->amount > 0
            ? NumberFormat::currency(
                $this->amount / 100,
                in: (string) (config('cashier.currency') ?: 'usd'),
                locale: app()->getLocale()
            )
            : '';

        $subject = $formattedAmount !== ''
            ? Lang::get('Your last payment of :amount to :app was unsuccessful', [
                'amount' => $formattedAmount,
                'app' => config('app.name'),
            ])
            : Lang::get('Your last payment to :app was unsuccessful', [
                'app' => config('app.name'),
            ]);

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mails.premium.unpaid', [
                'notifiable' => $notifiable,
                'url' => $notifiable->billingManagementUrl(route('user.edit')),
                'amount' => $this->amount
            ]);
    }
}
