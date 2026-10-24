# AVDBCMS — Universal Multi-Panel Deployment & Administration Guide
### The Complete Zero-Headache Setup Handbook for Community Users

> **Welcome to AVDBCMS Community Edition!**  
> This guide is designed to help **anyone**—from absolute beginners on shared hosting to experienced sysadmins managing high-performance VPS clusters—deploy, customize, and scale AVDBCMS effortlessly across any control panel.

---

## ⚡ 3-Minute Fast Track (Quick Start for Everyone)

If you just want to get your video platform running in 3 minutes without reading long manuals, follow these 3 steps:

```
┌─────────────────────────┐     ┌─────────────────────────┐     ┌─────────────────────────┐
│  1. CREATE SITE & DB    │ ──> │  2. UNZIP INSTALLER     │ ──> │  3. RUN /install WIZARD │
│  PHP 8.3+ & MySQL 5.7+  │     │  Drop in web directory  │     │  Fill DB info & Admin   │
└─────────────────────────┘     └─────────────────────────┘     └─────────────────────────┘
```

1. **Create Site & Database**:
   - In your panel (aaPanel, CloudPanel, HestiaCP, CyberPanel, 1Panel, Virtualmin, or cPanel), create a website for your domain with **PHP 8.3 or newer** and a new **MySQL/MariaDB database**.
2. **Upload & Extract**:
   - Upload the release ZIP you downloaded (for example, `avdbcms-1.2.1.zip`) to your website root folder and click **Extract** (or unzip).
3. **Run Web Installer**:
   - Open your browser and navigate to: `https://yourdomain.com/install` (or `https://yourdomain.com/index.php/install`).
   - Enter your database name, user, password, and choose your admin username/password.
   - Click **Install** — your schema, built-in categories, and plans are ready immediately. The clean install starts with no sample videos or channels.

---

## Table of Contents
1. [Overview & Zero-Configuration Architecture](#1-overview--zero-configuration-architecture)
2. [Universal System Requirements](#2-universal-system-requirements)
3. [The "index.php in URL" Explained & How to Remove It](#3-the-indexphp-in-url-explained--how-to-remove-it)
4. [Step-by-Step Installation by Control Panel](#4-step-by-step-installation-by-control-panel)
   - [Panel 1: aaPanel (Apache)](#panel-1-aapanel-apache)
   - [Panel 2: aaPanel (Nginx)](#panel-2-aapanel-nginx)
   - [Panel 3: aaPanel (OpenLiteSpeed)](#panel-3-aapanel-openlitespeed)
   - [Panel 4: CloudPanel v2 (Nginx)](#panel-4-cloudpanel-v2-nginx)
   - [Panel 5: HestiaCP (Nginx + Apache)](#panel-5-hestiacp-nginx--apache)
   - [Panel 6: CyberPanel (OpenLiteSpeed)](#panel-6-cyberpanel-openlitespeed)
   - [Panel 7: 1Panel (Docker / OpenResty)](#panel-7-1panel-docker--openresty)
   - [Panel 8: Virtualmin (Apache2)](#panel-8-virtualmin-apache2)
   - [Panel 9: cPanel (Managed Apache)](#panel-9-cpanel-managed-apache)
5. [Permissions & Security Hardening](#5-permissions--security-hardening)
6. [Scheduler & Background Queue Setup](#6-scheduler--background-queue-setup)
7. [Automated Content Importers (AVDB & XVIDAPI)](#7-automated-content-importers-avdb--xvidapi)
8. [Video Player & Upload18 Configuration](#8-video-player--upload18-configuration)
9. [Compatibility Verification](#9-compatibility-verification)
10. [Frequently Asked Questions & Troubleshooting](#10-frequently-asked-questions--troubleshooting)

---

## 1. Overview & Zero-Configuration Architecture

AVDBCMS includes an **Application-Level Zero-Config Bridge**:
- **Dual-Layer Document Root Support**:
  - In standard Laravel apps, servers must be configured to point to `/public`. If a panel extracts the zip into `/` (root), other scripts break or expose `.env`.
  - AVDBCMS includes a protected root `index.php` fallback that safely proxies requests to `public/index.php`, protects sensitive files (`.env`, `vendor/`, `config/`), and resolves static assets seamlessly without modifying web server virtual hosts.
- **Precompiled Dual-Layer Vite Assets**:
  - CSS fonts, SVG icons, and JavaScript chunks exist in both `public/build/assets` and root `build/assets` to eliminate 404 font errors on strict Nginx or LiteSpeed static cache filters.
- **Instant Client-Side Thumbnail Capture**:
  - During video upload, HTML5 `<video>` and `<canvas>` extract 3 crisp 640x360 frames locally in your browser. This completely bypasses Cloudflare bot protection and datacenter IP blocks.
- **Universal SVG Avatar Generator**:
  - Automatically creates branded vector SVG avatars for all registered users, manual admins, and imported creators.

---

## 2. Universal System Requirements

| Component | Minimum | Recommended |
| :--- | :--- | :--- |
| **PHP Version** | PHP 8.3 (64-bit) | **PHP 8.3+** (64-bit) |
| **MySQL / MariaDB** | MySQL 5.7 / MariaDB 10.3 | **MySQL 8.0+** or **MariaDB 10.6+** |
| **Web Server** | Any (Apache, Nginx, OpenLiteSpeed, Docker) | **Nginx** or **LiteSpeed** |
| **Memory (RAM)** | 1 GB | **2 GB+** (for video processing) |
| **Disk Space** | 2 GB free | **20 GB+ SSD / NVMe** |

### Required PHP Extensions
Ensure these extensions are enabled in your hosting panel:
- `pdo_mysql` (Database connectivity)
- `curl` (API communication with AVDB & video CDNs)
- `mbstring` (Multilingual UTF-8 text processing)
- `fileinfo` (MIME-type detection for uploads)
- `exif` (Image orientation & metadata)
- `gd` (Thumbnail fallback card generation)
- `zip` (Archive extraction and updates)
- `bcmath` (Precision math & Captcha)
- `xml` & `tokenizer` (Laravel core parsing)

---

## 3. The "index.php in URL" Explained & How to Remove It

### Why do some sites show `https://yoursite.com/index.php/video/...`?
1. **How Clean URLs Work**:
   When you visit `https://yoursite.com/video/my-video`, the web server looks on disk for a folder named `/video/my-video`. It does not find one.
   A properly configured web server uses a **Rewrite Rule** to pass the request internally to `index.php` behind the scenes.
2. **What Happens When Rewrite Rules are Missing**:
   - On **Apache**: Apache automatically reads the `.htaccess` file shipped with AVDBCMS, so clean URLs work out of the box.
   - On **Nginx** (for example aaPanel with Nginx, or 1Panel): When you create a new site, Nginx **does not read `.htaccess`**. If no rewrite rule is added, requesting `/video/my-video` returns a **404 Not Found**.
   - As a result, users or browsers type `/index.php/video/my-video` so Nginx will hand the request to PHP. Laravel then detects `/index.php` as the script name and prepends `/index.php` to all links.

### The Universal Solution
Add the standard Laravel rewrite rule to your Nginx virtual host:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```
That rule only gives you clean URLs. When the web root is the **project folder** (the one that contains `artisan`, the usual case on shared hosting), Nginx also has to serve uploaded files (`/storage/...`) and published assets (`/vendor/...`) and must block the application source. Those rules are shipped ready to include: [docs/server-configs/nginx-project-root.conf](docs/server-configs/nginx-project-root.conf) (replace `__PROJECT_ROOT__`), with the file locations for every panel in [docs/server-configs/README.md](docs/server-configs/README.md). Apache and OpenLiteSpeed need nothing extra: the shipped `.htaccess` already does all of this.

*Step-by-step instructions for each panel are provided below.*

---

## 4. Step-by-Step Installation by Control Panel

### Panel 1: aaPanel (Apache)
1. **Create Website**:
   - Go to **Websites** > **Add site**.
   - Domain: `yourdomain.com`
   - Database: Create MySQL database.
   - PHP Version: Select **PHP-8.3** or newer.
2. **Upload & Extract**:
   - Open **Files**, navigate to `/www/wwwroot/yourdomain.com`.
   - Upload `avdbcms-1.2.1.zip` and click **Unzip**.
3. **Site Directory**:
   - In **Websites**, click your domain > **Site directory**.
   - Set **Running directory** to `/public` (or keep `/` if using our zero-config root runner).
4. **Rewrite Rules**:
   - Under **URL rewrite**, choose **0.Current** or leave default. Apache reads `.htaccess` automatically.
5. **Visit `https://yourdomain.com/install`** and follow the on-screen wizard!

---

### Panel 2: aaPanel (Nginx)
1. **Create Website & Upload**:
   - Create site with **PHP-8.3** or newer and a MySQL database.
   - Extract files to `/www/wwwroot/yourdomain.com`.
2. **CRITICAL — Clean URL Rewrite (Eliminates `index.php`)**:
   - In **Websites**, click your domain > **URL rewrite**.
   - In the dropdown preset selector, select **laravel5**.
   - Or paste this directly:
     ```nginx
     location / {
         try_files $uri $uri/ /index.php?$query_string;
     }
     ```
   - Click **Save**.
   - Then copy `docs/server-configs/nginx-project-root.conf` to `/www/server/panel/vhost/nginx/extension/yourdomain.com/avdbcms.conf` (replace `__PROJECT_ROOT__` with `/www/wwwroot/yourdomain.com`) and reload Nginx. aaPanel loads every `*.conf` in that folder; this serves uploads and vendor assets and blocks the source tree. A site set up before 1.2.0 may already have this file there under an earlier name: update that file in place instead of adding a second copy (nginx refuses duplicate `location` blocks).
   - The include has its own `location /` block, so keep only one of the two: remove the plain rule from **URL rewrite** again, or delete the `location /` block from your copy of the include. Run `nginx -t` before you reload; it must report no duplicate `location "/"`.
3. **Anti-XSS / Open Basedir**:
   - Under **Site directory**, uncheck **Anti-XSS attack (open_basedir)** if you experience permission issues.
4. **Complete Setup**:
   - Open your browser to `https://yourdomain.com/` and complete installation.

---

### Panel 3: aaPanel (OpenLiteSpeed)
1. **Create Website**:
   - Add site with PHP 8.3 or newer and MySQL.
   - Extract archive into `/www/wwwroot/yourdomain.com`.
2. **Rewrite Rules**:
   - Open **Websites** > click domain > **URL rewrite**.
   - Select **laravel5** or paste:
     ```apache
     rewriteFile /www/wwwroot/yourdomain.com/.htaccess
     ```
   - Click **Save**. OpenLiteSpeed will auto-reload.

---

### Panel 4: CloudPanel v2 (Nginx)
1. **Add Site**:
   - Click **Add Site** > **Create a PHP Site**.
   - Domain: `yourdomain.com`
   - PHP Version: **PHP 8.3** or newer.
   - Document Root: `/htdocs/yourdomain.com/public` (or `/htdocs/yourdomain.com`).
2. **Upload Code**:
   - Use CloudPanel File Manager or SFTP to upload and extract the archive into the site directory.
3. **Vhost Configuration**:
   - Under **Vhost**, verify the root block includes:
     ```nginx
     location / {
         try_files $uri $uri/ /index.php?$query_string;
     }
     ```
   - CloudPanel includes this by default. Clean URLs work immediately.
   - If the Document Root is the project folder (not `/public`): save `docs/server-configs/nginx-project-root.conf` as `/etc/nginx/avdbcms-yourdomain.com.conf` (replace `__PROJECT_ROOT__` with `/home/{site-user}/htdocs/yourdomain.com`), add `include /etc/nginx/avdbcms-yourdomain.com.conf;` to **both** `server { }` blocks (the `:443` frontend and the `:8080` backend) in `/etc/nginx/sites-enabled/yourdomain.com.conf`, then run `nginx -t && systemctl reload nginx`. A site set up before 1.2.0 may already include this file under an earlier name: update that file in place instead of adding a second file or `include` line (nginx refuses duplicate `location` blocks).
   - The include has its own `location /` block, so keep only one of the two: either the plain rule above or the include. If `nginx -t` reports a duplicate `location "/"`, delete the `location /` block from your copy of the include and test again.

---

### Panel 5: HestiaCP (Nginx + Apache)
1. **Add Web Domain**:
   - Go to **WEB** > **Add Web Domain**.
   - Check **Enable SSL** > **Use Let's Encrypt**.
2. **Upload & Permissions**:
   - Upload and extract into `/home/{user}/web/{yourdomain.com}/public_html`.
   - In terminal or panel, set ownership:
     ```bash
     chown -R {user}:{user} /home/{user}/web/{yourdomain.com}/public_html
     ```
3. **Clean URLs**:
   - Because HestiaCP uses an Apache backend behind Nginx reverse proxy, `.htaccess` is processed automatically. URLs are clean without manual rewrite edits.
   - Nginx still serves static files itself, so copy `docs/server-configs/nginx-project-root.conf` to `/home/{user}/conf/web/{yourdomain.com}/nginx.conf_avdbcms` **and** `nginx.ssl.conf_avdbcms` (replace `__PROJECT_ROOT__` with `/home/{user}/web/{yourdomain.com}/public_html`), then run `nginx -t && systemctl reload nginx`. Without it, uploaded files under `/storage/` and the assets under `/vendor/` answer 404. Hestia loads every `nginx.conf_*` file in that folder, so never keep backup copies there. A site set up before 1.2.0 may already have both files there under an earlier name: update those in place instead of adding a second pair (nginx refuses duplicate `location` blocks).

---

### Panel 6: CyberPanel (OpenLiteSpeed)
1. **Create Website**:
   - Go to **Websites** > **Create Website**.
   - Select PHP **8.3** or newer.
2. **Upload Code**:
   - Open **File Manager** for your domain (`/home/yourdomain.com/public_html`).
   - Upload and extract the archive.
3. **Rewrite Rules**:
   - In CyberPanel, go to **Websites** > **List Websites** > **Manage** > **Rewrite Rules**.
   - Select **template** > **Laravel** (or paste `.htaccess` rewrite rules).
   - Save and restart OpenLiteSpeed (**Server Status** > **LiteSpeed Status** > **Reboot**).

---

### Panel 7: 1Panel (Docker / OpenResty)
1. **Create Website**:
   - Go to **Websites** > **Create Website**.
   - Running environment: **PHP 8.3** or newer.
   - Web directory: `/www/sites/yourdomain.com/index`.
2. **Extract Files**:
   - Use 1Panel File Manager to upload and extract archive into `/www/sites/yourdomain.com/index`.
3. **CRITICAL — Clean URL Rewrite**:
   - Open Website Configuration > **Configuration** (or edit `/opt/1panel/www/conf.d/yourdomain.conf`).
   - Add this inside the `server { ... }` block right before `location ~ [^/]\.php`:
     ```nginx
     location / {
         try_files $uri $uri/ /index.php?$query_string;
     }
     ```
   - Click **Save**. 1Panel reloads OpenResty automatically.
   - Then save `docs/server-configs/nginx-project-root.conf` as `/opt/1panel/www/sites/yourdomain.com/avdbcms.conf` with `__PROJECT_ROOT__` replaced by the **container** path `/www/sites/yourdomain.com/index`, add `include /www/sites/yourdomain.com/avdbcms.conf;` next to the `location /` block, then run `docker exec <openresty-container> nginx -t` and `docker exec <openresty-container> nginx -s reload`. A site set up before 1.2.0 may already have this file under an earlier name: update that file in place instead of adding a second file or `include` line (nginx refuses duplicate `location` blocks).
   - The include has its own `location /` block, so keep only one of the two: either the plain rule above or the include. If `nginx -t` reports a duplicate `location "/"`, delete the `location /` block from your copy of the include and test again.
4. **Fix Directory Permissions inside Docker**:
   - Run in terminal:
     ```bash
     chmod -R 777 /www/sites/yourdomain.com/index/storage /www/sites/yourdomain.com/index/bootstrap/cache
     ```

---

### Panel 8: Virtualmin / Webmin (Apache2)
1. **Create Virtual Server**:
   - In Virtualmin, click **Create Virtual Server** > enter domain.
   - Ensure PHP execution mode is set to **FPM** under **Server Configuration** > **PHP Options** (select PHP 8.3 or newer).
2. **Extract Code**:
   - Upload and extract into `/home/yourdomain/public_html`.
3. **Apache AllowOverride**:
   - Ensure `AllowOverride All` is active for `/home/yourdomain/public_html` so `.htaccess` runs without restrictions.

---

### Panel 9: cPanel (Managed / Shared Apache)
1. **Upload via File Manager**:
   - Log into cPanel > **File Manager**.
   - Navigate to `public_html`.
   - Upload `avdbcms-1.2.1.zip` and click **Extract**.
2. **Select PHP Version**:
   - In cPanel, find **MultiPHP Manager** or **Select PHP Version**.
   - Set domain to **PHP 8.3** or newer.
   - In **MultiPHP INI Editor**, ensure `max_execution_time = 300`, `upload_max_filesize = 500M`, and `post_max_size = 500M`.
3. **Database Creation**:
   - Go to **MySQL Database Wizard**, create database and user, and assign **All Privileges**.
4. **Visit `https://yourdomain.com/install`** to complete setup!
5. **If every page shows a Cloudflare "One moment, please..." screen**: the zone is in *Under Attack* or *Bot Fight* mode. Turn it off (or add a WAF skip rule for your own IP) while installing and testing, otherwise neither the installer nor the health checks can be reached.

---

## 5. Permissions & Security Hardening

After extracting the files, set proper folder permissions so Laravel can write sessions, caches, and video thumbnails:

### Linux CLI Command:
```bash
cd /path/to/your/site
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```
*(On CentOS / RHEL / CyberPanel, replace `www-data:www-data` with `nginx:nginx` or your panel user).*

### File Manager GUI:
- Right-click `storage` folder > **Permissions** > Check **Read**, **Write**, **Execute** for Owner and Group (775).
- Check **Apply to subdirectories and files**.
- Repeat for `bootstrap/cache`.

### Security headers (Admin → Settings → Security)
- **HSTS**: most panels and Cloudflare already send `Strict-Transport-Security` at the web-server/CDN layer (aaPanel+Nginx, HestiaCP, CyberPanel/OpenLiteSpeed, 1Panel/OpenResty do by default). Leave the app's **HSTS** toggle **off** unless `curl -I https://yourdomain.com` shows no such header — enabling it on top produces a duplicate header.
- **Force HTTPS Redirect** is safe behind Cloudflare and panel reverse proxies (the app honours `X-Forwarded-Proto`).
- **Content Security Policy** is opt-in; if you enable it, keep the default directives unless you self-host every script/style.

---

## 6. Scheduler & Background Queue Setup

To ensure video durations, AVDB batch imports, view counters, and scheduled tasks run automatically 24/7, configure the **Laravel Cron Job**:

### Step 1: Add Laravel Scheduler Cron
Open your panel's **Cron / Scheduled Tasks** menu and add:
```bash
* * * * * cd /path/to/your/site && php artisan schedule:run >> /dev/null 2>&1
```

### Step 2: Background Queue Worker (automatic)
No extra process is required. Every minute the scheduler from Step 1 starts a short-lived worker in the background (`php artisan queue:work --stop-when-empty`); it processes the pending jobs (imports, video durations, counters, mail) and exits. This is why the single cron line above is enough on shared hosting.

*Optional, for very busy sites:* keep a permanent worker running under **Supervisor** (aaPanel) or **Process Manager** (1Panel):
```bash
php /path/to/your/site/artisan queue:work --tries=3
```
Both workers can run at the same time. If `QUEUE_CONNECTION=sync`, jobs run inside the web request and no worker is started.

---

## 7. Automated Content Importers (AVDB & XVIDAPI)

AVDBCMS includes high-performance automated content importers connected to both **AVDB** (Asian AV catalog) and **XVIDAPI** (Western & Global catalog with 6,590,000+ videos).

### 7.1 AVDB Content Importer

Fresh installations do not start AVDB or XVIDAPI imports. Default schedule templates are disabled. Start an import manually, or create or enable a schedule in the relevant admin dashboard when you are ready. Running the Laravel scheduler alone does not enable these templates.
- **Coverage**: Asian Adult Video (Uncensored, Censored, FC2, Chinese Subtitled).
- **Admin Dashboard**: Access at `/admin/avdb-import`.
- **Key Features**:
  - Multi-language translation backfill (`php artisan avdb:translate --all` or `--locales=ja,vi,zh`).
  - Automatic creator profiles with vector SVG avatars.
  - Video duration backfill and starter views distribution.
- **CLI Usage**:
  ```bash
  cd /path/to/your/site
  php artisan avdb:import --category=1 --pages=5 --pagesize=50 --starter-views=natural
  ```

### 7.2 XVIDAPI Content Importer
- **Coverage**: Global video catalog with **64 dynamic categories** (Amateur, Anal, VR, AI, Latina, Mature, MILF, etc.) and over 6.59 million videos.
- **Admin Dashboard**: Access at `/admin/xvid-import` or use the top provider tabs.
- **Key Features**:
  - **4K Ultra HD & Quality Badges**: Automatically detects and highlights 4K, 1080p, and HD resolution badges on video cards and watch pages.
  - **Native Upload18 Player**: Renders fast, mobile-friendly embed players (`https://upload18.net/play/index/xvidapi-{id}`).
  - **Metadata Row**: Displays Movie Code, Quality, Actor pills, and Studio / Author in a clean metadata grid.
  - **Auto Creator Channels**: Creates dedicated creator channels with branded SVG initials avatars from actor and studio names.
  - **Tag Extraction**: Normalizes, cleans, and deduplicates tags for tag search and hashtag pills.
  - **Multi-language tags (XVIDAPI 2026 update)**: XVIDAPI now returns tag names in many languages. While importing, AVDBCMS fetches the localized tag names for every **active site language** (Admin → Languages) and stores them as tag translations, so a visitor browsing `/vi`, `/ja`, `/zh`, ... sees the tags in their language. Video **titles and descriptions stay in the video's original language** — the API does not translate them. Untick **Translate tags into site languages** in the import form (or pass `--skip-tag-translations`) to skip this; existing translations you edited are never overwritten.
  - **Original language filter**: *Search & Content Filters → Any original language* imports only videos whose title is originally Japanese, Chinese, Korean, Vietnamese, etc. (`--source-language=ja` on the CLI).
  - **Clean All XVIDAPI**: Single-click wipe function in the dashboard to reset imported videos and their relationships if needed.
- **CLI Usage**:
  ```bash
  cd /path/to/your/site
  # Import category 8 (Amateur), 5 pages, 50 items per page
  php artisan xvid:import --category=8 --pages=5 --pagesize=50

  # Import multiple categories with remote thumbnails (fastest, saves disk)
  php artisan xvid:import --categories=8,20,43,65 --pages=3 --pagesize=50 --skip-thumbs

  # Import specific video IDs
  php artisan xvid:import --ids=73246215,73246216

  # Only Japanese-original videos from category 65 (tags are translated into your site languages automatically)
  php artisan xvid:import --category=65 --pages=2 --pagesize=50 --source-language=ja
  ```

### 7.3 Low-Resource VPS Optimization Guide (1-2 vCPU / 1GB RAM)
For smooth, uninterrupted imports on budget VPS (CloudPanel, aaPanel, Hestia, CyberPanel, 1Panel, Virtualmin):
1. **Remote Poster Mode (Recommended)**:
   - Always leave **Skip Thumbnails (Use remote poster)** enabled.
   - This stores the remote CDN image URL directly in `poster_url` without downloading hundreds of megabytes of JPEG files to your local server disk.
2. **Memory Leaks Prevention**:
   - Both `avdb:import` and `xvid:import` automatically call `DB::disableQueryLog()` to prevent Laravel from accumulating query arrays in RAM.
   - PHP CLI garbage collection (`gc_collect_cycles()`) is automatically invoked after each page.
   - Memory limit is automatically raised to `512M` (or `1024M` if available) with zero timeout limits.
3. **Polite Delay & CPU Throttling**:
   - On single-core VPS nodes, set **Delay** to `250` or `500` ms. This prevents MariaDB and PHP-FPM from spiking CPU to 100%, keeping your public website fast for visitors while importing in the background.
4. **Subprocess vs Queue**:
   - On hosting panels without a 24/7 supervisor queue daemon, AVDBCMS automatically launches imports in a detached background subprocess (`ShellRunner::runBackground`) using the resolved PHP CLI binary (`PhpBinary::resolve()`). You can close your browser tab and the import will continue until completed.

### 7.4 Automated Scheduling & Cron
Add a single cron entry to your server:
```bash
* * * * * cd /path/to/your/site && php artisan schedule:run >> /dev/null 2>&1
```
This automatically runs both:
- `php artisan avdb:schedules:run` (Every minute checks for due AVDB schedules).
- `php artisan xvid:schedules:run` (Every minute checks for due XVIDAPI schedules).
- You can create, toggle, and manage recurring daily/hourly import schedules directly from both import dashboards.
- An XVIDAPI schedule imports the categories that are ticked in the import form when you click **Save Cron Schedule**; if none are ticked it falls back to the default category set, so a schedule never silently imports nothing.

### 7.5 Email: where do password-reset mails go before SMTP is set up?
A fresh install ships with `MAIL_MAILER=log`. Nothing is delivered to inboxes yet: every email the site tries to send (password resets, email verification, the **Admin → Email Test** page) is written to `storage/logs/mail.log` instead, so you can open that file to read the message or the reset link.
Configure a real mailer in **Admin → Settings → Email** (SMTP host, port, username, password, from-address) and send yourself a message from **Admin → Email Test**; once a test arrives in your inbox the site is sending real mail.

---

## 8. Video Player & Upload18 Configuration

AVDBCMS supports both self-hosted MP4/HLS streams and **Upload18 / Helvid Cloud Video CDN**:

### Upload18 Cloud Integration:
- Upload18 provides zero-cost streaming with adaptive bitrates.
- Videos are embedded via responsive, mobile-friendly iframe players at `/video/{uuid}/embed`.

### Anti-Debug Protection Notice:
- Video hosts like Upload18 embed anti-adblock and anti-inspect protection (`disable-devtool`).
- When testing video playback, **close your browser's F12 Developer Tools / Inspect dock**. If DevTools is open, the third-party player pauses playback or renders a black screen as a bot-prevention measure. Real users without DevTools open will experience smooth, instant playback.

---

---

## 9. Compatibility Verification

The community archive is designed for ordinary public hosting and does not depend on any private test server. Maintainers validate representative stacks and browser viewports before publishing a release; use the checks below to verify a deployment on your own host.

| Profile | Typical stack | Release checks |
| :--- | :--- | :--- |
| Apache | Apache 2.4 with PHP-FPM | `/install`, clean URL rewrite, storage permissions |
| Nginx | Nginx with PHP-FPM | `/install`, `index.php` fallback, queue scheduler |
| OpenLiteSpeed | LiteSpeed with PHP 8.3+ | rewrite rules, upload limits, cache headers |
| Managed panel | cPanel, CloudPanel, HestiaCP, or similar | document root, HTTPS, cron, database connection |
| Container | Docker or 1Panel | environment variables, writable storage, queue worker |

### Recommended browser checks
1. Open the home page and `/login` at a narrow phone width and a desktop width.
2. Register a test account, upload a short private video, and confirm the player works after processing.
3. Try one RTL locale, one translated locale, reduced motion, keyboard navigation, and a touch device.
4. Confirm AVDB and XVID imports remain idle until an administrator explicitly starts or schedules them.
5. Record a short scroll and playback video when checking animation or player performance; screenshots alone cannot show dropped frames or layout flashes.

### Verified Core Functions
1. **User Registration with Math Captcha**: Dynamically generated challenges (`X + Y = ?`) help prevent automated spambots.
2. **Deterministic Vector SVG Avatars**: New users receive a custom monogram vector avatar.
3. **In-Browser Video Studio Upload**: Browser-side thumbnail capture reduces unnecessary server processing.
4. **Adaptive Video Player**: Supports self-hosted media and Upload18 cloud embeds with responsive controls.
5. **Catalog Search & Filter**: Search covers videos, actors, studios, and categories.
6. **Multi-Language & Theme**: Dark/light themes and locale switching are built in.

---

## 10. Frequently Asked Questions & Troubleshooting

### Q: Why do I see a 500 Internal Server Error on a fresh install?
1. **Missing PHP Extensions**: Verify `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `gd`, `zip`, and `bcmath` are enabled.
2. **Permissions**: Ensure `storage` and `bootstrap/cache` are writable (`chmod -R 775`).
3. **Disk quota (Virtualmin / cPanel / DirectAdmin)**: a completely blank 500 page right after uploading usually means the hosting account's disk quota is full — the site cannot even write its log. The extracted package needs about 750 MB plus room for `storage/`; a leftover copy of an old site in the same account counts against the quota too. Raise the quota (or delete/move the old copy) and reload.
4. **Clear Cache**: Run `php artisan optimize:clear` via terminal or click **System** > **Clear Cache** in `/admin/settings`.

### Q: How do I remove `index.php` from all my website URLs?
- For Nginx (aaPanel / 1Panel): Add the rewrite rule from [Section 3](#3-the-indexphp-in-url-explained--how-to-remove-it).
- Once added, reload Nginx. Clean URLs like `/video/my-video` and `/trend` will work immediately.

### Q: Why do font icons look like squares or broken boxes?
- This happens when your web server does not have access to Vite font assets.
- In AVDBCMS, all FontAwesome fonts (`fa-solid-900.woff2`, `fa-brands-400.woff2`) are pre-compiled and placed in both `/public/build/assets` and `/build/assets`. Clear your browser cache (`Ctrl + F5`) to refresh.

### Q: The site looks unstyled or half-styled (plain colours, no glass or card effects), but icons and the menu work?
- The theme styles are cached files in `public/build/assets` (`layout-theme-a-*.css`, `layout-theme-b-*.css`). Make sure the whole `public/build` folder was uploaded, then purge your CDN / Cloudflare cache and reload with `Ctrl + F5`.
- If a proxy, CDN or security plugin still blocks those files, add `AVD_THEME_CSS_INLINE=true` to `.env` (run `php artisan config:clear` if you cached the config). Every page then carries the same theme CSS inline: it looks identical, the pages are just larger.

### Q: After an update, theater mode, lights out, `Ctrl + K` search or Watch Later do nothing?
- `resources/views` and `public/build` belong to the same release: always upload both folders together. Views from a newer release next to an older `public/build` leave these buttons without their scripts.
- Signs of it: the browser console (F12) shows `AVD: the layout script bundle did not run`, and the "Latest Log Error" line of Admin → Settings (Operational Health Checklist) shows `AVD: public/build has no entry for ...`.
- Fix: upload the whole `public/build` folder again, purge your CDN / Cloudflare cache, then reload with `Ctrl + F5`.

### Q: "Forgot password" says the link was emailed, but nothing arrives?
- Until SMTP is configured the mailer is `log`: open `storage/logs/mail.log` on the server, the reset link is in there (see [7.5](#75-email-where-do-password-reset-mails-go-before-smtp-is-set-up)).
- After configuring SMTP, use **Admin → Email Test** to confirm delivery.

### Q: How do I reset the Admin password if I forget it?
Run via SSH terminal (replace `NewPassword123!` with your new password, at least 8 characters):
```bash
cd /path/to/your/site
php artisan tinker --no-interaction --execute="\$u = App\Models\User::where('is_admin', 1)->first(); \$u->password = 'NewPassword123!'; \$u->save(); echo 'Password updated for '.\$u->username.PHP_EOL;"
```
- Type the password as plain text: the user model hashes it once when it is saved. Do not wrap it in `Hash::make()`, or it is hashed twice and the new password does not work.
- With several admins, choose the account by its username: replace `where('is_admin', 1)` with `where('username', 'your-admin-name')`.
- A line that starts with `Restricted Mode:` (from the Tinker console) is only a notice; the password is still changed.

---

*AVDBCMS Community Edition — Built for performance, stability, and effortless deployment.*
