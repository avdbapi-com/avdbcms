# Changelog

All notable changes to AVDBCMS. From 1.2.0 on, the version numbers follow semantic versioning: a release with fixes
only raises the last number, a release with new features the middle one.
How to update your site: [UPGRADE.md](UPGRADE.md). Every release with its files:
https://github.com/avdbapi-com/avdbcms/releases

## 1.2.0 (2026-10-23)

The first public release of AVDBCMS, and the first release on GitHub. It brings together everything that was built
and tested in the community test packages (see [Before 1.2](#before-12-the-community-test-packages) at the end of this
file) and adds one-click updates from GitHub. The lists below compare 1.2.0 with the community test packages of 12 to
17 October 2026.
How to install: [README.md](README.md). How to move a site from a community test package to 1.2.0:
[UPGRADE.md](UPGRADE.md).

### Highlights

- **32 themes and 12 complete looks.** Every theme has a light and a dark version. A look sets the theme, the
  homepage, the video page and the video cards in one click.
- **A new default look, YouTube Spotlight Pro:** live ranked lists at the top, category chips, 16:9 thumbnails and a
  two-column video page with "Up next". New installs start with it.
- **A new admin that a newcomer can read:** 8 sections with plain names (Home, Content, Community, Members, Money,
  Appearance, Settings, Tools) instead of one settings page with 19 tabs. Search the whole admin with Ctrl+K (or `/`).
- **Preview, publish, undo:** you try a look on your real site while visitors still see the old one, then you publish
  it. History lists the last 20 changes, and one click undoes a change.
- **One-click updates from GitHub:** **Tools › Updates** shows a new release; one click downloads it, checks it, backs
  up what it replaces, installs it and makes the database changes. Never automatic.
- **Lighter pages:** the home page HTML went from 202 KB to 125 KB and the admin settings page from 4.4 MB to 31 KB.
  In long video lists, each new page of videos makes the browser restyle about 4 times fewer page elements.
- **AVDBCMS Community License 1.0:** free to use, change and share, never sold.
- **Not changed:** the Upload18 upload, the video player and the embed code work exactly as before.

### License

- **AVDBCMS Community License 1.0** from this release (file `LICENSE`): use AVDBCMS free of charge, also for your own
  website that earns money, copy it, change it and share it; nobody may sell it, and charging others for installing,
  setting up, running, supporting, changing or handing over AVDBCMS, or for hosting plans with AVDBCMS installed,
  counts as selling. Shared copies stay free and carry no money-making code or links (ads, affiliate codes, paid links,
  miners) of the person who shares them.
- The code of the original open-source project that AVDBCMS is based on keeps its MIT License; its notice ("Copyright
  (c) 2024 Anton Bourtnik") stays word for word in `LICENSE`, Part B. Laravel, the other PHP and JavaScript packages
  and the bundled FFmpeg programs keep their own licenses. The new file `NOTICE` names them.
- The community test packages carried the MIT License file of the original project. A copy of one of them that
  someone already received keeps that license (`LICENSE`, Part A, section 7).

### Themes and looks

- 32 themes in 13 families, with 174 colour styles. Each theme has its own layout, fonts and shapes and a light and a
  dark version. The second version is a designed palette, not an inversion, and both pass the same contrast checks.
- 12 complete looks: YouTube Spotlight Pro (the default), Cinema Night, Velvet Lounge, Shorts Feed, Swiss Bento, Night
  City HUD, Esports Arena, Deep Space Theater, Imperial Salon, Magazine, Glass Lounge and Daylight Creator. A look sets
  the theme and its colour style, the homepage, the video page, the card layouts and the effect levels in one click.
  It never touches your site texts, logo, custom code, menus or ads.
- The themes really differ: they replace the 120 themes of the test packages, many of which looked almost the same,
  and no two of the new themes share the same page skeleton.
- All 120 old theme names still open, as their closest new theme.
- Easier to read: no text, button or badge is too faint in any of the 32 themes, in light or dark (the old designs had
  626 of these problems, 99 of them in focus rings); titles over bright video pictures meet a contrast of 4.5:1;
  keyboard focus rings are visible.
- Phones: at least 2 whole videos on the first screen, in all 32 themes and 12 looks. On the video page, the title and
  2 "Up next" videos show without scrolling with the video page layouts "Wide player on top" and "Centred, no
  distractions" (the looks Cinema Night, Velvet Lounge, Shorts Feed and Deep Space Theater); with the other layouts,
  "Up next" comes further down the page. On a phone the default look shows one ranked list (top 3) at the top.
- Daily designs stay on for the sites that used them, with a new daily calendar.
- Per page, the themes load at most 2 font families (3 font files, 90.4 KB) and at most 25.3 KB of compressed theme
  styles.

### Appearance in the admin

- Pages under **Appearance**: Themes; Logo, colours & fonts; Homepage; Video page; Video cards; Header, menus & footer;
  Effects & speed; Other pages & pop-ups; Creator channels; Custom code (in Developer mode).
- Work on a draft, preview it on your own site (visitors still see the old design), then click **Publish**; the
  message that follows has an Undo button. History lists the last 20 changes (50 are kept per group of settings), each
  with Undo. Publishing and undoing were checked to put every setting back exactly.
- The announcement bar moved to **Appearance › Header, menus & footer**.

### A new admin

- 8 sections: Home, Content, Community, Members, Money, Appearance, Settings and Tools. The settings are split into
  short pages: All settings, Site basics, Features on/off, Members & comments, Videos & uploads, Email, Google &
  sharing, Security, Languages, Maintenance mode, Redirects and Advanced.
- Home: "Your site is live", the checklist **Get your site ready** and a **Needs you** list (for example when
  scheduled tasks are not running).
- The site status in the top bar of every admin page checks three things: maintenance mode, Google and email. For
  example, it shows "Emails are not sent" while your site writes emails to a log file instead of sending them.
- Search pages, settings, members and videos with Ctrl+K, Cmd+K or `/`. Old names work too.
- A save bar appears only when something changed ("1 unsaved change"); Ctrl+S saves.
- **What moved where** lists where the old pages went. Old admin addresses keep working: old links to settings tabs
  open the new page, and old page addresses were kept.
- List pages with bulk actions, for example: tick 2 videos, then **Make private**.
- The admin works in light and dark and on a phone. It was checked on 111 admin pages on computer, tablet and phone,
  in light and dark, also in Vietnamese and Arabic; the automatic accessibility check (WCAG AA) is clean, and 18 of 18
  keyboard-only steps work.
- For now, the old admin stays one click away (account menu › **Use the old admin for now**); its broken
  "Make/Remove administrator" action was fixed.
- Developer tools (Custom code, Database, Developer zone) stay hidden until you turn on Developer mode in your account
  menu.
- The AVDBAPI import page shows the provider check of the last few minutes, with a **Refresh** button, instead of
  waiting for the provider. The import itself works as before.
- The admin texts are in every language file. When the new admin was built, every new admin text was reviewed and
  corrected in all 16 languages besides English; a second review fixed 384 texts with a wrong meaning.

### Speed

- Smaller pages, and the first videos show sooner: the home page HTML went from 202 KB to 125 KB.
- A lighter admin: the settings page went from 4.4 MB to 31 KB, **Appearance › Themes** from 4.4 MB to 91 KB and
  Home from 158 KB to 35 KB.
- Long video lists: when the next page of videos arrives, the browser now restyles only the new cards. That is about
  3,600 page elements per new page instead of about 14,300, about 4 times less (measured with the default look). The
  look of the site does not change.
- Older browsers that do not support the newest CSS keep a solid header bar over the home hero.

### One-click updates

- New page **Tools › Updates** (`/admin/updates`): when GitHub has a newer release of AVDBCMS, it shows "AVDBCMS
  X.Y.Z is available", and Home shows the same news. The check runs at most every 12 hours, or when you click
  **Check now**, and it sends nothing about your site. **Tools › Health check › Server** shows the AVDBCMS version.
- **Update to X.Y.Z** asks for your admin password. Then AVDBCMS downloads `avdbcms-update-X.Y.Z.zip` in small
  parts (a broken download continues where it stopped), checks its SHA-256 checksum, makes a database backup that
  shows on **Tools › Backups**, keeps a copy of every file it replaces, installs the new files during a short
  maintenance window and runs **Finish update**.
- It works without the PHP zip extension (it then unpacks with PHP's built-in PharData).
- Never automatic: you start every update. If a step before the database changes fails, the old files go back by
  themselves. **Undo this update** brings back the files of the version before; the database stays as it is.
- Never touched: `.env`, `.user.ini`, everything in `storage/` (uploads, logs, backups, the install lock), the upload
  folders in `public/`, the FFmpeg programs in `bin/` and your own files. Server files that you changed (such as
  `.htaccess`) are kept, with the new file saved next to them, and translations that you changed are kept.
- The version of the code is 1.2.0.

### Release packages

- Every release on GitHub has 4 files: `avdbcms-X.Y.Z.zip`, the full package for new sites, with the FFmpeg programs
  in `bin/`; `avdbcms-update-X.Y.Z.zip`, the same files without `bin/`, for updates; and a `.sha256` checksum file
  for each of them. GitHub's "Source code" downloads hold the source code only, without `vendor/` and
  `public/build/`, so the installer cannot start from them.
- The source code is public: https://github.com/avdbapi-com/avdbcms

### Update and database

- The themes and the new admin bring 3 database changes, which **Finish update** on Home makes: a table for the
  settings history, the move of the appearance settings to the new theme collection, and a column for admin
  preferences. Clicking **Finish update** twice does nothing the second time.
- After that update, Home shows "Your look moved to the new theme collection" with **See changes**, **Restore my
  previous look** (possible for 30 days) and **Keep the new look**.
- Kept by the update: colours set by hand, logos, footers and your own CSS and JavaScript code. A site that used a
  ready-made theme moves to its closest new theme (dark stays dark, light stays light). This was tested with 9
  different old sites, and **Restore my previous look** was exact on all 9.
- 8 old hero and layout view files were removed. If you edited theme view files by hand, see
  [UPGRADE.md](UPGRADE.md).

### Security

- Payment keys (Stripe, PayPal): saving asks for your admin password again; a saved key is never shown again (only
  "ends in ..."); changes are logged by name, never with the value.
- The admin IP lock works behind a proxy or Cloudflare without extra setup, and a faked address from the internet is
  still ignored. If you lock yourself out, `php artisan avd:admin-ip-lock off` opens the admin again.
- The **Server** and **PHP** tabs of **Tools › Health check** hide secret values by their name, whatever their length.
- A security review of the new admin found 2 serious and 2 small issues, all inside the admin (visitors could not
  reach them). All were fixed and tested again before the release.

### Install

- The 6-step installer works as before. A new site opens with the look YouTube Spotlight Pro and has no videos yet.
  `/install` is locked after the install.

### Not changed

- The Upload18 upload, the video player and the embed code are the same, byte for byte, and the embed pages look the
  same.
- The PHP packages in `vendor/`, the FFmpeg programs in `bin/` and the server requirements (PHP 8.3 or newer, MySQL
  5.7+ or MariaDB 10.3+) are the same as in the community test package of 17 October 2026.

### Known limits of 1.2.0

- The admin lists of videos, members and comments are about as fast as before, not faster; the video list loads
  full-size thumbnails.
- In the admin, the video and member lists show an error page when someone types a special web address with a list in
  the search field by hand (as before).
- **Tools › Health check › PHP** stays visible without Developer mode (it shows no secret).
- Some older texts that only the old admin shows were checked by machine only; the Spanish texts mix the polite and
  the familiar "you" in older texts.
- On a computer, a very fast scroll can reach the end of the loaded list before the next page arrives, so the footer
  jumps for a moment.
- On a weak phone, the largest picture of the home page appears later than before, because the phone home page now
  shows videos first, without a big banner.
- xvidapi sends about 44 videos per page, whatever page size you choose.
- **Tools › Backups** has no restore button: restore a database backup with the database tool of your hosting panel.
- The one-click update checks that the update package arrived complete and unchanged (SHA-256). This does not prove
  who published it, so download AVDBCMS only from https://github.com/avdbapi-com/avdbcms.
- **Undo this update** puts back files only. If the old version shows errors after an undo, restore the database
  backup that the update made.

## Before 1.2: the community test packages

From 12 to 23 October 2026, AVDBCMS was built and tested in community test packages. They were not public downloads
and have no version numbers. Everything in them is part of 1.2.0.

**23 October 2026**

- Speed fix for long video lists: each new page of videos makes the browser restyle only its own cards, about 4 times
  less work than before. The look did not change.
- No database change.

**22 October 2026**

- The 32 themes, the 12 complete looks and the new admin (described under 1.2.0 above).
- **Finish update** on Home makes the 3 database changes; **Restore my previous look** works for 30 days.

**17 October 2026**

- Placeholder cards show while the video feed loads, and the feed starts loading earlier.
- Effects adapt to the device: weaker devices get a lighter effects level by themselves.
- Smaller pages: theme styles and page scripts moved into files that the browser keeps; the home page HTML went from
  about 430 KB to about 238 KB. If a host cannot serve the theme style files, `AVD_THEME_CSS_INLINE=true` in `.env`
  puts them back into the page.
- Smoother scrolling: the header shadow, the reading progress bar, the sticky watch bar and the back-to-top button no
  longer run code on every scroll. In older browsers, the progress bar still uses a script.
- One shared engine for pointer effects and click ripples, and the old animation library was removed (the motion code
  went from 51 KB to 18 KB). Pointer effects follow the admin's motion switch and the device's "reduce motion" setting.
- Watch page: keyboard shortcuts act once (they acted twice), and the comments area keeps its space, so the footer no
  longer jumps.
- Sites whose build files are served from a /public address no longer download the scripts and the icon font twice.

**15 October 2026**

- The creator spotlight on the home page no longer shrinks cards to thin slivers or lets them overlap.
- Smoother scrolling on weak phones: the motion scheduler was rewritten (the old one paused and restarted every
  decorative animation during a scroll), decorations off screen stop moving, blur effects have a limit, and creator
  avatars no longer move.
- Items fade in a little before they reach the screen, with a shorter animation.

**14 October 2026**

- Videos that members upload open on their watch page (they showed "not found").
- Videos with titles in scripts such as Chinese, Japanese or Thai get a working web address; old addresses of the
  form `/video/-<id>` redirect (301).
- Video ads before the video can be clicked: one ad per line `VIDEO_URL | CLICK_URL`, an optional default click link
  and a "Visit advertiser" button.
- Search also finds code, actor, writer, author, country, quality, category title and channel.
- Views by guests are counted.
- The placeholders "Updating" and "N/A" are hidden on watch pages and no longer imported from xvidapi.

**13 October 2026**

- xvidapi imports translate the tags into every active site language; the titles stay in their original language.
- xvidapi imports can be limited to one original language.
- xvidapi schedules made in the admin import the categories you ticked (before, every run imported 0 videos) and use
  your Start Page, Page Size and Sort.
- Saving the Appearance settings no longer resets the dark or light choice, and turning off Trending or History hides
  all their links.
- With the "log" mailer, emails are written to `storage/logs/mail.log`. The background worker no longer stops every
  minute on hosts that turn off PHP's pcntl functions.

**12 October 2026 (second package of the day)**

- Sites behind Cloudflare with "Flexible" SSL no longer loop between https and http; the installer keeps https and
  trusts Cloudflare as a proxy.
- Avatar uploads work on hosts without PHP's fileinfo extension.
- Phones get bigger tap areas, and on tablets the side menu works between 768 and 991 pixels wide.
- Installing over an existing database works on MySQL 8.

**12 October 2026: the first clean community test package**

- A clean package with clean demo data: no private files and no developer tools.
- The package includes the install database, so the installer does not need the `mysql` command-line program (which
  fails on shared hosts).
- Weak devices are found by themselves (4 GB of memory or less, data saver, reduced motion or a slow frame test) and
  get a lighter mode without blur and decorative animation, for smooth scrolling on weak phones.
