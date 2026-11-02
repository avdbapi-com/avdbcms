<?php

namespace App\Notifications\Premium;

use App\Notifications\Support\MemberMail;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\Support\BellNotification;
use App\Notifications\Support\NotificationText;
use App\Notifications\Support\Realtime;
use App\Services\InvoiceService;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

class Invoice extends Notification
{
    use BellNotification;

    /**
     * The invoice transaction.
     *
     * @var Transaction
     */
    protected Transaction $transaction;

    /**
     * Create a new message instance.
     *
     * @param Transaction $transaction
     * @return void
     */
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    /**
     * Get the notification's channels: an e-mail and the bell (pushed live when the site has a live broadcaster).
     *
     * @param User $notifiable
     * @return array|string
     */
    public function via(User $notifiable): array|string
    {
        return Realtime::channels(['mail', 'database']);
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
        $message = (new MailMessage)
            ->subject(MemberMail::invoice($notifiable, $this->transaction)['subject'])
            ->view(['mails.premium.invoice', 'mails.text.premium.invoice'], [
                'notifiable' => $notifiable,
                'transaction' => $this->transaction
            ]);

        // The receipt PDF of this payment, read by its path on the site's disk: the file's full path (invoice_url) was
        // read as a path on the disk, so every receipt e-mail carried an empty PDF. A missing PDF, or one that is not this
        // payment's current one, is made first (InvoiceService::ensurePdf(), R1-027); without a PDF the e-mail still
        // links to the invoices and never carries an empty file. Named with the invoice number the PDF prints (R1-117).
        if (app(InvoiceService::class)->ensurePdf($this->transaction)) {
            // the name and type go on the attachment itself: MailMessage::attach() takes no options with an Attachment
            $message->attach(Attachment::fromStorage($this->transaction->invoice_path)
                ->as(InvoiceService::number($this->transaction).'.pdf')
                ->withMime('application/pdf'));
        }

        return $message;
    }

    /**
     * The bell's data: "Your invoice from <site> is ready." (translated when shown) and the invoice's page.
     *
     * @param  User $notifiable
     * @return array
     */
    public function toArray(User $notifiable) : array
    {
        return NotificationText::data('invoice_ready', [
            'site' => config('app.name'),
        ], [
            'url' => route('user.invoices.show', $this->transaction),
            'created_at' => now(),
            'is_read' => false,
        ]);
    }
}
