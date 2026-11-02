<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Security plan S29 (app/Notifications/Contact.php): the contact form's mail to the admin goes out from
 * the site's own address (mail.from), never from the address a visitor typed (which could be a payment provider's, and
 * fails SPF/DMARC); Reply-To is the visitor, so Reply still reaches them.
 */
class ContactMailFromTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_mail_is_sent_from_the_site_and_reply_goes_to_the_visitor(): void
    {
        config([
            'site.captcha_provider' => 'none',
            'mail.default' => 'array',
            'mail.from.address' => 'site@victim-site.example',
            'mail.from.name' => 'Victim Site',
            'site.admin_email' => 'owner@victim-site.example',
        ]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->post(route('contact.contact'), ['name' => 'Ann', 'email' => 'ann@example.test', 'message' => 'Hello, a normal message here.'])
            ->assertRedirectToRoute('contact.show')
            ->assertSessionHasNoErrors();

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();

        $this->assertSame(['site@victim-site.example'], array_map(fn ($address) => $address->getAddress(), $email->getFrom()));
        $this->assertSame([['ann@example.test', 'Ann']], array_map(fn ($address) => [$address->getAddress(), $address->getName()], $email->getReplyTo()));
        $this->assertSame(['owner@victim-site.example'], array_map(fn ($address) => $address->getAddress(), $email->getTo()));
    }
}
