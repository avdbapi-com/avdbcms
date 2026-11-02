<?php

namespace App\Notifications\Premium;

use App\Notifications\Support\MemberMail;
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
        // the amount as the payment service counts it (cents; whole units for JPY and the like), with the currency
        // symbol, written the way the mail's language writes money (judge R1-133: "EUR 5.00" in every language)
        $formattedAmount = $this->amount > 0
            ? NumberFormat::money(
                $this->amount,
                (string) (config('cashier.currency') ?: 'usd'),
                app()->getLocale(),
                precision: 2
            )
            : '';

        // Account settings in the member's language (judge R1-051), or the payment service's page that comes back to it
        $url = (string) $notifiable->billingManagementUrl(MemberMail::link('user.edit'));

        return (new MailMessage)
            ->subject(MemberMail::unpaid($notifiable, $url, $formattedAmount)['subject'])
            ->view(['mails.premium.unpaid', 'mails.text.premium.unpaid'], [
                'notifiable' => $notifiable,
                'url' => $url,
                'amount' => $formattedAmount,
            ]);
    }
}
