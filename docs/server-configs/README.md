# Web server configuration

AVDBCMS is a Laravel 12 application. Like every Laravel app it has a single
public entry point, `public/index.php`; the rest of the tree (`app/`, `config/`,
`storage/`, `vendor/`, `.env`) must never be reachable over HTTP.

The release supports two layouts.

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

## Layout B (fallback): web root = project root

Many shared panels only let you upload into `public_html`. The release works
there without manual edits on Apache and LiteSpeed, and with one include on nginx:

* `index.php` in the project root is a front controller: it serves static files
  from `public/`, refuses source paths, then boots Laravel.
* `.htaccess` in the project root (Apache, LiteSpeed, OpenLiteSpeed) rewrites
  every request into `public/`, forwards `/storage/*` and `/vendor/*` to the right
  place and blocks source files.
* nginx does not read `.htaccess`: include
  [nginx-project-root.conf](nginx-project-root.conf) in the site's `server {}`
  block (panel-specific paths are listed inside the file). On aaPanel the
  application also writes the equivalent rules into the site's URL-rewrite file on
  the first request when that file is writable by PHP.

### Per-panel notes for layout B

| Panel | Web server | What to do |
| --- | --- | --- |
| aaPanel | nginx | Put the include in `/www/server/panel/vhost/nginx/extension/<domain>/avdbcms.conf` (every `*.conf` there is loaded) or in the site's URL-rewrite box, then reload nginx from the panel. |
| aaPanel | Apache / OpenLiteSpeed | Nothing: the shipped `.htaccess` is enough. OpenLiteSpeed caches `.htaccess`, so run `/usr/local/lsws/bin/lswsctrl restart` after replacing it. |
| CloudPanel | nginx (frontend :443 + backend :8080) | Save the include as `/etc/nginx/avdbcms-<domain>.conf` and add it to **both** server blocks in `/etc/nginx/sites-enabled/<domain>.conf`, then `nginx -t && systemctl reload nginx`. |
| HestiaCP | nginx in front of Apache | Save the include as `/home/<user>/conf/web/<domain>/nginx.conf_avdbcms` **and** `nginx.ssl.conf_avdbcms`. Hestia loads every `nginx.conf_*` / `nginx.ssl.conf_*` file in that folder, so never leave backup copies there. |
| CyberPanel | OpenLiteSpeed | Nothing beyond `.htaccess`; restart OpenLiteSpeed after changing it. |
| 1Panel | OpenResty in Docker | Save the include in the site folder (`/opt/1panel/www/sites/<site>/avdbcms.conf`, seen by the container as `/www/sites/<site>/avdbcms.conf`), reference it from `/opt/1panel/www/conf.d/<site>.conf`, then `docker exec <openresty-container> nginx -t` and `nginx -s reload`. Use container paths inside the include. |
| Virtualmin | Apache | Nothing beyond `.htaccess`; the default `+SymLinksIfOwnerMatch` works because `public/storage` belongs to the site user. |
| Plesk | nginx + Apache | Paste the include into *Additional nginx directives*; `.htaccess` covers Apache. |
| cPanel / GoDaddy | Apache or LiteSpeed | Extract the zip into `public_html` (or point an addon domain at `.../public`), choose PHP 8.3 in MultiPHP Manager, open `https://domain/install`. If Cloudflare answers every request with a challenge page, the zone is in *Under Attack* or *Bot Fight* mode: turn it off while installing and testing. |

A site set up before 1.2.0 may already have this include under an earlier file name
(in the same folder, or pasted into the panel's URL-rewrite or directives box).
Update that copy in place and do not add a second one under the new name: nginx
refuses duplicate `location` blocks, so `nginx -t` fails and the reload is refused.

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

## Other operational notes

* PHP 8.3 or newer (64-bit) is required.
* One cron line runs everything, including the queue worker (`queue:work
  --stop-when-empty` is scheduled every minute, no supervisor needed):
  `* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1`
* After replacing files on OpenLiteSpeed, restart it; after replacing nginx
  includes, run `nginx -t` before reloading.
