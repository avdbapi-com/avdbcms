<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Security plan S33: opening a notification goes only to a page of this site. A stored link that names another host
 * (one stored while forwarded headers were believed, before 1.2.3) or that a browser would read as another host goes
 * to the home page instead; the notification is marked read either way.
 */
class NotificationClickTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://victim-site.example';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::SITE]);
    }

    public static function links(): array
    {
        return [
            'a path on this site' => ['/@member', self::SITE.'/@member'],
            'a path with a query' => ['/profile/videos?x=1', self::SITE.'/profile/videos?x=1'],
            'the site address' => [self::SITE.'/@member', self::SITE.'/@member'],
            'the site address over http' => ['http://victim-site.example/@member', 'http://victim-site.example/@member'],
            'the site address in capitals' => ['HTTPS://VICTIM-SITE.EXAMPLE/@member', 'HTTPS://VICTIM-SITE.EXAMPLE/@member'],
            'another host' => ['https://evil.example/x', null],
            'a protocol-relative address' => ['//evil.example', null],
            'a backslash after the slash' => ['/\\evil.example', null],
            'a tab after the slash' => ["/\t/evil.example", null],
            'a line break after the slash' => ["/\n/evil.example", null],
            'a backslash before the user part' => ['https://evil.example\\@victim-site.example/', null],
            'the site name as the user part' => ['https://victim-site.example@evil.example/', null],
            'a user name on the site address' => ['https://user@victim-site.example/', null],
            'a host that only starts with the site name' => ['https://victim-site.example.evil.example/', null],
            'https with a backslash' => ['https:/\\evil.example', null],
            'a script address' => ['javascript:alert(1)', null],
            'no link at all' => ['', null],
        ];
    }

    #[DataProvider('links')]
    public function test_a_notification_opens_only_a_page_of_this_site(string $stored, ?string $expected): void
    {
        $member = User::factory()->create();
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\Activity\\NewSubscriber',
            'notifiable_type' => User::class,
            'notifiable_id' => $member->id,
            'data' => json_encode(['message' => 'x', 'url' => $stored]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($member)->get(self::SITE.'/profile/notifications/'.$id.'/click');

        $response->assertStatus(302);
        $this->assertSame($expected ?? self::SITE, $response->headers->get('Location'));
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'), 'marked read');
    }
}
