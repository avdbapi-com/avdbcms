# Web server configuration

AVDBCMS is a Laravel 12 application. Like every Laravel app it has a single
public entry point, `public/index.php`; the rest of the tree (`app/`, `config/`,
`storage/`, `vendor/`, `.env`) must never be reachable over HTTP.

The release supports two layouts, and a site in a folder of its domain (layout C).

## The nginx files

| File | For | Replace before use |
| --- | --- | --- |
| [nginx-public-root.conf](nginx-public-root.conf) (and `nginx.conf` next to `index.php`, the same block without comments) | a whole `server {}` block, layout A | `/var/www/example.com` (`/path/to/avdbcms` in `nginx.conf`): three places; `server_name`, the PHP-FPM socket, the TLS lines |
| `nginx-aapanel-rewrite.conf` (next to `index.php`) | the locations only, layout A: aaPanel's URL rewrite box, CloudPanel's second server block | `__PROJECT_ROOT__`: two places |
| [nginx-project-root.conf](nginx-project-root.conf) | the locations only, layout B: aaPanel, 1Panel, CloudPanel's second server block | `__PROJECT_ROOT__`: four places |
| [nginx-cloudpanel-front.conf](nginx-cloudpanel-front.conf) | CloudPanel's first server block, layouts A and B | `__PROJECT_ROOT__`: three places |
| [nginx-hestia-project-root.conf](nginx-hestia-project-root.conf) | HestiaCP, layout B | `__PROJECT_ROOT__`: four places |
| [nginx-subfolder.conf](nginx-subfolder.conf) | layout C, any nginx | `__PROJECT_ROOT__`, the folder `/site`, the PHP-FPM socket |

`__PROJECT_ROOT__` is the project folder (the one that contains `artisan`), without a slash at the end. Never use
two files that both have a `location /` block or `gzip` lines in one server block: `nginx -t` refuses the pair.

## Layout A (recommended): web root = `public/`

Point the domain's document root at `<project>/public`. Nothing else is needed:
`public/.htaccess` handles Apache and LiteSpeed, and
[nginx-public-root.conf](nginx-public-root.conf) is the matching nginx block
([apache-vhost.conf](apache-vhost.conf) for Apache).

Where to set it: aaPanel (site > Site directory > Running directory `/public`),
CloudPanel (site root), HestiaCP (domain > Advanced > Custom document root),
CyberPanel (vHost Conf `docRoot`), 1Panel (site directory), Virtualmin (Server
configuration > Website options > Website documents sub-directory), Plesk
(Hosting settings > Document root), cPanel (addon and sub-domains; the primary
domain's root can only be changed from WHM).

Three nginx panels still need steps in this layout:

* CloudPanel (Document Root `/public`): its vhost has two server blocks. The
  second (`listen 8080`, PHP) gets the locations of `nginx-aapanel-rewrite.conf`
  above its PHP location (without them PHP runs a script that lands in
  `public/storage/`); the first gets [nginx-cloudpanel-front.conf](nginx-cloudpanel-front.conf)
  above the panel's `location ~* ^.+\.(css|js|jpg|...)$` block (without it the
  videos stored on the server and an administrator's backup downloads answer 404,
  and a dump in `public/` is handed out).
* HestiaCP (custom document root `public`): no nginx file. Take `gz`, `tgz` and
  `tar` out of the domain's *Proxy Extensions*, so that a dump in `public/` goes to
  Apache, which refuses it, instead of nginx, which hands it out.
* Plesk (document root `httpdocs/public`): no nginx file. If *Serve static files
  directly by nginx* is on (Apache & nginx Settings), take `gz`, `tgz` and `tar` out
  of its list, for the same reason. Not tried on a real Plesk (an emulation of its
  nginx-in-front-of-Apache layout).

## Layout B (fallback): web root = project root

Many shared panels only let you upload into `public_html`. The release works
there without manual edits on Apache and LiteSpeed, and with one include on nginx:

* `index.php` in the project root is a front controller: it serves static files
  from `public/`, refuses source paths, then boots Laravel.
* `.htaccess` in the project root (Apache, LiteSpeed, OpenLiteSpeed) rewrites
  every request into `public/`, forwards `/storage/*` and `/vendor/*` to the right
  place and blocks source files. A real folder of the project (`/storage/logs`,
  `/app` ...) is refused at once (403), never a redirect.
* nginx does not read `.htaccess`: include
  [nginx-project-root.conf](nginx-project-root.conf) in the site's `server {}`
  block (panel-specific paths are listed inside the file). On aaPanel the
  application also writes the equivalent rules into the site's URL-rewrite file on
  the first request when that file is writable by PHP.

### Per-panel notes for layout B

| Panel | Web server | What to do |
| --- | --- | --- |
| aaPanel | nginx | Put the include in `/www/server/panel/vhost/nginx/extension/<domain>/avdbcms.conf` (every `*.conf` there is loaded; leave the URL-rewrite box empty) or in the site's URL-rewrite box, then reload nginx from the panel. Never together with `nginx-aapanel-rewrite.conf` or the laravel5 preset (that file is for the Running directory `/public` only): `nginx -t` refuses the pair. |
| aaPanel | Apache / OpenLiteSpeed | Nothing: the shipped `.htaccess` is enough. OpenLiteSpeed caches `.htaccess`, so run `/usr/local/lsws/bin/lswsctrl restart` after replacing it. |
| CloudPanel | nginx (frontend :443 + backend :8080) | The include goes into the **second** server block only (`listen 8080`, above its PHP location); the first block gets [nginx-cloudpanel-front.conf](nginx-cloudpanel-front.conf) **above** the panel's `location ~* ^.+\.(css\|js\|jpg\|...)$` block. Never the include in the first block: its rewrites to `/index.php` reach PHP as `/index.php` through the proxy (every admin page and every video stored on the server showed the home page). Save in the panel's Vhost editor (it runs `nginx -t` and reloads). |
| HestiaCP | nginx in front of Apache | Not this include (its `location /` duplicates Hestia's, `nginx -t` refuses it): save [nginx-hestia-project-root.conf](nginx-hestia-project-root.conf) as `/home/<user>/conf/web/<domain>/nginx.conf_avdbcms` **and** `nginx.ssl.conf_avdbcms`, and take `gz`, `tgz` and `tar` out of the domain's *Proxy Extensions*. Hestia loads every `nginx.conf_*` / `nginx.ssl.conf_*` file in that folder, so never leave backup copies there. Better: a custom document root on `public` (layout A). |
| CyberPanel | OpenLiteSpeed | Nothing beyond `.htaccess`; restart OpenLiteSpeed after changing it. |
| 1Panel | OpenResty in Docker | Save the include in the site folder (`/opt/1panel/www/sites/<site>/avdbcms.conf`, seen by the container as `/www/sites/<site>/avdbcms.conf`), reference it from `/opt/1panel/www/conf.d/<site>.conf`, then `docker exec <openresty-container> nginx -t` and `nginx -s reload`. Use container paths inside the include. |
| Virtualmin | Apache | Nothing beyond `.htaccess`; the default `+SymLinksIfOwnerMatch` works because `public/storage` belongs to the site user. |
| Plesk | nginx in front of Apache | Not this include (its `location /` duplicates Plesk's own, `nginx -t` refuses it). Turn *Serve static files directly by nginx* off (Apache & nginx Settings): with it on, nginx hands out files of the project folder by their ending (backups, videos stored on the server) before the project folder's `.htaccess` can refuse them. Better: Document root `httpdocs/public` (layout A). Not tried on a real Plesk (an emulation of its layout). |
| cPanel / GoDaddy | Apache or LiteSpeed | Extract the zip into `public_html` (or point an addon domain at `.../public`), choose PHP 8.3 in MultiPHP Manager, open `https://domain/install`. If Cloudflare answers every request with a challenge page, the zone is in *Under Attack* or *Bot Fight* mode: turn it off while installing and testing. |

A site set up before 1.2.0 may already have this include under an earlier file name
(in the same folder, or pasted into the panel's URL-rewrite or directives box).
Update that copy in place and do not add a second one under the new name: nginx
refuses duplicate `location` blocks, so `nginx -t` fails and the reload is refused.

## Layout C: a site in a folder of its domain

A site can live in a folder of a domain whose root serves something else
(`https://example.com/site`, `APP_URL=https://example.com/site`; the installer
suggests it when you open `https://example.com/site/install`; a plain folder name, not `public`):

* Apache: `Alias /site <project>/public` with `AllowOverride All` for
  that folder (no `RewriteBase` needed), or the whole project unpacked into the
  domain's `site/` folder (its own `.htaccess` works there); both tried on Apache
  2.4. LiteSpeed and OpenLiteSpeed read the same `.htaccess` files, but a site in a
  folder was not tried on them.
* nginx: [nginx-subfolder.conf](nginx-subfolder.conf) in the domain's `server {}`
  block (it serves `public/`; the layout B files are for a domain's root only).
  Its header says what to change on a server without rewrite rules: the site then
  keeps `/site/index.php/...` addresses.
* Search engines read `robots.txt` only at the domain's root: add
  `Sitemap: https://example.com/site/sitemap.xml` there (without rewrite rules:
  `https://example.com/site/index.php/sitemap.xml`). nginx's `/_internal/`
  location exists once per server block: a second AVDBCMS site on the domain leaves
  it out (PHP streams its videos).

## Uploaded files (`/storage/...`)

`Storage::url()` links point at `/storage/<file>`. Laravel normally serves them
through the `public/storage -> ../storage/app/public` symlink created by
`php artisan storage:link`, but many panels disable PHP's `symlink()`. The
release therefore never depends on the symlink:

1. nginx: `location ^~ /storage/` serves `storage/app/public` directly.
2. Apache / LiteSpeed: `.htaccess` forwards `/storage/*` to `public/storage/*`.
3. The root `index.php` resolves `/storage/*` inside `storage/app/public` itself.
4. The Laravel route `storage.fallback` streams the file when nothing else did.

Only `storage/app/public` is ever exposed. `storage/logs`, `storage/framework`
and `storage/app/installed.json` always answer 404.

The upload folders serve pictures and never run a script: `/storage/...` and
`/uploads/...` (the upload folder of older releases, which updates keep) refuse
`.php`, `.php3` ... `.php8`, `.phar`, `.phtml`, `.pht` and `.phps` files, also
named like `x.php.jpg` or reached as `x.php/more`, in every shipped
configuration (nginx: the nested `location` in each upload folder; Apache and
LiteSpeed: `public/.htaccess` and the root `.htaccess`; IIS: the rules of
`public/web.config` and `web.config`). The Laravel route `storage.fallback`
refuses the same names, also when it is reached as `/index.php/storage/...` or
`/<language>/storage/...`.

A database dump, a log or a backup copy (`.sql`, `.sqlite`, `.db`, `.dump`,
`.log`, `.bak`, `.backup`, `.old`, `.orig`, `.save`, `.swp`, `.gz`, `.tgz`,
`.tar`, a name ending in `~`) is never handed out as a file, even when one lands
in `public/` or on the public disk (`storage/app/public`, also not through the
route `storage.fallback`). The site's own addresses that end like that (an
administrator's download of a backup or a log) still reach the application.

## Video files

Video files stored on the server itself (not the ones Upload18 or AVDBAPI play)
go through PHP first, which checks who may watch them. PHP can then send the file
itself, or hand it to the web server, which is faster for big files:

* nginx: PHP answers with `X-Accel-Redirect: /_internal/<file>` and nginx sends
  the file from the internal location every shipped nginx file has:

  ```nginx
  location ^~ /_internal/ {
      internal;
      alias /path/to/the/project/storage/app/private/;
  }
  ```

  Visitors can never request `/_internal/` themselves. Without this location nginx
  cannot send the file (it answered with an error up to 1.2.3 RC1), so the site
  checks it: with `STREAM_ACCELERATOR=auto` (the default) it hands files to nginx
  only after it asked itself for a test file through that location and got it
  back; until then, and on a server without the location, PHP streams the files
  (both answer range requests, so players can seek). The check runs after a
  video request, again every 10 minutes, and when you press *Re-probe* in
  Tools > Health check, which shows the answer under *Streaming*.
  The video addresses (`/video/file/<name>.mp4`) end like files: every shipped
  nginx file sends them to PHP first (`location ^~ /video/file/`), also where a
  panel's own static-file rule would answer them with a 404 (CloudPanel).
* Apache: PHP streams the files, unless `mod_xsendfile` is loaded into Apache's
  own PHP module.
* LiteSpeed / OpenLiteSpeed: the server sends the files (`X-LiteSpeed-Location`).

`STREAM_ACCELERATOR` in `.env` overrides the choice: `none` (always PHP),
`nginx`, `apache`, `litespeed`. With `nginx` the site still checks first (PHP
streams until the check ran, right after the first video request) and streams
with PHP once its check found that nginx cannot send the files; only when the
site cannot reach itself to check does it follow the setting blindly.

## Clean addresses

Pages have clean addresses (`/trend`, `/fr/login`) when the web server sends
every address that is not a file to `index.php`: the `try_files` line of the
nginx files, the `.htaccess` files on Apache and LiteSpeed. A server without that
rule still serves every page as `/index.php/trend`, and the site keeps its links
in that form there (also the canonical, `og:url`, language alternates and
sitemaps), so no link ever ends on the web server's 404 page.

`APP_CLEAN_URLS` in `.env` decides:

* `auto` (the default): clean addresses on Apache, LiteSpeed, IIS and PHP's own
  server, as before. On nginx the site checks once that a clean address reaches
  it with status 200 (it asks itself for `/_avdbcms/check/...`), and only then
  gives clean links; until then, and on a server without the rule, links keep
  `/index.php/...`. The check runs again every 10 minutes (an hour after a failed
  one, a few minutes after somebody opened a clean address, a minute after
  somebody opened a `/index.php/...` address), and when you press *Re-probe* in
  Tools > Health check, which shows the answer under *HTTP*. The admin area and
  the sign-in page opened through `/index.php/...` keep that form in their links
  whatever the check says, so a rule removed later never locks you out.
* `true`: always clean addresses (the rule is there for certain).
* `false`: always `/index.php/...`.

Old `/index.php/...` links keep working on every server, and the addresses the
site builds from a request that came in that way keep it too (the next page of
a list, a form sent back after a mistake, a filter with an empty field, the
`/index.php/x/` -> `/index.php/x` redirect, signed e-mail links).

## Checklist after installing

Run from any machine, replacing the domain (add `?cb=123` to bypass caches).

Must return 200:

* `/`
* `/build/manifest.json`
* `/vendor/umami/script.js`
* `/storage/<any uploaded file>`

Must not return 200:

* `/.env`, `/config/app.php`, `/artisan`, `/composer.json`
* `/vendor/autoload.php`, `/vendor/composer/installed.json`
* `/storage/logs/laravel.log`, `/storage/app/installed.json`
* `/storage/logs/` (403 or 404 at once, no redirect)
* a test file `x.php` put into `storage/app/public/`, requested as `/storage/x.php`
  (it must never run; delete it afterwards)
* a test file `x.sql` put into `public/`, requested as `/x.sql` (delete it afterwards)

## Other operational notes

* PHP 8.3 or newer (64-bit) is required.
* One cron line runs everything, including the queue worker (`queue:work
  --stop-when-empty` is scheduled every minute, no supervisor needed):
  `* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1`
  The installer adds it itself where PHP may start programs (its last screen then says there is
  nothing to add); otherwise its last screen shows the site's exact command. In a hosting panel's
  cron job choose "every minute" and enter only the command, without `* * * * *`.
* After replacing files on OpenLiteSpeed, restart it; after replacing nginx
  includes, run `nginx -t` before reloading.
