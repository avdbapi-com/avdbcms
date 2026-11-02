<?php

namespace Tests\Feature;

use App\Notifications\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Security plan S29: the contact form's name and message are printed in the admin's mail as text
 * (no Markdown link or image from visitor text), a link in the name is refused like one in the message, in any case
 * (HTTPS://...), and the admin's spam words apply to the name too. The mail's From (the site, never the visitor's
 * address) is tested in ContactMailFromTest.
 */
class ContactMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.captcha_provider' => 'none']);
        Storage::fake('local');
        // The form's 3-a-minute limit is not what this test is about.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_a_link_in_the_name_or_a_link_in_capitals_in_the_message_is_refused(): void
    {
        Notification::fake();

        $this->postJson(route('contact.contact'), ['name' => 'x https://evil.example', 'email' => 'ann@example.test', 'message' => 'Hello, a normal message here.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->postJson(route('contact.contact'), ['name' => 'Ann', 'email' => 'ann@example.test', 'message' => 'see HTTPS://evil.example now'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);

        Notification::assertNothingSent();
    }

    public function test_a_blocked_word_in_the_name_is_refused(): void
    {
        Notification::fake();
        Storage::disk('local')->put('spams/words.txt', "casinobonus\n");

        $this->postJson(route('contact.contact'), ['name' => 'CasinoBonus Ann', 'email' => 'ann@example.test', 'message' => 'Hello, a normal message here.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name' => __('Spam detected')]);

        Notification::assertNothingSent();
    }

    public function test_a_normal_message_is_still_sent(): void
    {
        Notification::fake();

        $this->post(route('contact.contact'), ['name' => 'Nguyễn Văn A', 'email' => 'ann@example.test', 'message' => "Hello,\nwhere is the video [part 2]?"])
            ->assertRedirectToRoute('contact.show')
            ->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(Contact::class);
    }

    public function test_the_mail_prints_the_visitors_text_as_text(): void
    {
        $mail = (new Contact('[Open invoice]', 'ann@example.test', "First line [x](www.evil.example)\nSecond ![p](https://evil.example/p.png) line"))
            ->toMail(new AnonymousNotifiable());
        $html = (string) $mail->render();

        $this->assertDoesNotMatchRegularExpression('#<a\s[^>]*href="[^"]*evil\.example#i', $html, 'no link to evil');
        $this->assertDoesNotMatchRegularExpression('#<img\s[^>]*src="[^"]*evil\.example#i', $html, 'no remote image');

        $visible = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // the member mail words of 1.2.3 (member design 6.21): "New message from <name>"
        $this->assertStringContainsString('New message from [Open invoice]', $visible, 'the brackets print as typed');
        $this->assertStringContainsString('First line [x](www.evil.example)', $visible);
        $this->assertStringContainsString('Second ![p](https://evil.example/p.png) line', $visible);
        $this->assertMatchesRegularExpression('#First line \[x\]\(www\.evil\.example\)<br\s*/?>\s*Second#', $html, 'the line break stays');
    }
}
