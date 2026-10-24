# Changelog

All notable changes to AVDBCMS. From 1.2.0 on, the version numbers follow semantic versioning: a release with fixes
only raises the last number, a release with new features the middle one.
How to update your site: [UPGRADE.md](UPGRADE.md). Every release with its files:
https://github.com/avdbapi-com/avdbcms/releases

## 1.2.2 (2026-10-25)

A fix release for 1.2.1. Sites on 1.2.1 or 1.2.0 update with one click in **Tools › Updates**; every other way is in
[UPGRADE.md](UPGRADE.md). It has 4 database changes, which the update makes for you (**Finish update** on Home when you
update by hand).

### New: view-only demo accounts

- In **Members › All members**, open an account and switch on **View-only demo account**, then make it an
  administrator if it should see the admin. It can open the whole site and the admin, but nothing it does is saved:
  every change it tries is refused with "This is a view-only demo account: you can look at everything, but changes are
  switched off."
- Backups, logs, exports and other downloads, logging in as a member, the .env, database and server tools, the
  maintenance secret and the upload settings are closed to it. Keys, passwords, e-mail addresses, IP addresses and
  server paths are masked in what it sees, and the admin's searches never match e-mail addresses for it.
- Its pages show a small "View-only demo" note, and the members list marks the account **View-only**. Only an
  administrator who can make changes can switch it on or off, never on their own account. Other administrators,
  members and visitors are not affected.
- **Undo this update** waits while the site has view-only demo accounts, because older versions do not know them. The
  message says what to do first.

### Upload limits

- New sites start with the intended upload limits: free members may upload 10 videos of up to 500 MB each, 5 GB in
  all; premium members files of up to 2 GB, with no limit on the number of videos or on space. The bundled database
  held a test site's limits (1,000,000 videos, 9.77 GB per file and 10 TB for free members, 97.7 GB per file for
  premium members), and the Premium page showed them.
- Updating sets these limits only where your site still has the bundled values; a limit you changed stays.
  **Tools › Logs › Admin actions** shows which limits changed.
- Administrators upload with the premium limits: the member plans apply to members. Before, an administrator without
  premium counted as a free member.
- **Money › Premium plans** now shows the cards **What each plan may upload** and **Free trial**, with the same save bar
  as the settings pages. You type sizes in MB or GB (for example 750 MB or 1.2 GB); they are stored in bytes as before,
  and values you saved before are kept exactly. The help texts say what each limit does: 0 videos for free members
  means free members cannot upload; the premium number of videos and premium total space only appear on the Premium
  page.

### Ads

- **Money › Ads › Ad after every** now means how many videos come before each ad, for both ad shapes. A card ad comes
  after that many videos (with 8: eight videos, then the ad; before, it took the eighth place), then again after every
  8 videos. A full-width ad row comes after the row that holds video 8 (then 16, 24, ...). **Most ads per list** still
  sets the most ads in one list.
- A card ad always stands between two videos, so a list needs more videos than **Ad after every** to show one. Rows
  with a set number of places (for example 2 rows of 4 on the home page) need more places than that number: choose a
  smaller number, or more videos per row or more rows.
- Video lists with card ads show all their videos once the last ones have loaded; channel sections, search results,
  playlists and Up next show their ad only between two videos.
- The video ad setting "Skip button after" is now **Ad break length** (1 to 60 seconds; viewers can skip after 3
  seconds).

### Admin

- **Tools › Backups**: the "Back up now" message gives the backup's size the way the list does (for example 26.4 KB,
  not 0.03 MB).
- **Content › Import videos**: a paused schedule shows Paused under Next Run instead of a time in the past (also the
  ready-made "Hourly XVIDAPI Video Sync"); switching a schedule on works out its next run from its timing.
- Help texts match what the settings do: **Let members upload videos** (off stops every upload, administrators' too),
  **Upload button**, **Uploads per minute** and the AVDB language note (titles come with the import; nothing is filled
  in later in the background).
- Members: a new password that an administrator types for a member now works. It was saved hashed twice, so nobody
  could sign in with it. It follows the same rules as a member's own password.
- Admin colour "Automatic": strong red site colours (for example the looks Cinema Night and Magazine) now give the
  neutral blue admin accent, so admin buttons never look like Delete. Pastel rose and orange site colours keep their
  own colour.
- Old admin Reports: Block and Resolve follow each report's real status, and bulk Resolve marks reports as resolved
  (before, it set them back to waiting).
- The active XVIDAPI tab and the old admin's red outline buttons in dark mode read at 4.5:1 or more.
- The admin works on older phones, tablets and browsers (for example an iPhone or iPad on iOS 12 to 15, an older Android
  browser, or Firefox before 121): **Save changes**, the confirm questions, the search, the dialogs and the phone menu
  work there, and pages stay readable even when the admin's styles or scripts do not load. Before, an older device
  could show only a dark, empty page.
- In Safari (Mac, iPhone, iPad) the actions in a list row's menu (such as **Make administrator**, **Mark as read** or
  **Delete**) work: the menu closed before the click arrived. In older Chrome and Firefox they work too: the browser did
  not say which row was clicked.

### One-click updates

- A server or proxy that ignores download ranges no longer makes the update hold the whole update zip in PHP's memory:
  every part of the download goes straight to disk, so a 128 MB memory limit is enough.
- If the maintenance page closed by itself before any file was replaced, a click on **Finish the update** or **Undo the
  update** on that page, still open in a tab, opens **Tools › Updates** with a short note instead of an error page.
- On hosts whose file system tells upper and lower case apart, a file that a release renames by letter case only no
  longer stays behind under its old name; it goes into the update backup, and Undo puts it back.
- Finish no longer says an update is installed while PHP may still run old program files from its memory (OPcache):
  it clears them again, or asks you to have PHP restarted and then click Continue.
- The update to 1.2.2 still runs your current version's code until the files are switched, so the download,
  maintenance page and letter-case fixes apply in full from the next update.

### Security

- **Always use the secure address (https)** now also sends http visits to https when your site's address is https,
  wherever that is safe: a visit straight to your server, a visit through Cloudflare from a visitor who used http, or a
  visit through a proxy listed in SITE_TRUSTED_PROXIES in .env that says the visitor used http. Behind Cloudflare
  "Flexible" SSL, or a proxy that does not say how the visitor came, it does nothing, so it cannot cause a loop.
- The release no longer contains the development seeders FleetDataEnricherSeeder, UserSeeder and ModelSeeder: someone
  running them by hand got fake accounts, some with well-known passwords. Updating deletes the copies a site still has,
  unless they were changed.
- Member accounts with a password that others know get a random password when you update: the sample members
  pbergstrom and blackbox_creator of the community test packages before 1.2.0, and the test members that the
  installer's "Run migrations and seeders" created before 20 September 2026. The accounts and their content stay;
  **Tools › Logs › Admin actions** names them, and you can give them a new password in **Members**.

### Translations

- "Director" means a film director in 11 languages, French uses "étiquette" for video tags, and the Korean, Indonesian
  and Malay tag words are corrected. Tag names no longer show a machine translator's notes to visitors.

### For developers

- The user factory gives the password "password" only inside the test suite; elsewhere (for example in tinker) a
  random one.
- The release build refuses a package with any seeder other than the three the installer uses.

## 1.2.1 (2026-10-24)

A fix release for 1.2.0. Sites on 1.2.0 update with one click in **Tools › Updates**; every other way is in
[UPGRADE.md](UPGRADE.md). It has no database changes.

### One-click updates

- Finishing an update is quick on every host. The update's work files (about 18,500 files and the downloaded zip) are
  moved aside in one step and then deleted a few seconds at a time by **Tools › Updates** and by the scheduler. In
  1.2.0 the last step deleted them all at once, which took about 20 seconds on a slow disk and could run into the
  host's time limit.
- A Finish that was cut off and clicked again writes its admin log lines only once.
- If the site could not record the last file move of an update, the update now goes on to Finish. Before, it stopped
  with the new files in place but without the database step.
- **Show details** uses your site's time zone on every line. The lines written while the files were switched used the
  server's time zone.
- After an undo, **Show details** still lists the undo's steps, and the page says that the update was undone (it read
  as if the site had been updated). While an undo runs, the page says "Keep this page open until it says your files
  are back."
- The database backup step says "Backing up the database..." until it knows the tables, instead of "table 0 of 0".
- A new run hides the result of the run before it.
- A cancelled update is written to the admin log.
- A connection error keeps its closing bracket after a hidden address.
- The update from 1.2.0 to 1.2.1 still runs the 1.2.0 code until the files are switched, so its step log and labels
  look like 1.2.0's; the quick Finish already applies to it. Everything above applies in full from the next update.

### Admin

- **Tools › Backups** says "1 backup takes 35.3 KB" with the right singular or plural in every language, shows small
  sizes in KB, and keeps each size on one line. On a phone the age of a backup no longer runs into its file name.
  Hidden files of the backup folder are no longer listed as backups.
- File sizes keep the zeros of whole numbers everywhere: the free plan's 500 MB file limit read "5 MB" on the Premium
  page, and upload messages and the Exports list had the same fault.
- **Import videos › XVIDAPI:** the "Add New Scheduled Cron" form and the import progress window are readable in the
  light admin, and the example schedule name is neutral.
- **Import videos › AVDBAPI:** the example texts of the filters are neutral, and category names are no longer cut in
  the middle of a word.
- **Settings › Languages:** the Arabic name lines up with the other language names.
- The top bar of the admin fits at tablet widths in long languages (Arabic, Russian, German, French): the status
  shortens instead of pushing the account menu off the screen.
- The help text of **Bottom bar on phones** names the buttons the bar really shows.
- The old admin in light mode: the dark fields of both import pages are readable.

### Themes and pages

- The language picker in the side menu shows the whole language name, and its list stays inside the menu, also in
  right-to-left languages. The language picker in the footer shows one arrow. In Arabic dark mode the moon of the
  light/dark switch stays inside the switch.
- **Appearance › Themes** and the gallery show new preview pictures of the 12 looks and the 32 themes, made on a clean
  demo site.
- The descriptions of the default look and the default theme no longer name the developers' test site.

### Translations

- 17 new or changed texts are translated into every language. The phone bar labels "Profile" and "Random" are
  corrected in Korean, Chinese, Arabic and Thai, and "Random" starts with a capital letter where it should.

### For developers

- New scheduled command `updates:tidy` (every 5 minutes, only while update files wait to be deleted) and the new folders
  `storage/app/updates/.trash` and `storage/app/updates/logs`.
- A site built by running every migration gets example ad codes with the class `avd-demo-ad`; existing codes with
  `avd-test-ad` keep their styles.

### Not changed

- The Upload18 upload, the video player and the embed code work exactly as before.

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
