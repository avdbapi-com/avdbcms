<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The contact page's words and its picture:
 *  - one name per field, the site's own word "e-mail" ("Your e-mail", not "Your email" next to the sign-in pages'
 *    "E-mail"), and errors that name the field the same way ("Enter your e-mail.", not "The Email field is required."
 *    next to "The name field is required.");
 *  - the answer after sending is a plain sentence without "!";
 *  - the side picture (shown from 1200px, at most 640 CSS pixels wide) is 1280 x 1280 for sharp screens and a fraction
 *    of its old 1.09 MB;
 *  - "cannot" is one word on the 404 page and wherever the site said "can not".
 */
class ContactPageWordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.captcha_provider' => 'none', 'site.honeypot_enabled' => true, 'cache.limiter' => 'array']);
        Cache::flush();
    }

    public function test_the_fields_and_their_errors_use_one_name(): void
    {
        $html = (string) $this->get('/contact')->assertOk()->getContent();
        $this->assertStringContainsString('<label class="mk-label" for="email">'.e(__('Your e-mail')).'</label>', $html);
        $this->assertStringNotContainsString('>'.e(__('Your email')).'<', $html);

        $this->from('/contact')->post('/contact', [])->assertRedirect('/contact');
        $errors = session('errors');
        $this->assertSame(__('Enter your name.'), $errors->first('name'));
        $this->assertSame(__('Enter your e-mail.'), $errors->first('email'));
        $this->assertSame(__('Write your message.'), $errors->first('message'));

        $this->from('/contact')->post('/contact', ['name' => 'Al', 'email' => 'not-an-address', 'message' => 'short']);
        $errors = session('errors');
        $min = (int) config('validation.contact.name.min');
        $this->assertSame(trans_choice('{1} Your name needs at least :count character.|[2,*] Your name needs at least :count characters.', $min, ['count' => $min]), $errors->first('name'));
        $this->assertSame(__('Enter a valid e-mail, like name@example.com.'), $errors->first('email'));
        $messageMin = (int) config('validation.contact.message.min');
        $this->assertSame(trans_choice('{1} Your message needs at least :count character.|[2,*] Your message needs at least :count characters.', $messageMin, ['count' => $messageMin]), $errors->first('message'));
        foreach (['name', 'email', 'message'] as $field) {
            $this->assertStringNotContainsString('The ', $errors->first($field), $field.': no "The ... field" wording');
        }
    }

    public function test_the_answer_after_sending_is_plain(): void
    {
        Notification::fake();

        $this->post('/contact', ['name' => 'Anton', 'email' => 'visitor@example.test', 'message' => 'A question about my account.'])
            ->assertRedirectToRoute('contact.show')
            ->assertSessionHas('success', __('Your message is sent. We will answer you by e-mail.'));
        $this->assertStringNotContainsString('!', (string) session('success'));
    }

    public function test_the_side_picture_is_sharp_and_light(): void
    {
        foreach (['public/images/pages/contact.png', 'images/pages/contact.png'] as $path) {
            $file = base_path($path);
            $this->assertFileExists($file);
            [$width, $height, $type] = getimagesize($file);
            $this->assertSame([1280, 1280, IMAGETYPE_PNG], [$width, $height, $type], $path);
            $this->assertLessThan(250 * 1024, filesize($file), $path.' is a fraction of the old 1.09 MB');
        }
        $this->assertFileEquals(base_path('public/images/pages/contact.png'), base_path('images/pages/contact.png'));
    }

    public function test_cannot_is_one_word(): void
    {
        $html = (string) $this->get('/@doesnotexist999-cannot-probe')->assertNotFound()->getContent();
        $this->assertStringContainsString(e(__('We cannot find this page')), $html);
        $this->assertStringNotContainsString('can not', $html);

        foreach ([
            'resources/views/errors/404.blade.php',
            'resources/views/users/profile/sections/delete.blade.php',
            'app/Http/Controllers/PremiumController.php',
        ] as $file) {
            $code = (string) file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression("/__\\('[^']*\\bcan not\\b/", $code, $file);
        }
    }
}
