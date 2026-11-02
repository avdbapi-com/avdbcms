<?php

namespace Tests\Unit;

use App\Support\PaymentProviders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentProvidersTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: string}> */
    public static function providers(): array
    {
        return [
            'stripe' => ['stripe', 'Stripe'],
            'paypal (as stored)' => ['paypal', 'PayPal'],
            'PayPal (any case)' => ['PayPal', 'PayPal'],
            'PAYPAL' => ['PAYPAL', 'PayPal'],
            'with spaces' => ['  paypal ', 'PayPal'],
            'no value: a row from before PayPal support' => [null, 'Stripe'],
            'empty' => ['', 'Stripe'],
            'another service' => ['bank_transfer', 'Bank Transfer'],
        ];
    }

    #[DataProvider('providers')]
    public function test_label_writes_each_payment_service_like_its_brand(?string $stored, string $shown): void
    {
        $this->assertSame($shown, PaymentProviders::label($stored));
    }

    public function test_label_never_writes_paypal_as_paypal_headline(): void
    {
        $this->assertNotSame('Paypal', PaymentProviders::label('paypal'));
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function cards(): array
    {
        return [
            'card digits' => ['4242', '•••• 4242'],
            'digits with spaces' => [' 0005 ', '•••• 0005'],
            'paypal stores its name' => ['PayPal', 'PayPal'],
            'stripe without a card' => ['', null],
            'nothing stored' => [null, null],
        ];
    }

    #[DataProvider('cards')]
    public function test_card_shows_the_last_digits_or_the_service(?string $stored, ?string $shown): void
    {
        $this->assertSame($shown, PaymentProviders::card($stored));
    }
}
