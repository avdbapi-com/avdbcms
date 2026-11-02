<?php

namespace Tests\Unit;

use App\Support\OutboundUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Security plan S22: the admin's fetch tools only reach http(s) addresses on the internet. Every address the host
 * stands for must be public: never this machine, a private or link-local network, shared (carrier-grade NAT) space or
 * a cloud metadata address.
 */
class OutboundUrlTest extends TestCase
{
    public static function notPublic(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/'],
            'loopback with a port' => ['http://127.0.0.1:6379/'],
            'private 10/8' => ['http://10.0.0.5/a.m3u8'],
            'private 172.16/12' => ['http://172.16.0.1/'],
            'private 192.168/16' => ['https://192.168.1.1/'],
            'cloud metadata (link-local)' => ['http://169.254.169.254/latest/meta-data/'],
            'shared address space' => ['http://100.64.0.1/'],
            'the zero address' => ['http://0.0.0.0/'],
            'IPv6 loopback' => ['http://[::1]/'],
            'IPv6 unique local' => ['http://[fd00::1]/'],
            'IPv6 link-local' => ['http://[fe80::1]/'],
            'IPv4-mapped IPv6 loopback' => ['http://[::ffff:127.0.0.1]/'],
            'localhost' => ['http://localhost/'],
            'a user part in front of a private host' => ['http://8.8.8.8@127.0.0.1/'],
            'another scheme' => ['ftp://8.8.8.8/'],
            'no scheme' => ['8.8.8.8/x'],
            'a backslash' => ['http://8.8.8.8\\@127.0.0.1/'],
            'empty' => [''],
        ];
    }

    #[DataProvider('notPublic')]
    public function test_addresses_that_are_not_on_the_internet_are_refused(string $url): void
    {
        $this->assertFalse(OutboundUrl::isPublic($url), $url);
    }

    public function test_a_public_address_passes(): void
    {
        $this->assertTrue(OutboundUrl::isPublic('http://8.8.8.8/'));
        $this->assertTrue(OutboundUrl::isPublic('https://[2001:4860:4860::8888]/'));
        $this->assertSame(['8.8.8.8'], OutboundUrl::publicAddresses('https://8.8.8.8/api.php/provide/vod'));
    }

    public function test_the_check_without_dns_names_local_hosts_only(): void
    {
        foreach (['http://127.0.0.1:6379/', 'http://169.254.169.254/x', 'http://localhost/', 'http://x.localhost/', 'not a url'] as $url) {
            $this->assertTrue(OutboundUrl::namesLocalHost($url), $url);
        }

        foreach (['https://avdbapi.com/api.php/provide/vod', 'https://8.8.8.8/'] as $url) {
            $this->assertFalse(OutboundUrl::namesLocalHost($url), $url);
        }
    }

    public function test_the_request_options_follow_only_public_redirects(): void
    {
        $options = OutboundUrl::options('https://8.8.8.8/x');

        $this->assertSame(5, $options['allow_redirects']['max']);
        $this->assertSame(['http', 'https'], $options['allow_redirects']['protocols']);
        $this->assertIsCallable($options['allow_redirects']['on_redirect']);
        $this->assertArrayNotHasKey('curl', $options, 'a literal address needs no pin');
    }
}
