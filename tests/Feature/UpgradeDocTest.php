<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Round 1 of 1.2.3 (R1-012): UPGRADE.md said an update never touches storage/ and .env, while the update to 1.2.3
 * renames the logs, backups, exports, invoices and copies of .env under storage/, removes the administrator's e-mail
 * from the install lock, moves the old cache away and takes the read right of other users from .env (as the changelog
 * says). The guide says what is kept and what changes, and what Finish does on a host with a short time limit.
 */
class UpgradeDocTest extends TestCase
{
    public function test_the_guide_says_what_the_update_changes_in_storage_and_env(): void
    {
        $guide = (string) file_get_contents(base_path('UPGRADE.md'));

        $this->assertStringNotContainsString('## What an update never touches', $guide);
        $this->assertStringNotContainsString('| Never touched. The update only adds its work folder', $guide);
        $this->assertStringContainsString('## What an update keeps and what it changes', $guide);

        foreach ([
            'What the update to 1.2.3 renames in `storage/`',
            '`laravel-….log`',
            'database backups and members exports get a random part in their names',
            'invoice PDFs move from `storage/app/private/invoices/`',
            'the install lock `storage/app/installed.json` no longer keeps the administrator\'s e-mail address',
            'copies of `.env` get a random part in their names',
            'the cache folders of 1.2.2 and older',
            'takes away the right of other hosting accounts on the server to read `.env`',
            '`database/install/example.sql`',
            '`database/schema/mysql-schema.sql`',
            'Some of the database changes are done. Click Finish update again for the rest.',
            'the page then sends the next one by itself',
        ] as $text) {
            $this->assertStringContainsString($text, $guide);
        }
    }
}
