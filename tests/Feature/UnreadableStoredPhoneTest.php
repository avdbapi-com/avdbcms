<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Account\VerifyPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 1.2.3 (round 1b, R1R-08): a stored phone number the phone library cannot place (an imported database, a number saved
 * before newer phone metadata, a national number without its country code) counts as no number. Before, the users.phone
 * cast threw "Queried value for phone is not in international format" as soon as anything read $user->phone, so
 * Account settings, the phone code routes, "Download my data" and the queued code answered 500 for that member.
 * Now it reads as null for showing and sending (the member can enter a new number), and the stored value itself is
 * never changed by reading it or by saving anything else.
 */
class UnreadableStoredPhoneTest extends TestCase
{
    use RefreshDatabase;

    /** Values the cast cannot place: a national number, a number no region has, an unused country code, junk. */
    private const UNREADABLE = ['0612345678', '+15550100', '+9991234567', 'abc'];

    protected function setUp(): void
    {
        parent::setUp();

        // a site that can send texts (App\Support\SmsAvailability::canSend() reads this key)
        config(['services.brevo.api_key' => 'xkeysib-test-0123456789']);
        Cache::flush();
    }

    public function test_an_unreadable_stored_number_reads_as_no_number_and_stays_as_it_was(): void
    {
        foreach (self::UNREADABLE as $stored) {
            $member = $this->memberWithStoredPhone($stored, verified: true);

            $this->assertNull($member->phone, $stored);
            $this->assertNull($member->getPhone(), $stored);
            $this->assertNull($member->phoneInternational(), $stored);
            $this->assertNull($member->phoneNational(), $stored);
            $this->assertNull($member->stripePhone(), $stored.': never sent to the payment provider');
            $this->assertNull($member->toArray()['phone'], $stored.': serialised as no number');
            $this->assertSame($stored, $member->getRawOriginal('phone'), $stored);

            // saving something else keeps the stored value byte for byte (a read never rewrites the row)
            $member->forceFill(['description' => 'Updated '.$stored])->save();
            $this->assertSame($stored, DB::table('users')->where('id', $member->id)->value('phone'), $stored);
        }

        // a readable number still reads as the library's number
        $readable = $this->memberWithStoredPhone('+33612345678');
        $this->assertSame('+33612345678', $readable->phone?->formatE164());
        $this->assertSame('FR', $readable->phoneCountry());
        $this->assertSame('+33612345678', $readable->toArray()['phone']);
    }

    public function test_account_settings_open_and_take_a_new_number(): void
    {
        $member = $this->memberWithStoredPhone('0612345678');

        $page = $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent();
        // the field is empty: the member enters a new number
        $this->assertDoesNotMatchRegularExpression('/id="phone"[^>]*value="[^"]+"/', $page);

        $this->actingAs($member)->fromRoute('user.edit')->put(route('user.update'), [
            'username' => $member->username,
            'slug' => $member->slug,
            'email' => $member->email,
            'prefix' => '+33',
            'code' => 'FR',
            'phone' => '612345678',
        ])->assertRedirectToRoute('user.edit')->assertSessionHasNoErrors();

        $member->refresh();
        $this->assertSame('+33612345678', $member->getRawOriginal('phone'));
        $this->assertSame('+33612345678', $member->phone?->formatE164());
        $this->assertNull($member->phone_verified_at);
    }

    public function test_the_phone_code_routes_answer_add_a_number_first(): void
    {
        $member = $this->memberWithStoredPhone('+15550100');

        $this->actingAs($member)->postJson(route('phone.send'))
            ->assertStatus(422)
            ->assertJsonPath('message', __('Add your phone number first.'));
        $this->assertNull($member->fresh()->phone_confirmation_code);

        // the form post (no script) goes back with the same sentence
        $this->actingAs($member)->fromRoute('user.edit')->post(route('phone.send'))->assertRedirectToRoute('user.edit');

        $this->actingAs($member)->postJson(route('phone.verify'), ['code' => '12345'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('This code has expired. Send a new one.'));
    }

    public function test_a_queued_code_for_an_unreadable_number_is_skipped_without_an_error(): void
    {
        $member = $this->memberWithStoredPhone('+9991234567');
        $member->forceFill(['phone_confirmation_code' => '12345'])->save();
        Log::spy();

        // QUEUE_CONNECTION=sync in the tests: the queued notification runs here, through the SMS channel
        $member->notify(new VerifyPhone());

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'no phone number'))->once();
    }

    public function test_download_my_data_answers_with_no_number(): void
    {
        $member = $this->memberWithStoredPhone('abc', verified: true);

        $response = $this->actingAs($member)->get(route('user.export'))->assertOk();
        $data = json_decode($response->streamedContent(), true);

        $this->assertIsArray($data);
        $this->assertNull($data['profile']['phone']);
    }

    public function test_the_admin_member_pages_open(): void
    {
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();
        $member = $this->memberWithStoredPhone('0612345678', verified: true);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.show', $member))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.edit', $member))->assertOk();
    }

    private function memberWithStoredPhone(string $stored, bool $verified = false): User
    {
        $member = User::factory()->create(['phone' => null]);
        // written straight to the column, as an import or an older version left it (the cast refuses to write it)
        DB::table('users')->where('id', $member->id)->update([
            'phone' => $stored,
            'phone_verified_at' => $verified ? now()->subDay() : null,
        ]);

        return User::query()->findOrFail($member->id);
    }
}
