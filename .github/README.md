# AVDBCMS

AVDBCMS is free, source-available software for a video sharing site on your own hosting. It fills your site with
videos from AVDB and xvidapi, lets your members upload videos through Upload18, and comes with members and channels,
ads, premium plans and an admin panel made for beginners.

AVDBCMS runs on Laravel 12 and needs PHP 8.3 or newer. It is based on an open-source project by Anton Bourtnik
(MIT License).

- Download: https://github.com/avdbapi-com/avdbcms/releases/latest
- Help center: https://help.avdbapi.com/
- Help (Telegram): https://t.me/avdb_api

## What you get

### 32 themes and 12 complete looks

- 32 themes in 13 families, with 174 colour styles. Each theme has its own layout, fonts and shapes, and a light and
  a dark version.
- 12 complete looks. A look sets the theme, the homepage, the video page, the video cards and the effects in one
  click. It never touches your site texts, logo, custom code, menus or ads.
- The looks: YouTube Spotlight Pro (the default for new sites), Cinema Night, Velvet Lounge, Shorts Feed, Swiss Bento,
  Night City HUD, Esports Arena, Deep Space Theater, Imperial Salon, Magazine, Glass Lounge and Daylight Creator.
- You preview a change on your real site first. Visitors keep the old design until you click **Publish**. History
  lists your last 20 design changes, and you can undo them.
- Visitors can switch between dark and light. With the default settings, weaker phones get lighter effects by
  themselves.

### Videos from AVDB and xvidapi

- **Content › Import videos** has one tab for AVDB (**AVDBAPI**) and one for xvidapi (**XVIDAPI**). No API key is
  needed.
- Choose categories and how many pages, then start the import. It runs in the background, so you can close the
  browser tab. Saved schedules repeat an import by themselves (they need the cron line, see Quick install).
- AVDB also sends the titles in 16 more languages. xvidapi translates the tags into your site languages; its titles
  stay in their original language.
- By default the video pictures load from the provider and take no space on your hosting.
- Imported videos play in the Upload18 player.
- A new site has no videos, and no import runs until you start one.

### Members, uploads and channels

- Visitors can create an account. Members comment, like videos, save them to Watch Later or a playlist, and subscribe
  to channels.
- Members can upload videos (switch **Let members upload videos** on **Settings › Features on/off**). Upload18 stores
  the uploads.
- **Appearance › Creator channels** sets how channel pages look and what creators may change.
- **Members** in the admin: find, verify and block members, and see their logins. **Community**: reports, comments,
  messages and announcements.

### Ads and premium plans

- **Money › Ads**: one switch for all ads, four ad spaces (a banner at the top, a box next to the video, a banner at
  the bottom and a small banner on phones), ads inside video lists, a video ad before videos with a Skip button, and a
  pop-under ad. With **No ads for premium members** (on by default), paying members see no ads.
- **Money › Premium plans**: monthly or yearly plans and a free trial. **Money › Payment setup** connects card payments
  (Stripe) and PayPal.
- **Money › Earnings** shows your income and what still needs setting up.

### An admin made for beginners

- 8 sections with plain names: Home, Content, Community, Members, Money, Appearance, Settings and Tools.
- Home starts with a checklist, **Get your site ready**, and a **Needs you** list.
- Press Ctrl+K (or `/`) to search every page, setting, member and video. A save bar shows what you changed; Ctrl+S
  saves. **What moved where** helps if you knew the old admin.
- **Tools**: a health check with one-click fixes, database backups, background jobs, logs and one button to clear the
  cache.
- Developer tools stay hidden until you turn on Developer mode in your account menu.
- The admin works in light and dark, and on a phone.

### 17 languages

- English, French, Thai, Indonesian, Korean, Vietnamese, Chinese, Japanese, German, Spanish, Portuguese, Russian,
  Arabic (right to left), Turkish, Hindi, Malay and Filipino, for your visitors and in the admin.
- **Settings › Languages**: turn languages on or off, edit their translations, or add a language.

### Search engines

- **Settings › Google & sharing** sets how your site appears in Google and when it is shared on social media.
- Your sitemap is at `/sitemap.xml`. It lists the pages, videos and channels in every site language.

### One-click updates from GitHub

- From 1.2.0 on, **Tools › Updates** shows when a new release is out. One click downloads it, checks it, backs up what
  it replaces and installs it. Nothing updates by itself. See [One-click updates](#one-click-updates).

## What you need

- **PHP 8.3 or newer**, 64-bit. The installer checks these extensions: ctype, curl, dom, filter, hash,
  json, mbstring, openssl, pdo, pdo_mysql, session, tokenizer and xml. Recommended: bcmath, fileinfo, gd and zip, and
  the process functions `proc_open`, `proc_get_status` and `proc_close` (for imports, background jobs and backups).
- **MySQL 5.7+ or MariaDB 10.3+** (MySQL 8.0+ or MariaDB 10.6+ recommended), an empty database and a database user
  with all privileges on it.
- **A web server:** Apache, Nginx, OpenLiteSpeed or LiteSpeed. The `.htaccess` files (Apache, LiteSpeed) and the
  `web.config` files (IIS) are included. Nginx needs a rewrite rule: see
  [COMMUNITY_SETUP_GUIDE.md](COMMUNITY_SETUP_GUIDE.md) and [docs/server-configs/](docs/server-configs/README.md).
- **Memory and disk:** at least 1 GB of memory (2 GB or more recommended) and 2 GB of free disk space (20 GB or more
  recommended). The extracted package alone takes about 750 MB.
- **Upload limits:** PHP `upload_max_filesize` and `post_max_size` of at least 64M for the install check; video
  uploads usually need 256M or more.
- **One cron line**, so that scheduled imports, emails and clean-ups run (see Quick install).
- FFmpeg is not needed on the server: the full package brings FFmpeg for Linux (x64 and arm64) and Windows in `bin/`.
  An FFmpeg that your server already has works too.

The setup guide has steps for these hosting panels: aaPanel (Apache, Nginx or OpenLiteSpeed), CloudPanel, HestiaCP,
CyberPanel, 1Panel, Virtualmin and cPanel.

## Quick install

1. In your hosting panel, create a site with PHP 8.3 or newer, and an empty MySQL or MariaDB database with a user that
   has all privileges on it.
2. Download the full package `avdbcms-X.Y.Z.zip` from the
   [latest release](https://github.com/avdbapi-com/avdbcms/releases/latest). Do not use GitHub's "Source code"
   downloads: they hold the source code only, and the installer cannot start from them.
3. Upload the zip to the folder that your domain serves and extract it there. The zip has no folder around the files.
   Best setup: point your domain to the `public/` folder inside it. The main folder works too.
4. Open `https://your-domain/install` and follow the 6 steps: Introduction, Requirements, Filesystem, Database, Admin
   Account and Install. If `/install` shows "404 Not Found", open `https://your-domain/index.php/install`.
   On the Database step, keep the choice **Import SQL backup**.
5. Sign in at `https://your-domain/login`, then open the admin at `https://your-domain/admin`. While you are signed
   out, `/admin` only shows "not found".
6. Add this cron line in your hosting panel, with your site folder as the path. The installer tries to add it for you.

   ```
   * * * * * cd /path/to/your/site && php artisan schedule:run >> /dev/null 2>&1
   ```

7. Follow the checklist **Get your site ready** on the admin Home.

Every step in detail: [Install AVDBCMS step by step](https://help.avdbapi.com/documentation/installation-guide-for-avdbcms/).
Notes for each hosting panel: [COMMUNITY_SETUP_GUIDE.md](COMMUNITY_SETUP_GUIDE.md).

### From the source code (for developers)

This repository holds the source code. The release zip adds what is built from it: the PHP packages in `vendor/`, the
built styles and scripts in `public/build/`, and the FFmpeg programs in `bin/`. To run a copy of the source, you need
PHP 8.3 or newer with Composer, and Node.js 20 (20.19 or newer) or Node.js 22.12 or newer, with npm 10 or newer:

```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

Then open `/install` as in step 4.

## One-click updates

From 1.2.0 on you update AVDBCMS in the admin:

1. **Tools › Updates** shows when a new release is out on GitHub. Home shows it too.
2. Click **Update to X.Y.Z** and type your admin password.
3. AVDBCMS downloads the update package, checks its SHA-256 checksum, makes a database backup, keeps a copy of every
   file it replaces, installs the new files and makes the database changes.

Nothing updates by itself: you start every update. If something goes wrong, the old files are put back, and
**Undo this update** brings back the files of the version before. Your `.env`, your uploads in `storage/`, your
backups and the FFmpeg programs in `bin/` are never touched.

A site that runs a community test package from before 1.2 moves to 1.2.0 once by hand: upload the update package,
then open the admin Home and click **Finish update** when Home shows it. All details, also the manual way and how to
go back: [UPGRADE.md](UPGRADE.md). What is new: [CHANGELOG.md](CHANGELOG.md).

## All guides

The help center has a guide for every part of AVDBCMS:

| Section | Guides |
|---|---|
| Getting started | [What is AVDBCMS?](https://help.avdbapi.com/documentation/avdbcms-what-is/) · [Requirements and hosting: what you need](https://help.avdbapi.com/documentation/avdbcms-requirements/) · [Download AVDBCMS](https://help.avdbapi.com/documentation/download-avdbcms/) · [Install AVDBCMS step by step](https://help.avdbapi.com/documentation/installation-guide-for-avdbcms/) · [First steps after install](https://help.avdbapi.com/documentation/avdbcms-first-steps/) · [A tour of the admin panel](https://help.avdbapi.com/documentation/avdbcms-admin-tour/) |
| Upgrade | [Update with one click from the admin](https://help.avdbapi.com/documentation/avdbcms-one-click-update/) · [Upgrade by hand (FTP or File Manager)](https://help.avdbapi.com/documentation/avdbcms-upgrade/) |
| Themes and looks | [The AVDBCMS themes and looks](https://help.avdbapi.com/documentation/avdbcms-themes/) · [Choose, preview and publish a look](https://help.avdbapi.com/documentation/avdbcms-choose-look/) · [Logo, colours, fonts and custom code](https://help.avdbapi.com/documentation/avdbcms-brand/) |
| Site settings | [Site basics: name, logo, languages and features](https://help.avdbapi.com/documentation/avdbcms-site-basics/) · [Members, sign-up, comments and email](https://help.avdbapi.com/documentation/avdbcms-members-comments/) |
| Videos and import | [Importing videos: AVDB and xvidapi explained](https://help.avdbapi.com/documentation/avdbcms-import-videos/) · [Import from AVDB step by step](https://help.avdbapi.com/documentation/avdbcms-import-avdb/) · [Import from xvidapi and automatic schedules](https://help.avdbapi.com/documentation/avdbcms-import-xvidapi/) · [Video uploads, the player and Upload18](https://help.avdbapi.com/documentation/avdbcms-uploads/) · [Manage videos, categories, tags and playlists](https://help.avdbapi.com/documentation/avdbcms-videos-categories/) |
| Ads and money | [Ads and ad places](https://help.avdbapi.com/documentation/avdbcms-ads/) · [Premium plans, payments and invoices](https://help.avdbapi.com/documentation/avdbcms-premium-payments/) |
| Search engines (SEO) | [SEO settings: what to set once](https://help.avdbapi.com/documentation/avdbcms-seo/) |
| Maintenance and backups | [Maintenance: health check, jobs, logs and cache](https://help.avdbapi.com/documentation/avdbcms-maintenance/) · [Backups and restore](https://help.avdbapi.com/documentation/avdbcms-backups/) |
| Security | [Keep your site safe (15-minute checklist)](https://help.avdbapi.com/documentation/avdbcms-security/) |
| Advanced | [Server setup: hosting panels, web servers and cron](https://help.avdbapi.com/documentation/avdbcms-server-setup/) |
| Help | [Troubleshooting and error fixes](https://help.avdbapi.com/documentation/avdbcms-error-fixes/) · [Frequently asked questions](https://help.avdbapi.com/documentation/avdbcms-faq/) · [Get help and report bugs](https://help.avdbapi.com/documentation/avdbcms-support/) |
| Releases and license | [Changelog](https://help.avdbapi.com/documentation/avdbcms-changelog/) · [What's new in AVDBCMS 1.2](https://help.avdbapi.com/documentation/avdbcms-whats-new-1-2-0/) · [License: AVDBCMS Community License 1.0](https://help.avdbapi.com/documentation/avdbcms-license/) |

In this package:

| File | What it covers |
|---|---|
| [UPGRADE.md](UPGRADE.md) | Updating: one click, by hand, going back, moving from a community test package |
| [CHANGELOG.md](CHANGELOG.md) | Everything that changed |
| [COMMUNITY_SETUP_GUIDE.md](COMMUNITY_SETUP_GUIDE.md) | Setup for each hosting panel, cron, importers, troubleshooting |
| [docs/server-configs/README.md](docs/server-configs/README.md) | Server setup files for Apache and Nginx (advanced) |
| [LICENSE](LICENSE) and [NOTICE](NOTICE) | The license and the third-party parts |

## Where to get help

- Help center: https://help.avdbapi.com/ (start with the
  [install guide](https://help.avdbapi.com/documentation/installation-guide-for-avdbcms/))
- Telegram group: https://t.me/avdb_api
- Telegram support: [@avdbapi_support](https://t.me/avdbapi_support)
- GitHub: https://github.com/avdbapi-com/avdbcms/issues (report bugs here)
- Security problems: please report them privately, see [SECURITY.md](.github/SECURITY.md)

When you ask for help, tell us your AVDBCMS version, your PHP version, your database version, your web server and
what you see on the screen. Never post passwords, keys or the contents of your `.env` file.

## License

From release 1.2.0, AVDBCMS is under the **AVDBCMS Community License 1.0** (file `LICENSE`). In plain words:
**share it, don't sell it.**

- **Use it for free**, also for your own website that earns money, for example with ads or premium plans.
- **Copy it, change it and share it.** Shared copies stay free and under the same license, keep every notice, mark
  their changes and carry no money-making code or links (ads, affiliate codes, paid links, miners) of the person who
  shares them.
- **Never sell it.** No payment for copies, downloads, changed versions, themes or parts of it, installation packages
  or access behind a paywall, and none for installing, setting up, running, supporting, changing or handing over
  AVDBCMS for other people, or for hosting plans that come with AVDBCMS installed (a host whose customers install it
  themselves is fine). Selling a website that you have really run yourself is allowed, as long as AVDBCMS on it stays
  free with every notice kept and no price is asked for AVDBCMS itself.
- The code of the original open-source project that AVDBCMS is based on keeps its MIT License (Copyright (c) 2024
  Anton Bourtnik). Laravel and the other PHP and JavaScript packages keep their own licenses (mostly MIT, also
  Apache-2.0, BSD and LGPL), and the FFmpeg programs in `bin/` are under the GNU GPL version 3. `LICENSE` and `NOTICE`
  list them.

The license text is written in plain English and is the one that counts; this summary is not legal advice.

Official sources: https://github.com/avdbapi-com/avdbcms, https://help.avdbapi.com/, https://avdbapi.com,
https://xvidapi.com, https://upload18.org and the Telegram group https://t.me/avdb_api.
