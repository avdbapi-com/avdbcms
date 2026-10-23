{{--
    The 403 page of the admin IP lock (App\Http\Middleware\IsAdmin): a signed-in administrator opened the admin from an
    address that is not on the list (Settings > Security > Admin access lock, a Developer mode card). It says what
    happened and how the owner gets back in (the avd:admin-ip-lock command, or emptying admin_ip_whitelist in the
    database and clearing the cache). It never shows the list or a secret.
    A page of its own (no admin menu: every admin link would be blocked too), small and without scripts.
    Names of settings, tables and folders are code (left to right in every language): the translated sentence is
    escaped first, then they replace its placeholders.
    Data: $ip = the address the lock saw for this request.
--}}
@use('App\Support\AdminTheme')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ AdminTheme::direction() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>{{ __('This address cannot open the admin') }} · {{ config('app.name') }}</title>
<style>
:root{--canvas:#f4f5f7;--surface:#fff;--border:#e3e6ea;--text:#16191e;--text-2:#4a515c;--code:#eef0f3;--warn:#8f5300;--warn-soft:#fdf3e1;--accent:#1f5bc7;--focus:#1f5bc7}
@media (prefers-color-scheme:dark){:root{--canvas:#121419;--surface:#1a1d23;--border:#2b3039;--text:#e9ebef;--text-2:#b4bac5;--code:#282d35;--warn:#f2b544;--warn-soft:#362f22;--accent:#4381f0;--focus:#4381f0}}
*{box-sizing:border-box}
body{margin:0;background:var(--canvas);color:var(--text);font:14px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans","Noto Sans Arabic",sans-serif}
main{max-width:640px;margin:48px auto;padding:0 16px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:28px}
.kicker{display:inline-block;margin:0 0 12px;padding:2px 10px;border-radius:999px;background:var(--warn-soft);color:var(--warn);font-weight:600;font-size:13px}
h1{margin:0 0 8px;font-size:22px;line-height:1.3}
h2{margin:24px 0 8px;font-size:16px}
p,li{color:var(--text-2)}
ol{margin:0;padding-inline-start:20px}
li+li{margin-top:10px}
code{display:inline-block;padding:1px 6px;border-radius:6px;background:var(--code);color:var(--text);font:13px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;direction:ltr;unicode-bidi:isolate}
.address{margin:16px 0 0;color:var(--text)}
.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}
.actions a{color:var(--accent);font-weight:600}
a:focus-visible{outline:2px solid var(--focus);outline-offset:2px;border-radius:4px}
@media (forced-colors:active){.card,.kicker,code{border:1px solid CanvasText}}
</style>
</head>
<body>
<main>
<div class="card">
<p class="kicker">{{ __('Admin access lock') }}</p>
<h1>{{ __('This address cannot open the admin') }}</h1>
<p>{{ __('The admin of this site opens only from the internet addresses on its list (Settings > Security > Admin access lock). This request came from an address that is not on it.') }}</p>
@if ($ip !== '')
<p class="address">{{ __('Your address, as this site sees it:') }} <code>{{ $ip }}</code></p>
@endif
<h2>{{ __('Is this your site? Get back in') }}</h2>
<ol>
<li>{{ __('Open the admin from an address on the list. Home internet addresses can change, and a VPN or a phone hotspot uses another one.') }}</li>
<li>{{ __('Or, on your server, in the folder of the site, run:') }} <code>php artisan avd:admin-ip-lock off</code><br>{{ __('It empties the list, so the admin opens from anywhere again. To lock it again, turn on Developer mode in the account menu, then add your address in Settings > Security > Admin access lock.') }}</li>
<li>{{ __('To see how many addresses are on the list and the address of the computer you connect from, run:') }} <code>php artisan avd:admin-ip-lock status</code></li>
<li>{!! strtr(e(__('No command line? In your hosting database tool, empty the value of :setting in the :table table.')), [':setting' => '<code>admin_ip_whitelist</code>', ':table' => '<code>settings</code>']) !!}
{!! strtr(e(__('Then clear the site cache so it reads the change: with your file manager, delete the files in :folder (the usual file cache), or empty the :table table when the site keeps its cache in the database.')), [':folder' => '<code>storage/framework/cache/data</code>', ':table' => '<code>cache</code>']) !!}</li>
</ol>
<div class="actions">
<a href="{{ url('/') }}">{{ __('Go to the site') }}</a>
<a href="{{ route('logout') }}">{{ __('Log out') }}</a>
</div>
</div>
</main>
</body>
</html>
