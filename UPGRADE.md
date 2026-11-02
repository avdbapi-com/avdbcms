# Update AVDBCMS

This guide moves a running AVDBCMS site to a newer release. Your videos, members, settings, look and uploads stay.

You can update in two ways:

- **With one click** on **Tools › Updates** in the admin. This exists from AVDBCMS 1.2.0 on.
- **By hand:** you copy the update package over your site with the File Manager of your hosting panel or with FTP,
  then you finish the update on the admin **Home** (**Home › Finish update**).

A site that runs a community test package from before 1.2 (October 2026) has no one-click update. It moves to 1.2.0
once by hand: see [Move a site from a community test package to 1.2.0](#move-a-site-from-a-community-test-package-to-120).
After that, it updates with one click.

What each release changes: [CHANGELOG.md](CHANGELOG.md). Every release with its files:
https://github.com/avdbapi-com/avdbcms/releases

## Before you start

- **Read what is new.** The [changelog](CHANGELOG.md) lists what the release changes.
- **Choose a quiet time.** Wait until running video imports have finished. The one-click update does not start while
  an import is running.
- **Sign in first.** Sign in at `https://your-domain/login`, then open `https://your-domain/admin`. While you are
  signed out, `/admin` only shows "not found".
- **Check the free disk space.** An update needs room for the package, its unpacked files and a database backup.
  **Tools › Health check**, tab **Server**, box **Disk Usage** shows how much is free.

## Update with one click (from 1.2.0 on)

Nothing updates by itself. AVDBCMS tells you that a new release is out, and you start the update.

### Step 1. See what is new

- When GitHub has a newer release, **Home** shows "AVDBCMS X.Y.Z is available" with the button **See the update**.
- **Tools › Updates** (`/admin/updates`) shows the version your site runs, when it was last updated, when AVDBCMS last
  asked GitHub, and the button **Check now**.
- AVDBCMS asks GitHub at most every 12 hours, and when you click **Check now** (at most once a minute). The check sends
  nothing about your site. Only published releases count: drafts and test builds are never offered.
- **What's new** opens the changelog of the release on the help site.

### Step 2. Start the update

1. On **Tools › Updates**, click **Update to X.Y.Z**.
2. The dialog says what will happen. Type your admin password and confirm.
   A wrong password shows "That password is not right." After 5 wrong tries in 10 minutes, the button waits for
   10 minutes.
3. Keep the page open. A progress bar shows each step, and **Show details** lists them.

The box **Skip the database backup** shows only when **Tools › Backups** already has a backup that is less than
24 hours old (the dialog names it). Without that box, the update always makes its own database backup.

### What the update does

1. **It checks first**, before it changes anything: no other update is running, no video import is running, no
   **Finish update** from an earlier upload is waiting, your PHP version is new enough for the release, AVDBCMS can
   write to the folders it replaces, PHP's file cache (OPcache) can be refreshed, and the disk has enough free space.
   When a check fails, the page says why, and nothing on your site has changed.
2. **It downloads** `avdbcms-update-X.Y.Z.zip` from the GitHub release, in small parts. A broken download continues
   where it stopped.
3. **It checks the SHA-256 checksum** of the download against the `.sha256` file of the release and against the
   checksum that GitHub reports. A damaged download is deleted: "The download is damaged (its SHA-256 does not
   match). Nothing on your site was changed. Try again."
4. **It unpacks** the package into a work folder in `storage/app/updates/` and checks every file. It uses PHP's zip
   extension, or PHP's built-in PharData when the zip extension is missing.
5. **It backs up the database.** The file `backup_before_update_X.Y.Z_<date>_<time>_<random part>.sql.gz` appears on
   **Tools › Backups** like any other backup.
6. **It replaces the changed files.** For these few seconds, visitors see "This site is being updated. Please come back
   in a minute." Each old file is moved into the update backup in `storage/app/updates/backups/` before the new file
   takes its place. Then the update clears the caches of your site and, where the server allows it, PHP's saved copies
   of the old files (OPcache). The old CSS and JavaScript files of the version before (in `public/build/assets/`) stay
   until the next update, at most 14 days, so a page that is still open in a browser keeps working. Then the clean-up
   moves them into the update backup.
7. **It finishes the update:** it makes the database changes of the new release, the same as **Home › Finish update**,
   gives your language files the texts the release corrected (see [Step 3](#step-3-read-the-result)), and shows
   "AVDBCMS X.Y.Z is installed." Each request of this step works for at most about 20 seconds and stops in time before
   your server's time limit; the page then sends the next one by itself, so a server with a short limit (30 seconds,
   for example) finishes in a few requests. If the server still ends a request half-way, the page sends it again and the
   update goes on where it stopped, at once. Only when your database was still working on a change of the stopped
   request does the page wait until that change is done (it says "The update is already being finished" meanwhile).

If a step before the database changes fails, the old files go back by themselves, and the page says: "The update could
not be installed. Your site was put back as it was. Nothing in the database was changed."

If the database changes fail, your site keeps working and the page shows the reason. Try again with **Finish update**
on **Home**, or ask your host to run `php artisan migrate`.

The update never switches the **Maintenance mode** setting of your site: if you turned it on, it stays on.

### Step 3. Read the result

The result lists the files that need your attention:

- **Server files you changed**, such as `.htaccess`, `web.config`, `nginx.conf` or `public/robots.txt`: the update
  keeps your file and saves the new one next to it, with the new version added to its name
  (`.htaccess.avdbcms-X.Y.Z`). Compare the two files and copy what you need into yours. (Since 1.2.3 the site writes
  `public/robots.txt` itself from **Settings › Google & sharing**. Lines of your own belong in **Custom robots.txt** on
  that page, shown with Developer mode on: when you change Custom robots.txt, Sitemap or Hide the whole site from
  Google, the site first keeps a file you edited as `robots.txt.owner-<date>-<time>` next to it, then writes its own.)
- **Translations you changed** on **Settings › Languages**: your texts stay, and the new texts of the release are
  added. A text you never changed (it is still exactly as an older version had it) gets the corrected wording of the
  new version. The result lists each language file with how many texts were corrected and how many of your own texts
  were kept; **Tools › Logs**, tab **Admin actions**, has one `update.texts` row per language. A changed file in a
  language folder (`lang/<language>/*.php`) is kept like a server file, with the new copy next to it.
- **Program files you changed**, for example a view in `resources/views/`: the update replaces them with the new
  version. The old copies stay in the update backup.

Reload the admin in your browser with Ctrl+F5 (Cmd+Shift+R on a Mac). If your site is behind Cloudflare or another CDN
that keeps copies of your pages, purge its cache once.

### Who can update

- Every admin sees **Tools › Updates** and can look for a new release.
- Starting, continuing, cancelling and undoing an update also need the admin tools of the **Tools** section. They are
  on after a normal install (`ADMIN_OPERATIONAL_TOOLS_ENABLED=true` in `.env`). When they are off, the page shows the
  manual way instead.
- Only one update runs at a time. Another admin sees "An update to X.Y.Z is being installed by …", with the name of
  the admin who started it and the time, and **Show progress**.
- An update that made no progress for 30 minutes counts as stalled. An admin can then click **Continue** or
  **Cancel the update**. Cancelling works only before the files are replaced: it deletes the downloaded files, and a
  database backup that the update already made stays on **Tools › Backups**. After that, only finishing or undoing
  the update is possible.

### When the one-click update cannot run

| The page says | What to do |
|---|---|
| "An import is running. Wait until it ends or stop it, then try again." | Wait until the import has ended, then click **Update to X.Y.Z** again. |
| "An earlier update still waits for its database step. Click Finish update on Home first, then try again." | Click **Finish update** on **Home**, then start the update again. |
| "Not enough disk space: the update needs about … free." | Delete what you no longer need (old backups, old zips), or ask your host for more space. |
| "AVDBCMS X.Y.Z needs PHP … or newer, and this server runs PHP …" | Choose a newer PHP version for your site in your hosting panel, or ask your host. |
| "AVDBCMS cannot write to … Ask your host to give PHP write access to the site files, then try again." | Click **Auto-fix** on **Tools › Health check**, or give your site folder back to the user that PHP runs as. |
| "This server keeps old PHP files in memory and does not let AVDBCMS clear them. Ask your host to restart PHP after a manual update." | [Update by hand](#update-by-hand), then ask your host to restart PHP. |
| "This server cannot open zip files. Upload the update zip with your file manager, then click Finish update on Home." | [Update by hand](#update-by-hand). |
| "This server cannot check secure connections to GitHub. Ask your host to update the CA certificates." | Ask your host, or [update by hand](#update-by-hand). |
| "GitHub has had too many requests from this server. Try again after …" | Wait until that time. GitHub answers 60 checks per hour from one server address, and every site on that address shares them. |
| "This site has no list of its AVDBCMS files (avdbcms-manifest.json), so it cannot be updated from here. …" | Your site runs a community test package (or that file was deleted): [move it to 1.2.0 by hand](#move-a-site-from-a-community-test-package-to-120), or [update by hand](#update-by-hand). |
| "Operational tools are turned off on this site, so updates cannot be installed from here. …" | [Update by hand](#update-by-hand), or see [Who can update](#who-can-update). |

### If the update stopped half-way

The update writes down every file it moves, so it can finish or undo a run that was cut off, for example when the
browser was closed or the host stopped a request.

If your site still shows "This site is being updated" after 15 minutes:

1. Open `https://your-domain/admin` in the same browser that started the update. A small page offers
   **Finish the update** and **Undo the update**.
2. Without that browser: with the File Manager of your hosting panel, delete `storage/framework/maintenance.php` and
   `storage/framework/down`. Then copy the folder `files/` of the newest update backup in
   `storage/app/updates/backups/` back over your site folder, delete the files that its `backup.json` lists as added,
   and delete the `.php` files in `bootstrap/cache/`.

## Update by hand

Use this way:

- to move a site from a community test package to 1.2.0 (read also
  [the section about it](#move-a-site-from-a-community-test-package-to-120)),
- when the admin tools are off or the one-click update cannot run on your server,
- or when you prefer to copy the files yourself.

### Step 1. Back up your site

Do not skip this. If something goes wrong, the backup brings your site back.

**Database:**

1. Open **Tools › Backups** (`/admin/backups`).
2. Click **Back up now**. When it is done, a message starts with "Database backup created", and the new backup is at
   the top of the list.
3. Open the menu of the new backup, click **Download** and keep the file on your computer.

On a big site, if the backup does not finish, export the database with phpMyAdmin or the backup tool of your hosting
panel instead.

**Files:** your site folder is the folder that holds the file `artisan` and the folders `app`, `public` and `storage`,
for example `public_html`. In the File Manager, compress the whole folder into a zip and download it. If that is too
big, leave out `storage` and `bin`: an update does not replace your files there. The zip holds your `.env` with your
database password: keep it private, and delete it from the server after the download.

### Step 2. Save what you changed

Copying the package replaces every file that is in it, also files that you changed. Save these first:

- **Translations** you changed on **Settings › Languages**: open the menu of each of these languages and click
  **Download the JSON file**.
- **Server files** that you or your host changed: `.htaccess`, `web.config`, `favicon.ico`, `manifest.json` and
  `404.html` (in your site folder and in `public/`), `public/robots.txt`, `nginx.conf`, `nginx-aapanel-rewrite.conf`
  and `php-cli.ini`. Keep a copy.
- **Program files** you edited, for example a view in `resources/views/`. Keep a copy, and compare it with the new file
  before you use it again.

### Step 3. Optional: turn on maintenance mode

While the files are copied, your site runs on a mix of old and new files. Maintenance mode shows visitors a "back soon"
page meanwhile.

Open **Settings › Maintenance mode**, switch **Maintenance mode** on, click **Save changes** and confirm with
**Turn on maintenance mode**. With **Admins can still browse the site** on (the default), you still see the normal site
while you are signed in.

### Step 4. Download and check the update package

1. Open https://github.com/avdbapi-com/avdbcms/releases/latest. This address always opens the newest release.
2. Under **Assets**, download `avdbcms-update-X.Y.Z.zip` and its checksum file `avdbcms-update-X.Y.Z.zip.sha256`.
   Do not use the "Source code" downloads that GitHub adds to every release: they hold the source code only, without
   `vendor/` and `public/build/`.
3. In the folder with the zip, compute its checksum. The result must be exactly the value in the `.sha256` file.

   | Computer | Command |
   |---|---|
   | Windows (Command Prompt) | `certutil -hashfile avdbcms-update-X.Y.Z.zip SHA256` |
   | Mac (Terminal) | `shasum -a 256 avdbcms-update-X.Y.Z.zip` |
   | Linux | `sha256sum -c avdbcms-update-X.Y.Z.zip.sha256` (prints `OK` when the file is complete) |

   If the values differ, download the zip again. Never upload a file whose checksum does not match.

The full package `avdbcms-X.Y.Z.zip` works for an update too, but it is much bigger: it also holds the FFmpeg programs
in `bin/`, which your site already has.

### Step 5. Copy the new files over the old ones

Copy the files into your site folder, the one with `artisan`. Do this also when your domain points to the `public`
folder inside it.

> **Never delete your site folder first.** Copy the new files over the old ones. If you start from an empty folder, you
> lose `.env`, your uploads and the install lock, and your site may show the installer instead. For the same reason,
> never use a "synchronize" or "mirror" option of an FTP program that deletes files on the server.

**With the File Manager:** upload the zip into your site folder, click **Extract** (some panels say **Unzip**) and
extract it into the same folder. When the panel asks, overwrite the existing files. The zip has no folder around the
files, so every file lands in its place. Then delete the zip from the server.

**With FTP or SFTP:** extract the zip on your computer into a new, empty folder. Turn on hidden files in your FTP
program, so that files whose name starts with a dot, such as `.htaccess`, are copied too. Upload everything into your
site folder and overwrite the existing files. The package has thousands of files: if the transfer stops or lists
failed files, upload again until nothing is left. Half-copied folders, above all `public/build` and `resources/views`,
leave pages without styles or with buttons that do nothing.

Then put your own changes back:

- add your own rules again to the new `.htaccess`, `public/.htaccess` or `web.config` (do not copy the old file back);
- copy back the other files of step 2 that you had made your own, such as your `favicon.ico` or `public/robots.txt`;
- on OpenLiteSpeed, restart the web server in your hosting panel: it keeps the old `.htaccess` rules until it restarts.

### Step 6. Finish the update

Sign in at `/login`, then open the admin at `/admin`. Its first page is **Home**.

If the new release changes the database, **Home** shows the box **Finish the update**: "A new version is uploaded.
1 database change is waiting - your site keeps working while you do this." (or the number of changes). Click
**Finish update**. The button says "Finishing the update..." while it works.

| Message | What it means |
|---|---|
| "The update is finished." | Done. Go on with step 7. |
| "Some of the database changes are done. Click Finish update again for the rest." | Your server gives a click only a short time, so the rest waits for the next one. Click **Finish update** again until it says "The update is finished." |
| "Your site is up to date." | No database change was waiting. |
| "The update is already being finished. Wait a minute, then reload this page." | An earlier click is still running, or your database is still working on a change that a click your server stopped had begun. Wait a minute, then reload **Home**. |
| "The update could not finish: … Your site keeps working. Try again, or ask your host to run "php artisan migrate"." | Click **Finish update** again. If it fails again, use the commands below or ask your host, and keep the error text for support. |
| "… Your language files were not checked this time, because another update step was running. Try again in a minute on Tools > Updates." | The database step is done. Wait a minute, then click **Correct the texts** on **Tools › Updates** (see below). |
| "… These language files could not be saved, so their texts stay as they were: lang/…json." | PHP cannot write that file. Click **Auto-fix** on **Tools › Health check**, or give the file back to the user that PHP runs as, then click **Correct the texts** on **Tools › Updates**. |

Clicking **Finish update** twice is safe: the second time there is nothing left to do.

**Finish update** also gives your language files the texts the new version corrected: a text that is still exactly as
an older version had it gets the new wording, a text the new version no longer uses goes while it is still exactly as an
older version had it, and your own texts stay. The message says how many per language. Did you
save changed translations in step 2, and does **Home** show the box? Then import them first (see step 7), and click
**Finish update** after that, so their old texts get the corrected wording too.

No box on **Home**? Then the release has no database change. Open **Tools › Updates** once: when a language file you
changed still has texts as an older version wrote them, the page shows "Your language files have texts to correct"
with each file and its number of texts. Click **Correct the texts**. Your own texts stay. Did you import saved
translations later (step 7)? Then the box shows again: click **Correct the texts** once more.

With SSH you can do the same on the command line, as the user of your site (the user of your cron job, not root):

```
cd /path/to/your/site
php artisan migrate --force
php artisan updates:texts
php artisan optimize:clear
```

The first command makes the database changes. The second gives your language files the texts the new version
corrected and removes the ones it no longer uses, as **Finish update** does, and lists them per language (run it again
after you import saved translations).
The third clears the caches of your site. `migrate` and `optimize:clear` alone never change your language files.

### Step 7. Clear the caches

1. Open **Tools › Health check**, tab **Server**, and click **Clear All**. You see "All caches cleared." Do this also
   when there was nothing to finish: **Finish update** clears the caches only when it made database changes.
2. Reload the page with Ctrl+F5 (Cmd+Shift+R on a Mac), so your browser loads the new styles and scripts.
3. If your site is behind Cloudflare or another CDN, purge its cache once.
4. PHP can keep program files in memory (OPcache; the **Server** tab shows whether it is on). If your pages still show
   the old version after a few minutes, restart PHP in your hosting panel or ask your host. **Clear All** does not reset
   OPcache.

Did you download changed translations in step 2? Type your changes again on **Settings › Languages** with
**Edit translations**, or import your file in the box **Import translations**. An import replaces the whole language
file, so the texts that are new in this release are then not translated in that language. After an import, open
**Tools › Updates** and click **Correct the texts**: the texts of your file that you never changed get the corrected
wording of the new version.

### Step 8. Check your site

- your home page, a video page (the video plays) and a search,
- your site on a phone,
- `https://your-domain/sitemap.xml` and `https://your-domain/robots.txt` (since 1.2.3 the site writes `robots.txt` from
  **Settings › Google & sharing**: after an update from 1.2.2 or older, open that page once; `robots.txt` then ends
  with the whole address of your sitemap),
- the admin **Home**: no box **Finish the update** any more,
- **Tools › Health check**, tab **Checks**: **Blocking issues** shows 0. If a folder is "NOT writable", click
  **Auto-fix**.

If you turned on maintenance mode in step 3, turn it off now: **Settings › Maintenance mode**, switch it off, then click
**Save changes**.

## What an update keeps and what it changes

| Part of your site | One-click update | Update by hand |
|---|---|---|
| `.env` (database password and keys) and `.user.ini` | Kept: never replaced or rewritten. Only the update to 1.2.3 takes away the right of other hosting accounts on the server to read `.env` (where your site's PHP user owns the file) | Not in the package, so not replaced. **Finish update** of 1.2.3 does the same |
| `storage/`: uploads, logs, database backups, members exports, invoices and the install lock `storage/app/installed.json` | Kept: nothing of yours is deleted. The update adds its work folder `storage/app/updates/` and one database backup. The update to 1.2.3 also gives the private files names nobody can guess, once: see below | Kept: in the package, this folder holds only empty placeholder files. **Finish update** of 1.2.3 renames the same files |
| `public/uploads/`, `public/thumbnails/`, `public/avatars/` and the link `public/storage` | Never touched | Not in the package |
| The starter data of new installs, `database/install/example.sql` | Replaced by the copy of the new release only when yours is still exactly a copy an older release shipped; the copy it replaces stays in the update backup, so **Undo** brings it back. A copy you changed stays | Replaced: it is in the package |
| Files the new release no longer has | Deleted in the program folders (`app/`, `config/`, `database/migrations/`, `database/schema/`, `resources/`, `routes/`, `vendor/`, `public/build/`); the old copies stay in the update backup. Old built CSS and JavaScript stay until the next update, at most 14 days, then they move into the update backup. When the update to 1.2.3 finishes, it also removes what 1.2.0 to 1.2.2 shipped outside these folders and 1.2.3 no longer has: the old database dump `database/schema/mysql-schema.sql`, the development Docker files `docker-compose.yml` and `docker/`, and 20 unused pictures of the old sign-in and error pages (`images/errors/` and `public/images/errors/`, and `forgot.png`, `login.png`, `register.png`, `reset.jpg` and `subscriptions.png` in `images/pages/` and `public/images/pages/`). Each one goes only while it is exactly as a release shipped it (a copy you changed stays), into the update backup, so **Undo** brings it back. **Tools › Logs › Admin actions** says what went. Anything else stays: a page of your own may still show it | Kept: copying never deletes files. Since 1.2.3, **Finish update** removes the same old database dump, Docker files and 20 pictures, each only while it is exactly as a release shipped it |
| `bin/` (the FFmpeg programs) | Never touched: the update package has no `bin/` | Kept: the update package has no `bin/` |
| Your own files that are not in the package (for example `.well-known/`) | Never touched | Kept: copying never deletes files |
| The database: videos, members, settings and your look | Kept. The database changes of the new release are added | Kept. **Finish update** adds the changes of the new release |
| Server files you changed: `.htaccess`, `web.config`, `favicon.ico`, `manifest.json` and `404.html` (in your site folder and in `public/`), `public/robots.txt`, `nginx.conf`, `nginx-aapanel-rewrite.conf`, `php-cli.ini` | Kept; the new file is saved next to yours as `<name>.avdbcms-X.Y.Z` | Replaced: save your copy first (step 2) |
| Translations you changed in `lang/` | Kept; the new texts are added, and texts you never changed get the corrected wording (a changed `lang/<language>/*.php` file is kept, with the new copy next to it) | Replaced: download them first (step 2) |
| Program files you changed (in `app/`, `resources/` and the other program folders) | Replaced; the old copies stay in the update backup, and the result lists them | Replaced: keep a copy first (step 2) |

What the update to 1.2.3 renames in `storage/` (once, with one click or with **Finish update**; your files keep their
content, and **Tools › Logs** and **Tools › Backups** show them under their new names):

- the logs `laravel.log` (and its daily files), `mail.log`, `stripe.log` and `paypal.log` become `laravel-….log`,
  `mail-….log`, `stripe-….log` and `paypal-….log` (a part of each name comes from your site's key, `APP_KEY`);
- database backups and members exports get a random part in their names;
- invoice PDFs move from `storage/app/private/invoices/` into a folder named from your site's key;
- the install lock `storage/app/installed.json` no longer keeps the administrator's e-mail address;
- copies of `.env` get a random part in their names: the installer's copy in `storage/app/installer-backups/`, the
  copies the payment-key page kept in `storage/app/env-backups/`, and the `.env.backup.<date>` copies next to `.env`,
  which move into `storage/app/env-backups/` (the newest 5 are kept);
- the cache folders of 1.2.2 and older (`storage/framework/cache/data/`) move into a folder with a random name, which
  the clean-up deletes a few seconds at a time afterwards: **Tools › Updates** while it is open, and the cron job every
  five minutes. A busy site may have tens of thousands of old cache files there. An undo right after the update does
  not wait for them (see below).

Undo back to 1.2.2 or older puts the invoice PDFs back where that version reads them; everything else keeps its new
name, and the older version works with it.

## Undo an update

### After a one-click update

On **Tools › Updates**, click **Undo this update** and confirm with your admin password. This works for the newest
update, as long as its backup is kept. The dialog says: "Your files go back to X. The database stays as it is now. If
the old version shows errors, restore the database backup `backup_before_update_...sql.gz` with your hosting panel's
database tool." It names the file exactly as **Tools › Backups** lists it.

- Your files go back to the version before, and files that the update added are deleted. Your language files go back
  exactly as you had them before the update. So do the files the update removed or replaced when it finished: after
  the update to 1.2.3, the old database dump `database/schema/mysql-schema.sql`, `docker-compose.yml` and `docker/`, the
  unused sign-in and error pictures, the starter data of new installs `database/install/example.sql`, and the old CSS
  and JavaScript the clean-up moved into the update backup after 14 days. The Undo dialog says so too.
- A file that changed again after the update is left alone, and the page lists it.
- Right after an update, its work files (and, after the update to 1.2.3, the old cache files) may still wait for the
  clean-up. An undo back to 1.2.1 or later does not wait for them: that version deletes them afterwards, the same way.
  An undo back to 1.2.0 deletes them first, because 1.2.0 has no such clean-up; with many old files on a slow disk,
  that can take a few minutes.
- Going back to a version older than 1.2.2: the three development files that 1.2.2 removed for safety
  (`database/seeders/UserSeeder.php`, `ModelSeeder.php` and `FleetDataEnricherSeeder.php`) stay removed. Your site does
  not need them to run. The Undo dialog says so too.
- Going back to 1.2.2 or older: a member's upload that Upload18 is still processing at that moment stays an unfinished
  draft, because those versions cannot finish an upload whose page was closed. The member can delete it and upload the
  video again. If you update again, the site finishes it by itself.
- The database stays as it is. If you need the database of before the update, import the backup that the update made
  with the database tool of your hosting panel, for example phpMyAdmin. **Tools › Backups** has no restore button.

AVDBCMS keeps the backups of the two newest updates. **Tools › Updates** lists them with their size and date, and
**Delete** removes one.

Without the admin, you can undo by hand: copy the folder `files/` of the update backup in
`storage/app/updates/backups/<old version>-to-<new version>-<time>/` back over your site folder, delete the files that
its `backup.json` lists as added, and delete the `.php` files in `bootstrap/cache/`.

### After an update by hand

1. Copy the files of your backup zip from step 1 back over your site folder, the same way as in step 5.
2. Open **Tools › Health check**, tab **Server**, and click **Clear All**. If the admin does not open, delete the `.php`
   files in `bootstrap/cache/` and the files in `storage/framework/views/` with your File Manager.
3. If you clicked **Finish update** and the old version now shows errors, import the database backup from step 1 with
   the database tool of your hosting panel, for example phpMyAdmin.

Copying back does not delete files that only the newer release has. If **Home** then shows **Finish the update** again,
do not click it: ask for help first.

## Move a site from a community test package to 1.2.0

From 12 to 23 October 2026, AVDBCMS was tested in community test packages. They have no version number and no
one-click update. A site that runs one moves to 1.2.0 once, by hand:

1. Follow [Update by hand](#update-by-hand) with the 1.2.0 update package `avdbcms-update-1.2.0.zip` and its checksum
   file `avdbcms-update-1.2.0.zip.sha256`.
   The admin of a test package from before 22 October 2026 has other names: there, the database backup is at
   `https://your-domain/admin/backups` (old menu **System › Backups**, button **Create Database Backup**, then the
   download button of the new backup under **Actions**), and each language has an **Export JSON** button at
   `https://your-domain/admin/languages`. Skip the optional maintenance mode step there.
2. Open the admin **Home**. When it shows the box **Finish the update**, click **Finish update**. On a site that ran a
   test package of 17 October 2026 or older, these database changes wait there: a table for the settings history, the
   move of your appearance settings to the new theme collection, and a column for admin preferences. The box then also
   says "Your look moves to the new theme collection; you can restore the old one for 30 days."
3. On such a site, your look moves to the 32 new themes. A ready-made theme becomes its closest new theme (dark stays
   dark, light stays light). Colours you set by hand, logos, footers and your own CSS and JavaScript code stay. Old
   theme names keep working.
4. **Home** then shows "Your look moved to the new theme collection" with three buttons:
   - **See changes** shows the history of your look;
   - **Restore my previous look** brings back the look your site had before. It works for 30 days;
   - **Keep the new look** hides the box.
5. Clear the caches and check your site (steps 7 and 8 of [Update by hand](#update-by-hand)).

The admin of 1.2.0 has 8 sections with new names. **What moved where**, at the bottom of the admin menu, shows where
each old page is now, and old admin addresses keep working. For now, the old admin is still one click away:
account menu › **Use the old admin for now**.

From now on, **Tools › Updates** offers the next releases with one click.

### If you edited theme view files by hand

The new themes replaced these 8 view files of the test packages. They are not in 1.2.0:

- `resources/views/pages/partials/hero-bento.blade.php`
- `resources/views/pages/partials/hero-slider.blade.php`
- `resources/views/pages/partials/hero-ticker.blade.php`
- `resources/views/pages/partials/layout-bento-mosaic.blade.php`
- `resources/views/pages/partials/layout-cinema-carousel.blade.php`
- `resources/views/pages/partials/layout-cyberpunk-hud.blade.php`
- `resources/views/pages/partials/layout-tiktok-shorts.blade.php`
- `resources/views/pages/partials/layout-youtube-spotlight.blade.php`

The homepage hero now comes from `resources/views/pages/partials/hero.blade.php` and the files in
`resources/views/pages/partials/hero/`. Copying the update package does not delete the old files from your server, but
1.2.0 does not use them. Compare your changes with the new files before you add them again.

## Nginx rules you copied from the package

An update changes the files of the package, never a copy you made of one of its Nginx files (in your panel's URL
rewrite box or vhost, in `/etc/nginx/`, in HestiaCP's `nginx.conf_avdbcms`). Rules that AVDBCMS wrote itself into
aaPanel's URL rewrite box are written again by the site. Compare your copy with the new file once after the update to
1.2.3:

- `docs/server-configs/nginx-project-root.conf` and `nginx-aapanel-rewrite.conf` also refuse the package's documents
  and its list of files: `UPGRADE.md`, `CHANGELOG.md`, `LICENSE`, `NOTICE`, `avdbcms-manifest.json` and `docs/` (the
  list gives the exact version of your site and every file's checksum). Copy the new file again, with `__PROJECT_ROOT__`
  replaced by your project folder.
- **CloudPanel**: the first `server { }` block of the vhost gets the new `docs/server-configs/nginx-cloudpanel-front.conf`,
  never `nginx-project-root.conf` (its 1.2.3 rules there make every admin page and every video stored on your server
  show the home page). **HestiaCP**: the new `docs/server-configs/nginx-hestia-project-root.conf` replaces
  `nginx-project-root.conf` as `nginx.conf_avdbcms` (Nginx refuses the old file there). The steps:
  [COMMUNITY_SETUP_GUIDE.md](COMMUNITY_SETUP_GUIDE.md), Panel 4 and Panel 5.

## If something goes wrong

| What you see | What to do |
|---|---|
| A blank page or error 500 right after the upload | The disk of your hosting account may be full. Delete the uploaded zip and old copies of your site, or ask your host for more space, then reload. If the copy stopped half-way, copy the files again. |
| Pages without styles, or buttons that do nothing | Upload the folders `public/build` and `resources/views` again completely, purge your CDN cache and press Ctrl+F5. |
| The installer opens instead of your site | `.env` is missing: copy it back from your backup. |
| "This site is being updated" for more than 15 minutes | See [If the update stopped half-way](#if-the-update-stopped-half-way). |
| Pages still show the old version | Restart PHP in your hosting panel (OPcache), or ask your host. |
| A folder is "NOT writable" on **Tools › Health check** | Click **Auto-fix**. A File Manager can extract files as another user (on aaPanel as root): give your site folder back to the user that PHP runs as, for example `www` on aaPanel. |

## Get help

- Guides on the help site:
  [Update with one click from the admin](https://help.avdbapi.com/documentation/avdbcms-one-click-update/),
  [Upgrade by hand (FTP or File Manager)](https://help.avdbapi.com/documentation/avdbcms-upgrade/) and
  [Backups and restore](https://help.avdbapi.com/documentation/avdbcms-backups/).
- Telegram group: https://t.me/+jj6McO-iYfA0MTM1 (tell us what you see and at which step you are)
- Telegram support (personal): https://t.me/avdbapi_support
- Bugs: https://github.com/avdbapi-com/avdbcms/issues
