<?php

namespace Tests\Feature;

use App\Support\PageUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * App\Support\PageUrl (member design 4.3): the member pages print links to this site as paths, which keeps
 * their content within its budget; every other address stays exactly as it was.
 */
class PageUrlTest extends TestCase
{
    private function onRequest(string $url): void
    {
        $this->app->instance('request', Request::create($url, 'GET'));
    }

    public function test_an_address_of_this_site_becomes_its_path_with_query_and_fragment(): void
    {
        $this->onRequest('http://videos.example.test/library');

        $this->assertSame('/video/night-drive-1234', PageUrl::local('http://videos.example.test/video/night-drive-1234'));
        $this->assertSame('/watch?list=abc#t=10', PageUrl::local('http://videos.example.test/watch?list=abc#t=10'));
        $this->assertSame('/index.php/video/x', PageUrl::local('http://VIDEOS.example.test/index.php/video/x'));
        $this->assertSame('/', PageUrl::local('http://videos.example.test'));
    }

    public function test_other_sites_ports_schemes_and_relative_addresses_stay_as_they_are(): void
    {
        $this->onRequest('https://videos.example.test/library');

        // another host (a CDN thumbnail), another port, the default port of the other scheme
        $this->assertSame('https://cdn.example.test/t/1.jpg', PageUrl::local('https://cdn.example.test/t/1.jpg'));
        $this->assertSame('https://videos.example.test:8443/v', PageUrl::local('https://videos.example.test:8443/v'));
        $this->assertSame('http://videos.example.test/v', PageUrl::local('http://videos.example.test/v'));
        // already relative, protocol-relative, not http(s), empty
        $this->assertSame('/images/a.svg', PageUrl::local('/images/a.svg'));
        $this->assertSame('//videos.example.test/v', PageUrl::local('//videos.example.test/v'));
        $this->assertSame('mailto:a@example.test', PageUrl::local('mailto:a@example.test'));
        $this->assertSame('', PageUrl::local(null));
        // the same scheme, host and port: a path
        $this->assertSame('/v', PageUrl::local('https://videos.example.test/v'));
    }
}
