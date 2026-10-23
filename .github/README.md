<!--
  Repository page of AVDBCMS (shown by GitHub from .github/README.md).
  The package README for people who download the zip is /README.md and stays unchanged.
  Art in /docs/images: made for AVDBCMS by AvdbAPI.com, under the AVDBCMS Community License 1.0 like the rest of docs/
  (see docs/images/CREDITS.md).
-->

<p align="center">
<a href="#quick-install"><picture>  <source media="(prefers-color-scheme: dark)" srcset="/docs/images/avdbcms-hero-dark.svg">  <source media="(prefers-color-scheme: light)" srcset="/docs/images/avdbcms-hero-light.svg">  <img alt="AVDBCMS: the free, source-available video sharing CMS. 32 themes and 12 complete looks, 17 languages, ads and premium plans, imports from AVDB and xvidapi, member uploads and one-click updates." src="/docs/images/avdbcms-hero-dark.svg" width="100%"></picture></a>
</p>

<h3 align="center">Your own video sharing site, filled with videos from AVDB and xvidapi. Free, source-available, with an admin made for beginners.</h3>

<p align="center">
<a href="https://github.com/avdbapi-com/avdbcms/releases/latest"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-download-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-download-light.svg"><img alt="Download AVDBCMS 1.2.0 (latest release)" src="/docs/images/badge-download-dark.svg" height="30"></picture></a> <a href="#license"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-license-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-license-light.svg"><img alt="License: AVDBCMS Community License 1.0 (free to use and share, never sold)" src="/docs/images/badge-license-dark.svg" height="30"></picture></a> <a href="#what-you-need"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-php-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-php-light.svg"><img alt="PHP 8.3 or newer" src="/docs/images/badge-php-dark.svg" height="30"></picture></a> <a href="#what-you-need"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-db-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-db-light.svg"><img alt="MySQL 5.7+ or MariaDB 10.3+" src="/docs/images/badge-db-dark.svg" height="30"></picture></a> <a href="#12-complete-looks"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-themes-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-themes-light.svg"><img alt="32 themes, dark and light" src="/docs/images/badge-themes-dark.svg" height="30"></picture></a> <a href="https://t.me/avdb_api"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-telegram-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-telegram-light.svg"><img alt="Get help on Telegram: t.me/avdb_api" src="/docs/images/badge-telegram-dark.svg" height="30"></picture></a>
</p>

<p align="center"><a href="#quick-install"><b>Quick install</b></a> · <a href="#one-click-updates">One-click updates</a> · <a href="https://help.avdbapi.com/documentation/installation-guide-for-avdbcms/">Install guide</a> · <a href="/CHANGELOG.md">What is new</a> · <a href="https://help.avdbapi.com/">Help center</a></p>

AVDBCMS is free, source-available software for a video sharing site on your own hosting. It fills your site with videos from AVDB and xvidapi, lets your members upload videos through Upload18, and comes with members and channels, ads, premium plans and an admin panel made for beginners. AVDBCMS runs on Laravel 12 and needs PHP 8.3 or newer. It is based on an open-source project by Anton Bourtnik (MIT License).

<!-- screenshots -->
<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/screenshots/avdbcms-frt-01.webp"><source media="(prefers-color-scheme: light)" srcset="/docs/images/screenshots/avdbcms-frt-02.webp"><img alt="AVDBCMS demo site home page in the default look YouTube Spotlight Pro: header with search, side menu with categories, the ranked lists Viral velocity and Fastest rising, category chips and the Recommended Videos grid" src="/docs/images/screenshots/avdbcms-frt-01.webp" width="100%"></picture><br><sub>The default look, YouTube Spotlight Pro, on a demo site (dark and light follow your GitHub theme)</sub></p>

<table>
<tr><td width="76%" valign="top"><img alt="AVDBCMS demo video page Crossing the dunes on foot in the default dark look: the player with its toolbar, title, views and date, like, share, save and report buttons, the channel with Subscribe, and the Up next and related videos column" src="/docs/images/screenshots/avdbcms-frt-03.webp" width="100%"><br><sub>A video page in the default look<br>1 video player, 2 Up next and related videos, 3 player toolbar: lights, size, sleep timer and more tools, 4 title, 5 views and date, 6 Like, Share, Save and Report, 7 channel with the Subscribe button</sub></td><td width="24%" valign="top"><img alt="AVDBCMS demo home page on a phone in the dark default look: header with menu button, logo, search and sign-in icons, the Viral velocity ranked list, category chips, two video cards and the bottom bar" src="/docs/images/screenshots/avdbcms-phn-01.webp" width="100%"><br><sub>The home page on a phone<br>1 menu button and logo, 2 search, sign in and register, 3 ranked list (swipe for fastest rising), 4 video grid, two per row, 5 bottom bar</sub></td></tr>
</table>

<table>
<tr><td width="72%" valign="top"><img alt="AVDBCMS admin on the All videos page: logo and site name, the menu with 8 sections, What moved where and Search everything, the top bar with search, site status, View site and account button, the page title with its breadcrumb and the status tabs" src="/docs/images/screenshots/avdbcms-adm-01.webp" width="100%"><br><sub>The admin at a glance, here on Content › All videos<br>1 logo, site name and domain (back to Home), 2 the 8 sections, 3 What moved where and Search everything, 4 search (Ctrl+K), 5 site status, 6 View site, 7 account menu, 8 where you are, the title and what the page is for, 9 tabs</sub></td><td width="28%" valign="top"><img alt="AVDBCMS admin search window with lo typed: the result groups Pages, Settings, Members and Videos, with the keyboard hints at the bottom" src="/docs/images/screenshots/avdbcms-adm-03.webp" width="100%"><br><sub>Search everything (Ctrl+K)<br>1 what you type, 2 pages, 3 settings, with where they are, 4 members, 5 videos, 6 keys: arrows to move, Enter to open</sub></td></tr>
</table>

<table>
<tr><td width="51%" valign="top"><img alt="AVDBCMS admin, All videos: tabs All, Public, Private and Banned, the search box, category menu, Published dates, sort menu, and a list of demo videos with poster, status, category, views, comments and date" src="/docs/images/screenshots/avdbcms-cnt-01.webp" width="100%"><br><sub>Content › All videos: every video, imported or uploaded<br>1 status tabs, 2 search titles and descriptions, 3 category menu, 4 published from and to dates, 5 sort menu and Search, 6 tick videos for the bulk actions, 7 status of each video, 8 row menu</sub></td><td width="49%" valign="top"><img alt="AVDBCMS admin, XVIDAPI tab: Categories Selection filtered to Japanese with All, None and Popular, the Specific Video IDs box, and Import Parameters with Start Page, Pages to Import, Videos Per Page, Request Delay, Thumbnails Storage, Starter Views Mode, the Language box, Search &amp; Content Filters, Skip Tags, Dry Run and Start XVIDAPI Import" src="/docs/images/screenshots/avdbcms-imp-10.webp" width="100%"><br><sub>Import videos, tab XVIDAPI: tick categories, choose how many pages, start<br>1 All, None, Popular, 2 filter category names, 3 a ticked category, 4 or specific video IDs, 5 start page, pages to import, videos per page, request delay, 6 thumbnails storage, 7 starter views, 8 language and tag translation, 9 search and content filters, 10 Skip Tags and Dry Run, 11 Start XVIDAPI Import</sub></td></tr>
</table>

<table>
<tr><td width="33%" valign="top"><img alt="Demo site video page in its dark look: the ad break in the player with the demo backup ad, the Ad badge and Skip in 2s, an example ad inside the Up next list and the example box Watch with fewer breaks next to the video" src="/docs/images/screenshots/avdbcms-ads-04.webp" width="100%"><br><sub>Ad places on a video page (example ads of the demo)<br>1 video ad before the video (here the backup ad), 2 ad inside the Up next list, 3 box next to the video</sub></td><td width="67%" valign="top"><img alt="Demo site player during the ad break: the Ad badge with 4 seconds left, the demo backup ad Support uninterrupted discovery with Learn more, and the button Skip in 2s" src="/docs/images/screenshots/avdbcms-ads-05.webp" width="100%"><br><sub>The video ad before a video, with a Skip button<br>1 Ad and the seconds left, 2 backup ad, shown when no video ad file loads, 3 Skip ad: counts down, then lets viewers skip</sub></td></tr>
</table>

<table>
<tr><td width="100%" valign="top"><img alt="AVDBCMS admin, Money › Earnings: four totals, the What needs setting up checklist with Connect card (Stripe) or PayPal payments still To do, and links to the other Money pages" src="/docs/images/screenshots/avdbcms-mon-01.webp" width="100%"><br><sub>Money › Earnings: your income and what still needs setting up before members can buy premium<br>1 Money menu, 2 this month, active subscribers, premium plans and all time, 3 what needs setting up: Done or To do, 4 card (Stripe) or PayPal payments, still to do, 5 shortcuts to the other Money pages</sub></td></tr>
</table>

<table>
<tr><td width="57%" valign="top"><img alt="AVDBCMS admin, Settings › Google &amp; sharing: Video page title {title} | {site_name}, an empty Default description and Google Search Console code, Mark the site as adult content, Sitemap and Video sitemap on, and More options" src="/docs/images/screenshots/avdbcms-seo-01.webp" width="100%"><br><sub>Settings › Google &amp; sharing: how your site appears in Google<br>1 video page title, 2 default description, 3 Google Search Console code, 4 mark the site as adult content, 5 Sitemap and Video sitemap (leave them on), 6 more options</sub></td><td width="43%" valign="top"><a href="/docs/images/screenshots/avdbcms-tls-01.webp"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/screenshots/avdbcms-tls-15.webp"><source media="(prefers-color-scheme: light)" srcset="/docs/images/screenshots/avdbcms-tls-01.webp"><img alt="AVDBCMS admin, Tools › Health check, tab Checks: 21 healthy checks, 2 warnings, 0 blocking issues, the Auto-fix and Re-probe buttons and the PHP limits with their recommended values" src="/docs/images/screenshots/avdbcms-tls-01.webp" width="100%"></picture></a><br><sub>Tools › Health check: checks with one-click fixes<br>1 Checks, Server and PHP tabs, 2 healthy checks, warnings and blocking issues, 3 Auto-fix, 4 Re-probe, 5 PHP limits with the recommended values</sub></td></tr>
</table>

<sub>Screenshots of AVDBCMS 1.2.0 with demo data; the numbers mark what each caption explains. The guides in the [help center](https://help.avdbapi.com/) go through the admin step by step.</sub>
<!-- /screenshots -->

## What you get

<table>
<tr>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-themes-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-themes-light.svg"><img src="/docs/images/icon-themes-dark.svg" width="40" height="40" alt=""></picture><br><b>32 themes and 12 complete looks</b><br>32 themes in 13 families, with 174 colour styles. Each theme has its own layout, fonts and shapes, and a light and a dark version. A complete look sets the theme, the homepage, the video page, the video cards and the effects in one click, and never touches your site texts, logo, custom code, menus or ads (<a href="#12-complete-looks">see the 12 looks</a>). You preview a change on your real site first: visitors keep the old design until you click <b>Publish</b>, and History lists your last 20 design changes, which you can undo.</td>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-import-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-import-light.svg"><img src="/docs/images/icon-import-dark.svg" width="40" height="40" alt=""></picture><br><b>Videos from AVDB and xvidapi</b><br><b>Content › Import videos</b> has one tab for AVDB (<b>AVDBAPI</b>) and one for xvidapi (<b>XVIDAPI</b>). No API key is needed. Choose categories and how many pages, then start the import: it runs in the background, so you can close the browser tab. Saved schedules repeat an import by themselves (they need the cron line, see <a href="#quick-install">Quick install</a>). By default the video pictures load from the provider and take no space on your hosting. Imported videos play in the Upload18 player. A new site has no videos, and no import runs until you start one.</td>
</tr>
<tr>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-members-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-members-light.svg"><img src="/docs/images/icon-members-dark.svg" width="40" height="40" alt=""></picture><br><b>Members, uploads and channels</b><br>Visitors can create an account. Members comment, like videos, save them to Watch Later or a playlist, and subscribe to channels. Members can upload videos when you switch on <b>Let members upload videos</b> on <b>Settings › Features on/off</b>; Upload18 stores the uploads. <b>Appearance › Creator channels</b> sets how channel pages look and what creators may change. In the admin, <b>Members</b> finds, verifies and blocks members and shows their logins, and <b>Community</b> has reports, comments, messages and announcements.</td>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-ads-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-ads-light.svg"><img src="/docs/images/icon-ads-dark.svg" width="40" height="40" alt=""></picture><br><b>Ads and premium plans</b><br><b>Money › Ads</b>: one switch for all ads, four ad spaces (a banner at the top, a box next to the video, a banner at the bottom and a small banner on phones), ads inside video lists, a video ad before videos with a Skip button, and a pop-under ad. With <b>No ads for premium members</b> (on by default), paying members see no ads. <b>Money › Premium plans</b>: monthly or yearly plans and a free trial. <b>Money › Payment setup</b> connects card payments (Stripe) and PayPal, and <b>Money › Earnings</b> shows your income and what still needs setting up.</td>
</tr>
<tr>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-admin-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-admin-light.svg"><img src="/docs/images/icon-admin-dark.svg" width="40" height="40" alt=""></picture><br><b>An admin made for beginners</b><br>8 sections with plain names: Home, Content, Community, Members, Money, Appearance, Settings and Tools. Home starts with a checklist, <b>Get your site ready</b>, and a <b>Needs you</b> list. Press Ctrl+K (or <code>/</code>) to search every page, setting, member and video. A save bar shows what you changed; Ctrl+S saves. <b>What moved where</b> helps if you knew the old admin. The admin works in light and dark, and on a phone.</td>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-languages-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-languages-light.svg"><img src="/docs/images/icon-languages-dark.svg" width="40" height="40" alt=""></picture><br><b>17 languages</b><br>English, French, Thai, Indonesian, Korean, Vietnamese, Chinese, Japanese, German, Spanish, Portuguese, Russian, Arabic (right to left), Turkish, Hindi, Malay and Filipino, for your visitors and in the admin. On <b>Settings › Languages</b> you turn languages on or off, edit their translations or add a language. AVDB also sends the titles in 16 more languages; xvidapi translates the tags into your site languages, and its titles stay in their original language.</td>
</tr>
<tr>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-shield-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-shield-light.svg"><img src="/docs/images/icon-shield-dark.svg" width="40" height="40" alt=""></picture><br><b>Health check, backups and safety</b><br><b>Tools</b> has a health check with one-click fixes, database backups, background jobs, logs and one button to clear the cache. While you are signed out, <code>/admin</code> only shows "not found", and developer tools stay hidden until you turn on Developer mode in your account menu. Then go through the <a href="https://help.avdbapi.com/documentation/avdbcms-security/">15-minute safety checklist</a>.</td>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-speed-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-speed-light.svg"><img src="/docs/images/icon-speed-dark.svg" width="40" height="40" alt=""></picture><br><b>Light on weak phones</b><br>With the default settings, weaker phones get lighter effects by themselves: on <b>Appearance › Effects &amp; speed</b>, <b>On slower devices</b> is set to <b>Auto</b>, and weak phones get still versions of the effects. The <b>Effects level</b> (Off, Calm, Standard or Maximum) sets every effect with one choice. Visitors can switch between dark and light.</td>
</tr>
<tr>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-seo-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-seo-light.svg"><img src="/docs/images/icon-seo-dark.svg" width="40" height="40" alt=""></picture><br><b>Search engines</b><br><b>Settings › Google &amp; sharing</b> sets how your site appears in Google and when it is shared on social media. Your sitemap is at <code>/sitemap.xml</code>: it lists the pages, videos and channels in every site language.</td>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-updates-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-updates-light.svg"><img src="/docs/images/icon-updates-dark.svg" width="40" height="40" alt=""></picture><br><b>One-click updates from GitHub</b><br>From 1.2.0 on, <b>Tools › Updates</b> shows when a new release is out. One click downloads it, checks it, backs up what it replaces and installs it. Nothing updates by itself. See <a href="#one-click-updates">One-click updates</a>.</td>
</tr>
</table>

## 12 complete looks

<p align="center"><picture>  <source media="(prefers-color-scheme: dark)" srcset="/docs/images/demo-looks-dark.webp">  <source media="(prefers-color-scheme: light)" srcset="/docs/images/demo-looks-light.webp">  <img alt="The 12 complete looks of AVDBCMS on the same demo site, each in the mode it opens in, with its theme and colour style: YouTube Spotlight Pro (the default), Cinema Night, Velvet Lounge, Shorts Feed, Swiss Bento, Night City HUD, Esports Arena, Deep Space Theater, Imperial Salon, Magazine, Glass Lounge and Daylight Creator" src="/docs/images/demo-looks-dark.webp" width="100%"></picture></p>

A look sets the theme, the homepage, the video page, the video cards and the effects in one click. It never touches your site texts, logo, custom code, menus or ads. Pick one on **Appearance › Themes**, preview it on your real site, then click **Publish** ([guide](https://help.avdbapi.com/documentation/avdbcms-choose-look/)).

| Look | Theme · colour style | Homepage | Opens in | Made for |
|---|---|---|---|---|
| **YouTube Spotlight Pro**, the default for new sites | Midnight Spotlight · Hot Pink | Spotlight | dark | Any site: the safest, most readable choice |
| **Cinema Night** | Noir Premiere · Premiere Red | Cinema rows | dark | Movies, series, premium catalogues |
| **Velvet Lounge** | Velvet Boudoir · Rose Gold | Cinema rows | dark | Romantic, lifestyle and premium after-dark catalogues |
| **Shorts Feed** | Fairy Floss · Fairy Floss | Shorts | dark | Short clips, anime, fan edits, phone-first audiences |
| **Swiss Bento** | Swiss Grid · Swiss Red | Mosaic | light | Design, portfolios, minimal brands |
| **Night City HUD** | Shibuya Rain · Shibuya | Control room | dark | Nightlife, music, gaming, anime |
| **Esports Arena** | Arena · Arena Red | Classic grid | dark | Esports, gaming highlights, streamers |
| **Deep Space Theater** | Interstellar · Orion | Cinema rows | dark | Science, space, ambient visuals, documentaries |
| **Imperial Salon** | Obsidian & Gold · 24K Gold | Mosaic | dark | Premium memberships, curated collections |
| **Magazine** | Broadsheet · Press Red | Classic grid | light | News, commentary, documentaries, podcasts |
| **Glass Lounge** | Liquid Glass · Iris | Spotlight | light | Lifestyle, beauty, music and modern brands |
| **Daylight Creator** | Daylight Studio · Daylight Blue | Classic grid | light | Tutorials, vlogs, education, weak devices |

The other 20 of the 32 themes have no look of their own: pick them on the **Themes** tab, with any of their colour styles. All of them: [The AVDBCMS themes and looks](https://help.avdbapi.com/documentation/avdbcms-themes/).

<table>
<tr><td width="50%" valign="top"><img alt="AVDBCMS admin, Appearance › Themes: the Appearance menu, the panel On your site now with YouTube Spotlight Pro, See changes and Undo the last change, the tabs Complete looks, Themes and Daily designs, look cards with pictures, and the preview panel with the publish bar" src="/docs/images/screenshots/avdbcms-thm-13.webp" width="100%"><br><sub>Appearance › Themes: pick a look, preview it on your site, publish<br>1 History: the last 20 changes, 2 On your site now, 3 See changes and Undo the last change, 4 preview of your site: Computer or Phone, 5 tabs Complete looks, Themes and Daily designs, 6 the Appearance pages, 7 a look and what it suits, 8 publish bar</sub></td><td width="50%" valign="top"><img alt="AVDBCMS demo home page in the look Cinema Night: a full-width banner with a featured video, menu links in the header, a Top 10 today row with big numbers, category chips and the Trending Now row, dark" src="/docs/images/screenshots/avdbcms-thm-02.webp" width="100%"><br><sub>Cinema Night: theme Noir Premiere, colour style Premiere Red<br>1 menu links in the header instead of a side menu, 2 big banner that changes by itself, 3 Top 10 row, 4 category chips, 5 rows of videos</sub></td></tr>
<tr><td width="50%" valign="top"><img alt="AVDBCMS demo home page in the light look Swiss Bento: a large featured tile next to a numbered Most watched list, black-and-red category chips and the Latest videos grid on white" src="/docs/images/screenshots/avdbcms-thm-05.webp" width="100%"><br><sub>Swiss Bento: theme Swiss Grid, colour style Swiss Red, opens in light mode<br>1 large featured tile, 2 Most watched list, 3 category chips, 4 latest videos</sub></td><td width="50%" valign="top"><img alt="AVDBCMS demo home page in the look Night City HUD: a framed featured video with corner marks, a Now showing stats panel, bracketed category chips, the Trending now grid and a slim icon rail, dark" src="/docs/images/screenshots/avdbcms-thm-06.webp" width="100%"><br><sub>Night City HUD: theme Shibuya Rain, colour style Shibuya<br>1 framed featured video, 2 stats panel of the featured video, 3 icon rail instead of a full side menu, 4 category chips, 5 Trending now grid</sub></td></tr>
</table>

## Quick install

### What you need

- **PHP 8.3 or newer**, 64-bit. The installer checks these extensions: ctype, curl, dom, filter, hash, json, mbstring, openssl, pdo, pdo_mysql, session, tokenizer and xml. Recommended: bcmath, fileinfo, gd and zip, and the process functions `proc_open`, `proc_get_status` and `proc_close` (for imports, background jobs and backups).
- **MySQL 5.7+ or MariaDB 10.3+** (MySQL 8.0+ or MariaDB 10.6+ recommended), an empty database and a database user with all privileges on it.
- **A web server:** Apache, Nginx, OpenLiteSpeed or LiteSpeed. The `.htaccess` files (Apache, LiteSpeed) and the `web.config` files (IIS) are included. Nginx needs a rewrite rule: see [COMMUNITY_SETUP_GUIDE.md](/COMMUNITY_SETUP_GUIDE.md) and [docs/server-configs/](/docs/server-configs/README.md).
- **Memory and disk:** at least 1 GB of memory (2 GB or more recommended) and 2 GB of free disk space (20 GB or more recommended). The extracted package alone takes about 750 MB.
- **Upload limits:** PHP `upload_max_filesize` and `post_max_size` of at least 64M for the install check; video uploads usually need 256M or more.
- **One cron line**, so that scheduled imports, emails and clean-ups run (step 6 below).
- FFmpeg is not needed on the server: the full package brings FFmpeg for Linux (x64 and arm64) and Windows in `bin/`. An FFmpeg that your server already has works too.

The [setup guide](/COMMUNITY_SETUP_GUIDE.md) has steps for these hosting panels: aaPanel (Apache, Nginx or OpenLiteSpeed), CloudPanel, HestiaCP, CyberPanel, 1Panel, Virtualmin and cPanel.

### Install

1. In your hosting panel, create a site with PHP 8.3 or newer, and an empty MySQL or MariaDB database with a user that has all privileges on it.
2. Download the full package `avdbcms-X.Y.Z.zip` from the [latest release](https://github.com/avdbapi-com/avdbcms/releases/latest). Do not use GitHub's "Source code" downloads: they hold the source code only, and the installer cannot start from them.
3. Upload the zip to the folder that your domain serves and extract it there. The zip has no folder around the files. Best setup: point your domain to the `public/` folder inside it. The main folder works too.
4. Open `https://your-domain/install` and follow the 6 steps: Introduction, Requirements, Filesystem, Database, Admin Account and Install. If `/install` shows "404 Not Found", open `https://your-domain/index.php/install`. On the Database step, keep the choice **Import SQL backup**.
5. Sign in at `https://your-domain/login`, then open the admin at `https://your-domain/admin`. While you are signed out, `/admin` only shows "not found".
6. Add this cron line in your hosting panel, with your site folder as the path. The installer tries to add it for you.

   ```
   * * * * * cd /path/to/your/site && php artisan schedule:run >> /dev/null 2>&1
   ```

7. Follow the checklist **Get your site ready** on the admin Home.

Every step in detail: [Install AVDBCMS step by step](https://help.avdbapi.com/documentation/installation-guide-for-avdbcms/). Notes for each hosting panel: [COMMUNITY_SETUP_GUIDE.md](/COMMUNITY_SETUP_GUIDE.md).

<table>
<tr><td width="47%" valign="top"><img alt="AVDBCMS installer, Introduction step: domain and web server tiles, the 6-step bar, the detected panel, the SQL backup example.sql marked Found, and the Start Installation button" src="/docs/images/screenshots/avdbcms-ins-01.webp" width="100%"><br><sub>Step 4: the installer checks your server, then takes you through 6 steps<br>1 your domain, web server and the SQL backup found, 2 the 6 steps, 3 detected panel, 4 Start Installation, 5 the SQL backup example.sql: Found</sub></td><td width="53%" valign="top"><img alt="AVDBCMS admin Home: Your site is live, View site and Import videos buttons, the Get your site ready checklist, Needs you, This week with views, new videos, members, comments and earnings, Recent imports and Latest comments" src="/docs/images/screenshots/avdbcms-adm-07.webp" width="100%"><br><sub>Step 7: the admin Home with the checklist Get your site ready<br>1 your site is live, and this week in one line, 2 View site and Import videos, 3 Get your site ready, 4 Needs you, 5 This week, 6 Recent imports, 7 Latest comments</sub></td></tr>
</table>

### From the source code (for developers)

This repository holds the source code. The release zip adds what is built from it: the PHP packages in `vendor/`, the built styles and scripts in `public/build/`, and the FFmpeg programs in `bin/`. To run a copy of the source, you need PHP 8.3 or newer with Composer, and Node.js 20 (20.19 or newer) or Node.js 22.12 or newer, with npm 10 or newer:

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
3. AVDBCMS downloads the update package, checks its SHA-256 checksum, makes a database backup, keeps a copy of every file it replaces, installs the new files and makes the database changes.

<table>
<tr><td width="43%" valign="top"><a href="/docs/images/screenshots/avdbcms-upd-02.webp"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/screenshots/avdbcms-upd-19.webp"><source media="(prefers-color-scheme: light)" srcset="/docs/images/screenshots/avdbcms-upd-02.webp"><img alt="AVDBCMS admin, Tools › Updates on the demo site with an example release: the installed version 1.2.0, the new release with its notes, What's new, the Update button and the release page on GitHub" src="/docs/images/screenshots/avdbcms-upd-02.webp" width="100%"></picture></a><br><sub>Tools › Updates when a new release is out (the demo shows an example release, 1.2.1)<br>1 Check now, 2 the new version, 3 What's new, 4 release notes, 5 Update to 1.2.1, 6 release page on GitHub</sub></td><td width="57%" valign="top"><a href="/docs/images/screenshots/avdbcms-upd-10.webp"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/screenshots/avdbcms-upd-20.webp"><source media="(prefers-color-scheme: light)" srcset="/docs/images/screenshots/avdbcms-upd-10.webp"><img alt="AVDBCMS admin, Tools › Updates after an update on the demo site: the new version is installed, the counts of replaced, added and removed files, the database backup, the files you changed, and Show details" src="/docs/images/screenshots/avdbcms-upd-10.webp" width="100%"></picture></a><br><sub>The result of an update<br>1 the new version is installed, 2 What's new and Home, 3 files replaced, added and removed, and the database backup, 4 files you changed that the update kept, with the new copy next to them, 5 files you changed that were replaced, 6 Show details</sub></td></tr>
</table>

Nothing updates by itself: you start every update. If something goes wrong, the old files are put back, and **Undo this update** brings back the files of the version before. Your `.env`, your uploads in `storage/`, your backups and the FFmpeg programs in `bin/` are never touched.

A site that runs a community test package from before 1.2 moves to 1.2.0 once by hand: upload the update package, then open the admin Home and click **Finish update** when Home shows it. All details, also the manual way and how to go back: [UPGRADE.md](/UPGRADE.md). What is new: [CHANGELOG.md](/CHANGELOG.md). Step by step: [Update with one click from the admin](https://help.avdbapi.com/documentation/avdbcms-one-click-update/).

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

In this repository and in the download package:

| File | What it covers |
|---|---|
| [UPGRADE.md](/UPGRADE.md) | Updating: one click, by hand, going back, moving from a community test package |
| [CHANGELOG.md](/CHANGELOG.md) | Everything that changed |
| [COMMUNITY_SETUP_GUIDE.md](/COMMUNITY_SETUP_GUIDE.md) | Setup for each hosting panel, cron, importers, troubleshooting |
| [docs/server-configs/README.md](/docs/server-configs/README.md) | Server setup files for Apache and Nginx (advanced) |
| [LICENSE](/LICENSE) and [NOTICE](/NOTICE) | The license and the third-party parts |

## License

From release 1.2.0, AVDBCMS is under the **AVDBCMS Community License 1.0** ([LICENSE](/LICENSE)). In plain words: **share it, don't sell it.**

- **Use it for free**, also for your own website that earns money, for example with ads or premium plans.
- **Copy it, change it and share it.** Shared copies stay free and under the same license, keep every notice, mark their changes and carry no money-making code or links (ads, affiliate codes, paid links, miners) of the person who shares them.
- **Never sell it.** No payment for copies, downloads, changed versions, themes or parts of it, installation packages or access behind a paywall, and none for installing, setting up, running, supporting, changing or handing over AVDBCMS for other people, or for hosting plans that come with AVDBCMS installed (a host whose customers install it themselves is fine). Selling a website that you have really run yourself is allowed, as long as AVDBCMS on it stays free with every notice kept and no price is asked for AVDBCMS itself.
- The code of the original open-source project that AVDBCMS is based on keeps its MIT License (Copyright (c) 2024 Anton Bourtnik). Laravel and the other PHP and JavaScript packages keep their own licenses (mostly MIT, also Apache-2.0, BSD and LGPL), and the FFmpeg programs in `bin/` are under the GNU GPL version 3. [LICENSE](/LICENSE) and [NOTICE](/NOTICE) list them.

The license text is written in plain English and is the one that counts; this summary is not legal advice.

Official sources: https://github.com/avdbapi-com/avdbcms, https://help.avdbapi.com/, https://avdbapi.com, https://xvidapi.com, https://upload18.org and the Telegram group https://t.me/avdb_api.

## Support and community

- **Questions and setup help:** the [help center](https://help.avdbapi.com/) (start with the [install guide](https://help.avdbapi.com/documentation/installation-guide-for-avdbcms/)), the Telegram group [t.me/avdb_api](https://t.me/avdb_api) and Telegram support [@avdbapi_support](https://t.me/avdbapi_support).
- **Bugs:** report them on GitHub: [github.com/avdbapi-com/avdbcms/issues](https://github.com/avdbapi-com/avdbcms/issues).
- **Security problems:** please report them privately, see [SECURITY.md](/.github/SECURITY.md).
- **Contributing:** read [CONTRIBUTING.md](/.github/CONTRIBUTING.md) and our [Code of Conduct](/.github/CODE_OF_CONDUCT.md).
- **New releases:** watch this repository (Watch › Custom › Releases); **Tools › Updates** in your admin shows them too.

When you ask for help, tell us your AVDBCMS version, your PHP version, your database version, your web server and what you see on the screen. Never post passwords, keys or the contents of your `.env` file.

<div align="center">

If AVDBCMS saves you time, a star helps other site owners find it.

<a href="https://github.com/avdbapi-com/avdbcms"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-star-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-star-light.svg"><img alt="Star AVDBCMS on GitHub" src="/docs/images/badge-star-dark.svg" height="30"></picture></a> <a href="https://twitter.com/intent/tweet?text=AVDBCMS%3A%20a%20free%2C%20source-available%20video%20CMS%20with%2032%20themes%2C%2017%20languages%2C%20ads%20and%20premium%20plans%20built%20in.&amp;url=https%3A%2F%2Fgithub.com%2Favdbapi-com%2Favdbcms"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-share-x-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-share-x-light.svg"><img alt="Share AVDBCMS on X" src="/docs/images/badge-share-x-dark.svg" height="30"></picture></a> <a href="https://t.me/share/url?url=https%3A%2F%2Fgithub.com%2Favdbapi-com%2Favdbcms&amp;text=AVDBCMS%3A%20a%20free%2C%20source-available%20video%20CMS%20with%2032%20themes%2C%2017%20languages%2C%20ads%20and%20premium%20plans%20built%20in."><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-share-telegram-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-share-telegram-light.svg"><img alt="Share AVDBCMS on Telegram" src="/docs/images/badge-share-telegram-dark.svg" height="30"></picture></a>

</div>

<p align="center"><sub>Share it, don't sell it: AVDBCMS is free to use, change and share under the AVDBCMS Community License 1.0.<br>AVDBCMS is based on an open-source project by Anton Bourtnik (MIT License).</sub></p>
