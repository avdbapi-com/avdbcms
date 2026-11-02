<?php

namespace Tests\Feature;

use App\Http\Middleware\AddPreloadLinksForPages;
use App\Http\Middleware\BackgroundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * R1-163: a part of an admin page that fills itself in after the page appears fetches the page again
 * (modules/list-probes.ts). That answer carried the page's Link: rel=preload header, so Chrome preloaded the admin CSS a
 * second time ("preloaded but not used"). Only page views get the header now.
 */
class AdminPreloadLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_view_gets_the_preload_links_and_a_background_fetch_does_not(): void
    {
        // The tests run without Vite: its preloads are given here.
        Vite::shouldReceive('preloadedAssets')->andReturn(['/build/assets/admin-x.css' => ['rel="preload"', 'as="style"']]);
        $middleware = new AddPreloadLinksForPages();
        $answer = fn (array $headers) => $middleware->handle(
            tap(Request::create('/admin', 'GET'), fn (Request $r) => $r->headers->add($headers)),
            fn () => new Response('page'),
            4
        );

        $this->assertStringContainsString('rel="preload"', (string) $answer(['Sec-Fetch-Mode' => 'navigate'])->headers->get('Link'), 'a page view keeps its Link header');
        $this->assertNull($answer([BackgroundRequest::HEADER => '1', 'X-Requested-With' => 'XMLHttpRequest'])->headers->get('Link'), 'the page fetching itself gets none');
        $this->assertNull($answer(['Sec-Fetch-Mode' => 'cors'])->headers->get('Link'), 'a fetch() that is no navigation gets none');
    }

    public function test_through_the_kernel_only_a_page_view_gets_the_link_header(): void
    {
        // The 'web' middleware group of app/Http/Kernel.php, as a browser meets it: Vite's preloads are given here (the
        // tests run without Vite), the admin page is asked for as a page view and as its own background fetches.
        // With the framework's AddLinkHeadersForPreloadedAssets in the group (RC1), every one of them got the header.
        config(['admin.ui' => 'v2']);
        Vite::clearResolvedInstance();
        $this->swap(\Illuminate\Foundation\Vite::class, new class extends \Illuminate\Foundation\Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null)
            {
                return new \Illuminate\Support\HtmlString('');
            }

            public function __call($method, $parameters)
            {
                return '';
            }

            public function __toString(): string
            {
                return '';
            }

            public function useIntegrityKey($key)
            {
                return $this;
            }

            public function useBuildDirectory($path)
            {
                return $this;
            }

            public function useHotFile($path)
            {
                return $this;
            }

            public function withEntryPoints($entryPoints)
            {
                return $this;
            }

            public function useScriptTagAttributes($attributes)
            {
                return $this;
            }

            public function useStyleTagAttributes($attributes)
            {
                return $this;
            }

            public function usePreloadTagAttributes($attributes)
            {
                return $this;
            }

            public function preloadedAssets()
            {
                return ['/build/assets/admin-x.css' => ['rel="preload"', 'as="style"']];
            }

            public function reactRefresh()
            {
                return '';
            }

            public function content($asset, $buildDirectory = null)
            {
                return '';
            }

            public function asset($asset, $buildDirectory = null)
            {
                return '';
            }
        });
        $admin = User::factory()->admin()->create();

        $page = $this->actingAs($admin)->withHeaders(['Sec-Fetch-Mode' => 'navigate', 'Accept' => 'text/html'])->get('/admin')->assertOk();
        $this->assertStringContainsString('</build/assets/admin-x.css>; rel="preload"; as="style"', (string) $page->headers->get('Link'), 'a page view');

        foreach ([
            'the page fetching itself (modules/list-probes.ts)' => [BackgroundRequest::HEADER => '1', 'X-Requested-With' => 'XMLHttpRequest', 'Sec-Fetch-Mode' => 'cors'],
            'a fetch() that is no navigation' => ['Sec-Fetch-Mode' => 'cors'],
            'a same-origin fetch' => ['Sec-Fetch-Mode' => 'same-origin'],
            'an XHR' => ['X-Requested-With' => 'XMLHttpRequest'],
        ] as $what => $headers) {
            $this->flushHeaders();
            $answer = $this->actingAs($admin)->withHeaders($headers + ['Accept' => 'text/html'])->get('/admin')->assertOk();
            $this->assertNull($answer->headers->get('Link'), $what);
        }
    }

    public function test_the_admins_own_refetch_still_answers(): void
    {
        config(['admin.ui' => 'v2']);
        $this->actingAs(User::factory()->admin()->create())
            ->withHeaders([BackgroundRequest::HEADER => '1', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'])
            ->get('/admin')->assertOk();
    }

    public function test_what_counts_as_the_pages_own_request(): void
    {
        $request = fn (array $headers): Request => tap(Request::create('/admin', 'GET'), fn (Request $r) => $r->headers->add($headers));

        $this->assertFalse(AddPreloadLinksForPages::isOwnRequest($request([])), 'a request without hints is a page view');
        $this->assertFalse(AddPreloadLinksForPages::isOwnRequest($request(['Sec-Fetch-Mode' => 'navigate'])));
        $this->assertFalse(AddPreloadLinksForPages::isOwnRequest($request(['Sec-Fetch-Mode' => 'nested-navigate'])));
        $this->assertTrue(AddPreloadLinksForPages::isOwnRequest($request([BackgroundRequest::HEADER => '1'])));
        $this->assertTrue(AddPreloadLinksForPages::isOwnRequest($request(['X-Requested-With' => 'XMLHttpRequest'])));
        $this->assertTrue(AddPreloadLinksForPages::isOwnRequest($request(['Sec-Fetch-Mode' => 'cors'])));
        $this->assertTrue(AddPreloadLinksForPages::isOwnRequest($request(['Sec-Fetch-Mode' => 'same-origin'])));
    }
}
