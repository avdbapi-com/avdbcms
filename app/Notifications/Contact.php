<?php

namespace App\Notifications;

use App\Notifications\Support\MemberMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Notifications\AnonymousNotifiable;


class Contact extends Notification
{
    public string $name;
    public string $email;
    public string $message;


    /**
     * Create a notification instance.
     *
     * @param string $name
     * @param string $email
     * @param string $message
     * @return void
     */
    public function __construct(string $name, string $email, string $message)
    {
        $this->name = $name;
        $this->email = $email;
        $this->message = $message;
    }
    /**
     * Get the notification's channels.
     *
     * @param AnonymousNotifiable $notifiable
     * @return array|string
     */
    public function via(AnonymousNotifiable $notifiable): array|string
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     *
     * @param AnonymousNotifiable $notifiable
     * @return MailMessage
     */
    public function toMail(AnonymousNotifiable $notifiable): MailMessage
    {
        return $this->buildMailMessage($notifiable);
    }

    /**
     * Get the contact email notification mail message.
     *
     * @param AnonymousNotifiable $notifiable
     * @return MailMessage
     */
    protected function buildMailMessage(AnonymousNotifiable $notifiable): MailMessage
    {
        $ip = (string) request()->ip();

        return (new MailMessage)
            ->subject(MemberMail::contact($this->name, $this->email, $this->message, $ip)['subject'])
            // Sent from the site's own address (mail.from): a visitor's address as From fails SPF/DMARC, gets the mail
            // refused, and lets anybody pose as a payment provider (security plan S29). Reply still reaches the visitor.
            ->replyTo($this->email, $this->name)
            ->view(['mails.contact', 'mails.text.contact'], [
                'name' => $this->name,
                'email' => $this->email,
                'contactMessage' => $this->message,
                'ip' => $ip,
            ]);
    }
}
