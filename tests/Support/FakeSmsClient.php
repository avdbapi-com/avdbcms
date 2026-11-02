<?php

namespace Tests\Support;

use App\Services\BrevoService;

/**
 * The SMS client of every test (1.2.3 round 3, R3-059): the phone codes the site sends are recorded here and never
 * leave the machine. The real client (App\Services\BrevoService) posted them to Brevo's API with the tests' made-up key,
 * so a test run depended on the network and wrote Brevo's "Key not found" answer into the site's log. Tests\TestCase
 * binds one instance per test; a test reads what was sent from $sent.
 */
class FakeSmsClient extends BrevoService
{
    /** @var list<array{number: string, content: string}> */
    public array $sent = [];

    public function __construct()
    {
        parent::__construct('fake-sms-client');
    }

    public function sendTransactionalSMS(string $number, string $content): int
    {
        $this->sent[] = ['number' => $number, 'content' => $content];

        return 201;
    }
}
