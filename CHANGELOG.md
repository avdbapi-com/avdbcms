# Changelog

All notable changes to AVDBCMS. From 1.2.0 on, the version numbers follow semantic versioning: a release with fixes
only raises the last number, a release with new features the middle one.
How to update your site: [UPGRADE.md](UPGRADE.md). Every release with its files:
https://github.com/avdbapi-com/avdbcms/releases

## 1.2.3 (2026-11-02)

The final release of the 1.2 series, and a big one: new member pages, uploads that are no longer lost when the page is
closed, an admin where every setting can be found, imports that skip videos whose titles, tags or categories use words
that present a child or a school-age minor, the fixes of a full security review and many other fixes. Sites on 1.2.0,
1.2.1 or 1.2.2 update with one click in **Tools › Updates**; every other way is in [UPGRADE.md](UPGRADE.md). It has 21
database changes, which the update makes for you (**Finish update** on Home when you update by hand). Before you update,
check that the **Time zone** in **Settings › Site basics** is yours: the times of older views, subscriptions, saved
playlists, likes and dislikes and the dates videos were added to playlists are put in the time zone your site has when
you update.

After the update, please check these points:

- **Upload18 key:** open **Settings › Videos & uploads**, card **Upload18 connection** (under Advanced). A site that
  still had the key of 1.2.0 to 1.2.2 now has the new shared AVDBCMS key, so its uploads work again. Better: create your
  own restricted key, as the card explains. If the card says "Check your Upload18 key", replace the key.
- **Blocked addresses:** every address and range in **Members › Blocked addresses** is now really blocked. Keep only the
  addresses you want to block.
- **Hidden videos:** the update bans the videos your site already has whose titles, tags or categories use words that
  are always blocked (words that present a child or a school-age minor), so visitors no longer see them. If Home shows
  "... videos were hidden because their titles, tags or categories use blocked words" under **Needs you**, click
  **Review** and **Unban** any video that was hidden by mistake. Tags and categories whose names use these words are
  hidden from visitors too: **Content › Categories** and **Content › Tags** mark them.
- **Imports:** if you import from AVDBAPI or XVIDAPI, open the new **Blocked words** card on
  **Content › Import videos**. New imports skip every video whose title, tags or categories use one of its words; the
  list is yours to change.
- **Cron job:** it must run every minute. It finishes the uploads whose page was closed and does the daily tidy-ups.
  After the update, it also puts the times of older views, subscriptions, saved playlists, likes and dislikes and the
  dates videos were added to playlists in your site's time zone and, on a big site, finishes the recount of likes and
  comments and the hiding of videos with blocked words, builds the new notification indexes and, on a site with more
  than 200,000 videos, builds the video indexes of 1.2.3. When it does not run, Home shows "Scheduled tasks are not
  running" under **Needs you**, or "Scheduled tasks run only while people visit the site" when only the page views of
  your site start them; **Tools › Background jobs › Scheduled tasks** then shows the line to add. A cron line that your
  site added itself is updated by itself.
- **Time zone:** the cron job and the background jobs now work with your settings, like the pages do. Scheduled imports
  and the daily tasks run at their times in the **Time zone** of **Settings › Site basics** (before, the cron used Paris
  time), so check that it is yours, best before you update (see above). Their e-mails go out through the mail server and
  with the sender of **Settings › Email**.
- **Private files:** if Home or **Tools › Health check** shows "Private files of your site can be downloaded" in red,
  change your server as the message says.
- **robots.txt:** open **Settings › Google & sharing** once. The site then writes its robots.txt with your settings and
  the full address of your sitemap. A robots.txt you edited yourself is kept until you change one of its settings.
- **nginx:** the update does not change your server's nginx rules. Put the rules of 1.2.3 in place of the ones you use;
  the setup guide, COMMUNITY_SETUP_GUIDE.md ("The Universal Solution" and your panel's steps), says which file goes
  where and what to write in it: `docs/server-configs/nginx-project-root.conf` when the site folder is the web root,
  `nginx-aapanel-rewrite.conf` in aaPanel's URL rewrite with the Running directory /public, the new `location` blocks of
  `docs/server-configs/nginx-public-root.conf` in your own server block, and on CloudPanel and HestiaCP their own files
  `nginx-cloudpanel-front.conf` and `nginx-hestia-project-root.conf`; on Plesk no nginx file. Write your site's folder
  where the file has `__PROJECT_ROOT__` or an example folder, and run `nginx -t` before you reload nginx. These rules
  keep scripts from running in the upload folders, never hand out database dumps or logs, and let nginx send the videos
  stored on your server. On aaPanel, the site updates the rules it wrote itself when PHP may write them.
- **Apache and LiteSpeed:** the `.htaccess` files of 1.2.3 keep scripts from running in the upload folders and never
  hand out database dumps or logs. If you changed one of them (some panels do), the one-click update kept yours and put
  the new one next to it as `.htaccess.avdbcms-1.2.3`, listed in **Tools › Updates**: take the new rules into your file.
  After an update by hand, add your own rules to the new file again instead of copying your old file back, as UPGRADE.md
  says. On OpenLiteSpeed (for example CyberPanel), restart it after the update so that it reads them.
- **Stripe:** if members pay by card, fill in the **Webhook signing secret** in **Money › Payment setup**. Without it,
  your site now refuses Stripe's messages.
- **Premium sales:** if you sell Premium, open **Money › Earnings**. It now says whether members can buy Premium and,
  when they cannot, what is missing (for example PayPal's **Webhook ID**, or a plan without the price ID of a connected
  service), with a link to it.
- **Invoices:** if members pay, fill in the **Invoices** card in **Money › Payment setup**. Invoices made by an older
  version printed another company: they are made again with your details when a member downloads them (with only your
  site's name while the card is empty).
- **A CDN other than Cloudflare:** list the addresses of that CDN (or of your load balancer) in SITE_TRUSTED_PROXIES in
  `.env`. Otherwise your site sees the CDN's address instead of your visitors' addresses.
- **Tools › Logs › Admin actions** shows what the update changed on your site, for example the Upload18 key, the
  homepage list ad, the Telegram links, the public videos that were never published, the sign-up limit, the Premium page
  texts, the recount of likes and comments, the older times put in your time zone and the videos hidden because of
  blocked words.

### New: member pages

The pages that members use have a new design. It takes its colours and fonts from your look, in light and dark, on
phones and in right-to-left languages.

- **Studio:** a new Dashboard (figures for the period you choose, the latest videos, comments and subscribers, "Needs
  your attention" and tips) and Analytics with a chart and **See who rated it**.
- **Studio › My videos:** status tabs with counts, search, filters, a menu on each row, and bulk visibility and delete.
- **Studio › Video details** and the draft page: visibility as cards, scheduling in your site's time zone, a preview,
  and a short message after saving, pinning and unpinning.
- **Studio:** Playlists with their editor, Comments with a replies panel, Subscribers with **Subscribe back**, Activity
  with filters, Reports you sent and Invoices.
- **Account settings:** one page per section (Profile, Channel, Sign-in and security, Notifications, Premium, Delete
  account), each with its own save bar. A save returns to its section, and errors show under their fields.
- **Profile:** no country is chosen for the member, country names are in the member's language, and the Language list
  shows the language the member is reading. The avatar crop is round (400 x 400) and the channel banner is 16:5
  (2048 x 640). Cancel really cancels.
- **Sign-in and security:** a new password signs out the member's other devices (**Sign out of all other devices** is
  ticked by default). The device list has **Show all** and **Sign out all other devices**, and **Download my data**
  gives one file with the member's data. Only the sign-in services your site has set up are listed.
- **Phone number:** shown only when your site can send text messages (BREVO_API_KEY in `.env`; sites with a Brevo key
  could not send codes before). One account per number. A code works for 10 minutes and 5 tries, at most 5 codes an
  hour.
- **Delete account:** asks for the password and an "I understand ..." tick. The section is hidden when **Let members
  delete their account** is off.
- **Premium page:** plain messages, and prices with the currency symbol and the yearly saving. Right after paying, the
  page waits until the payment is confirmed and offers nothing to buy, so nobody pays twice. A failed payment shows
  **Payment problem** and how to keep Premium. **Account settings › Premium** shows the plan in plain words and the
  latest invoices.
- **Channel page:** rebuilt, with the creator's header band in your look, tabs with their own addresses (Videos,
  Playlists, About), Share, the channel address with a copy button and "Last upload". Videos and Playlists page through
  everything. The settings for the videos per row and the rows of a playlist are gone, because a channel shows each
  playlist as one card; saved values stay.
- **Channel look:** a channel follows each choice of **Appearance › Creator channels** (card **Default channel look**,
  under Advanced), such as the **Accent colour** and the layout, until its creator changes that choice. Saving the
  channel settings no longer keeps the defaults shown at that moment, so a channel whose creator only changed the
  tagline follows your next default too. The **Accent colour** is **Look colour** (your look's accent colour) until you
  choose another, also on older sites where the default channel colour was never changed. Choices that channels saved
  before 1.2.3 stay as they were, except coral, the old default accent: those channels follow your **Accent colour**
  too, and their creators can pick coral again. Channel Home follows your limits for channel videos and playlists (up to
  48).
- **Subscribe:** one button everywhere, the watch page too. It asks before unsubscribing, and its bell sets what the
  member gets from that channel: All, Only highlights or None.
- **Library (new):** one page, `/library`, with History, Watch later, Liked videos, Playlists, Saved playlists and
  Subscriptions. The side menu, the account menu and the bottom bar on phones link to it.
- **History:** search, days grouped as "Today", "Yesterday" and dates, **Remove** for a day, **Clear all history** and
  **Pause history**.
- **Watch later and Liked videos:** the number of videos, the running time and **Play all**. Watch later has **Remove
  watched**, with Undo. Playlist pages have **Play all**, **Save**, **Share** and **Edit**. These pages show their first
  8 videos at once and the rest with **Show more**; the list ad keeps its place.
- **Subscriptions:** a feed with channel chips, **Manage subscriptions** and **Discover**. A creator who turns off
  **Show how many people subscribe** is not listed in Discover.
- **Sign in, Create account, Forgot password and Reset password:** one clear card next to your site's brand panel, with
  a show/hide password button and **Keep me signed in**. Messages say exactly what to fix.
- **Signing in:** members go back to the page they were on. A wrong password, a suspended account and too many tries
  each get their own message, and "Too many tries" says how many seconds to wait.
- **Sign-ups:** new sites, and updated sites that still have the value of 1 a minute that older versions installed,
  allow 3 sign-ups per internet address in 10 minutes. A number you set yourself in **Sign-ups allowed per visitor**
  (**Settings › Security**, card **Abuse limits**) still means that many per minute: raise it when many people sign up
  from one school or office network. When sign-ups are closed, the sign-in page says "Sign-ups are closed" and every
  **Create account** link is hidden.
- **Header:** on narrow screens, **Sign in** and **Create account** are icon buttons with a tooltip.
- **Account menu:** a full panel with Studio, Library and Account, the number of unread notifications, **Upload video**,
  Language, Light / Dark / Device and **Sign out**. Its badge says Administrator, View-only demo, Premium account or
  Standard account; before, administrators without premium saw Standard account.
- **Notifications:** a new bell panel and a **Notifications** page, grouped by Today, Yesterday, This week and Earlier.
  The bell counts up to 99, then shows 99+, and older notifications load as you scroll. Each one can be marked read or
  unread, or deleted.
- **Notification choices:** in **Account settings › Notifications**, members choose what the bell shows: new
  subscribers, comments, replies, and new videos from channels they follow. A channel's bell wins over this choice.
  Someone who subscribes again within 30 days is announced only once.
- **E-mails to members:** one clean layout in your site's colour, with a plain-text version and a translated subject.
- **Error pages:** 401, 403, 404, 410, 419, 429, 500 and 503 say what happened and what to do next. The maintenance page
  reloads by itself when the site is back.
- **Contact page:** matches the member pages and follows your contact settings.

### Watch page and the rest of the site

- **Buttons** no longer move under the mouse: the light sweep, lift and magnet pull are gone, and so is the flash on
  **+ Create Video**. Panels no longer tilt or lift. With **Shine on main buttons** (**Appearance › Effects & speed**),
  the main buttons get a still shine under the mouse instead of a moving light sweep. Their text keeps enough contrast
  in every look, also with **Film grain texture** on: in a few looks the button is a shade darker or lighter.
- **Watch page:** related videos and "Up next" no longer move under the mouse, so their buttons stay still. The
  creator's "show likes" choice is respected, creators get **Edit video** and **Statistics** on their own videos, and
  the raw video ID is gone. Comment menus are no longer cut off, and the tool bar no longer jumps while the page loads.
- **Like** and **Dislike** on the watch page go back to how they were when the site refuses the click (signed out, page
  out of date, view-only demo account, too many tries, no connection), and the message says why. A click on a link or a
  time code no longer opens or closes the description; its links are underlined in dark looks, and long words wrap on
  phones. "Autoplay next" is no longer cut off on screens 992 to 1100 pixels wide, tablets (768 to 991 pixels) show the
  player centred, and a playlist whose next video the viewer may not watch no longer ends in an error page. With the
  layout **Wide player on top** (**Appearance › Video page**), the player is centred on screens from 992 pixels wide.
- **Video tools** use plain words: the player's **Tools** menu and the tools sheet on phones name each tool the same
  way, for example **Color filter**, **Black and white**, **Mirror the video**, **Focus mode** and **Notes**, and the
  menu no longer shows untranslated state labels such as "Normal" or "0°". The sleep timer, notes and video details
  windows, the heading shown when the sleep timer pauses the video and the short messages of these tools use the same
  words, in the visitor's language, and the keyboard shortcuts panel names the tools the same way. On phones, the
  buttons of the tools sheet no longer cut a word in two.
- The video tools' messages follow each language's punctuation and numbers ("Vitesse de lecture : 1,25x" in French), use
  plain words ("Playback speed", "Use the gear icon inside the player to change the speed", "Bookmarks work only with
  the built-in player") and put the time where the language puts it. A snapshot is stamped with the video's real size
  (it said 4K for every video), and the brightness buttons of the tools menus read +15% and -15% in right-to-left
  languages too.
- **Screen readers:** **Like** and **Dislike**, also under comments, have names and say whether they are pressed,
  **Show more** in the description is a real button, and the close buttons of the tool windows are named in the reader's
  language. In the **Share** window, opening **Embed** keeps the keyboard focus in the window (Escape still closes it),
  and its back arrow has a name and returns the focus to **Embed**.
- **Video player:** the buttons of its tool bar, its **Tools** menu, the mini player and the "Back to the video" bar are
  named for screen readers by their words, the x of "Continue where you left off?" is named Dismiss, and the mini
  player's buttons ("Expand to the full player", "Close the mini player") and the note on Upload18 videos in the
  **Tools** menu ("This video plays in the Upload18 player: change its speed, volume and quality inside the player.")
  use plain words.
- **Keyboard users:** a link, button or field reached with Tab (or Option+Tab in Safari) is never left hidden under the
  sticky header, the bars at the bottom of a phone screen (the bottom bar, the studio tab bar), a save bar or a
  selection bar, "Back to top" or the day headings of the notifications list, on the site and in the admin. This holds
  even when a save bar or a selection bar appears because you ticked a box, changed a setting or typed in a field with
  the keyboard: the page brings that box or field into the clear at once. Nothing changes for mouse and touch use.
- Every page starts with a "Skip to content" link for keyboard users (it shows when it has the focus), in every
  language.
- The category chips row no longer sends the keyboard focus back to its first chip when Tab is pressed quickly.
- **Wording:** the watch page and the public pages say "Share video", "Video link", "Save to playlist", "Save to Watch
  later", "See all" and "All categories", and counts read like "7 categories". Comments are sorted by "Top comments" or
  "Newest first", related videos say "Picked for you", the Up next card says "Next video" and "Watch next", the header's
  random button is called "Random video", and the check mark of verified channels says "Verified creator". The Watch
  later list has the same name everywhere, and the Save pop-up for guests offers **Create account** only while sign-ups
  are open.
- **Comments:** switching between "Top comments" and "Newest first" keeps the list on screen, and **Reply** uses your
  look's accent colour. A guest's **Sign in** brings them back to the same video, in their language.
- **Share:** "Start at" uses m:ss and starts at the current position, and X has its new logo.
- **Save to playlist:** shows the member's playlists at once, undoes a save that fails, and creates new playlists as
  Private. It answers at once, also on slow phones, and "Create new playlist" sits at the bottom of the full-height and
  side-sheet windows. Every pop-up window opens and closes with less work for the browser.
- **Search:** very long searches work instead of failing, the suggestions mark what you typed in colour, and results on
  phones use the full width, without empty Previous / Next buttons under a single page of results.
- **Phones:** the bottom bar has **Your channel** and **Library**. The header and the bottom bar are slimmer, so small
  phones show more of the page. On the video page, the ad strip steps aside over the like row and the Subscribe row. On
  small phones (iPhone SE size), the ad strip appears only after the visitor scrolls. The "Back to the video" bar stays
  above the page's footer, and the sleep timer's message fits in the player with its Resume button (also in the mini
  player).
- **Phones and touch screens:** tapping the dimmed area around a centred window (upload, embed code, notes, sleep timer
  and others) closes it. The home page hero, the category chips and the videos of the home, trending and category pages
  keep 16 pixels from the screen edges; full-screen hero pictures still run edge to edge. Footer links, social buttons,
  the logo (in every look) and the ad strip's close button are 44-pixel targets, and the home page's rail arrows, "View
  trending", "Discover channels" and slide dots are bigger too.
- **Header:** the site's name is no longer cut: the search box makes room first, and where a look's own header links do
  not fit (Imperial Salon on smaller laptops), the last ones step out of the bar and stay in the side menu. The name's
  g, p and y are no longer clipped, **Create account** is no longer cut in Imperial Salon in Filipino, both guest
  buttons use the look's lettering, and the Premium chip is easy to read in light looks. The chip no longer cuts the
  site's name on small tablets and phones held sideways: it shows from 660 pixels wide (992 in Imperial Salon), and
  below that the account menu still says Premium account.
- **Messages:** one message system for the whole site. Messages stack above the bottom bars, and **Back to top** and the
  **Today's design** pill step aside. **Today's design** is one pill, next to the side menu.
- **Today's design:** the sheet is readable in light looks and uses plain words, nothing is cut in any language (on
  phones its colours become a list), and colour codes read right in Arabic.
- **Home:** category chips never scroll by themselves, the discovery rows that move by themselves stop after 20 seconds
  without input, the hero shows only published videos in the visitor's language (like the list under it), and the
  shorts shelf shows only videos of 3 minutes or less.
- **Category pages** without a picture get a compact band in your look's colours.
- **Avatars:** accounts without a picture of their own get a calm avatar with their initials instead of the colourful
  "CREATOR" or "ADMIN" badge. Imported creators keep their badge.
- **Verified check mark** (**Appearance › Video cards**): now only on channels whose owner confirmed a phone number, and
  on administrators' channels. Before, every channel had it. A site that cannot send text messages shows it only on
  administrators' channels.
- **Side menu:** in the icon style, labels use up to two lines and are no longer cut off, and the Premium link reads
  "Premium". The Premium link's default text is in the visitor's language (it stayed in English). Saved playlists that
  their channel made private are no longer listed or counted.
- **Arabic:** card badges, quick actions and **Back to top** sit in the mirrored corners. The close button of a window
  sits at the end of its title bar and no longer covers the title, the choices of **Report** sit next to their labels,
  and a tap on a playlist's box in **Save to playlist** ticks it.

### Uploads: never lose a video

Members upload as before: the upload panel and its steps are the same until Upload18 needs more than 10 minutes. What
changed happens around it.

- As soon as Upload18 has the whole file, the site keeps a private draft. If the member closes the page while Upload18
  is still processing, the site finishes the video by itself when Upload18 is ready (through the cron job, for up to a
  day). Until then, **Studio › My videos** shows the draft as **Processing on Upload18**.
- After 10 minutes, the upload window no longer ends with "Video processing timed out. Please try again." Instead, it
  says "Your video is safe." when the site has kept the draft (otherwise "Upload18 is still processing your video."),
  and offers **Keep waiting** and **Retry finishing**.
- If an upload fails while its edit page is open, the page says why, and its **Manage videos** button opens **Studio ›
  My videos**, where the video shows Failed and its menu offers **Upload again**. Before, the page reloaded into an
  error page.
- The draft page no longer reloads itself every few seconds while it waits for the upload to finish: it reloads only
  when its first picture is ready and when the upload is finished. So "Draft saved." stays on screen, and **Save draft**
  and **Finish later** are no longer cut short by a reload.
- Finishing an upload twice never makes a second video. If Upload18 hands back a video that is not the member's file (an
  older one, or one of another size or length), the upload is refused with a clear message.
- **Upload tokens:** members' browsers get a short-lived upload token instead of your Upload18 key. Each upload gets its
  own token, valid for 6 hours and only for your site's Upload18 category and upload server (and folder, when one is
  set). Only when Upload18 cannot make a token does the browser get the key itself, and only a restricted key with
  nothing more than Upload + Read. With any other key, the member then sees "Upload18 could not start the upload. Try
  again in a minute."
- **The Upload18 key of 1.2.0 to 1.2.2** no longer works. A site that still has exactly that key gets the new shared
  AVDBCMS key when it updates. A key you entered yourself is never changed, and an empty field stays empty.
- **New sites** start with the shared key, a restricted key named "AVDBCMS public download" (Upload + Read only), so
  uploads work at once, with both ways to install (**Import SQL backup** and **Run migrations and seeders**). A site
  installed from your own SQL backup keeps that backup's key. The card **Upload18 connection** explains how to create
  your own key at upload18.org.

### Imports: blocked words

- Imports from AVDBAPI and XVIDAPI, the scheduled ones too, skip every video whose title, tags or categories use words
  that present a child or a school-age minor. These words are checked in English, in romanised Japanese and in the other
  languages the providers send; they are always blocked and cannot be turned off. When AVDBAPI pages are fetched in
  another language, the import also checks each video's English details, and a video whose English details do not arrive
  is tried again on the next run.
- **Blocked words**, a new card on **Content › Import videos** (AVDBAPI and XVIDAPI, in the new and the old admin):
  videos whose title, tags or categories use one of its words or phrases are not imported. It starts with words for sex
  without consent, leaked or hidden-camera videos, school-age costume play and child-like looks. Change the list as you
  like: one word or phrase per line, at least 3 letters (2 in Chinese, Japanese, Korean or Thai), up to 500 of them.
  Videos already on your site are not changed by this list.
- The starting list keeps the AVDBAPI category of leaked videos out of new imports (about a quarter of the AVDBAPI
  videos in our test sample). To import it, take leak and leaked out of the list.
- The import log says how many videos were skipped because of blocked words. No tag or category is made from a blocked
  word, and translated tag names and titles that use a word that is always blocked are never saved.
- The update to 1.2.3 bans the videos your site already has whose titles, tags or categories use words that are always
  blocked, so visitors no longer see them. Each keeps its status, so **Unban** brings it back. Home (and the old admin's
  dashboard) says how many, with a link to review them, until you hide the notice; **Content › All videos** shows the
  reason, and **Tools › Logs › Admin actions** has one row with the counts. On a big site this goes on in the background
  after the update, and with Meilisearch these videos leave the search at once. The ban covers members' videos too. A
  member's video whose title, tags or category use these words cannot be made public, unlisted or scheduled, and a
  public video cannot take such a title or category: Video details, the draft page and the admin's video page say
  whether the title, the category or a tag is the reason; the studio's bulk visibility and the admin's bulk bar leave
  those videos as they are and say how many. Private videos, new uploads and the admin's **Ban** and **Unban** are not
  checked.
- Tags and categories whose names use words that are always blocked are no longer shown to visitors (menus, pages, video
  cards, search, sitemaps, the public API) or offered to members in the studio. Nothing is deleted: **Content ›
  Categories** and **Content › Tags** mark them (a hidden category has no place in the menu's order), and renaming one
  shows it again.
- Importer tags and categories whose translation named a child or a minor now show their English name (or a neutral
  word) in those languages, also where you changed a language file. Translations of importer tag and category names that
  use blocked words are removed from the language files you did not change, and the category "Teen" is marked 18+ in the
  16 other languages.

### Admin

- **Find any setting:** the admin search (Ctrl+K) and the search on **Settings** find every setting by its name, help
  text, card or old name (also the name the admin of 1.2.0 to 1.2.2 showed, in every language), the payment keys of
  **Money › Payment setup** too, and both put the same setting first. A word you type must start a word ("ads" no longer
  finds "uploads"), and each result says where the setting is. "upload18" finds the Upload18 key first; before, it found
  nothing.
- While the search is still loading, it says so instead of "Nothing found", and Ctrl+K also finds a page by the name of
  its first tab (for example "settings file"). The Ctrl+K window tells screen readers when it is searching, when it
  could not load, when nothing was found and how many results it shows.
- Settings and pages for developers are found too, marked Developer mode. Their page lists them under **Settings for
  developers**, with a **Turn on Developer mode** button that brings you back to the setting.
- **Back from the old admin:** **API access from other sites** on **Tools › Developer zone**, **Terms of service** on
  **Content › Info pages**, **Blocked ranges** on **Members › Blocked addresses**, and **Language switcher** on
  **Settings › Languages**.
- **Show every language right to left** is the new name of the old right-to-left switch (**Settings › Languages**, More
  options). Arabic, Persian, Hebrew and Urdu read right to left by themselves.
- **Creators can show "Last upload"** (**Appearance › Creator channels**, under Advanced) is the new name of
  **Creators can change the upload schedule**.
- **New switches**, both off as before: **Ask for strong passwords** and **Sign-ups from apps** (**Settings › Members &
  comments**, card **Sign-up & login**, More options).
- **What moved where** also lists the 19 tabs of the old Settings page, and names each page of the old admin menu in
  that menu's own words, in every language.
- **Upload18 connection:** this card on **Settings › Videos & uploads** (under Advanced) no longer needs Developer mode.
  It explains how to create your own key: upload18.org › API › Keys & security › Restricted keys, tick Upload and Read.
  The key stays secret: the field says Saved or Not set and never shows the key.
- The card checks your key with Upload18 about once a day. It says "Check your Upload18 key" when the key is a master
  key, can edit or delete videos, cannot upload or read, is an upload token, or is refused.
- **Members › Blocked addresses** now really blocks: blocked addresses and ranges (203.0.113.0/24, IPv6 too) get "Access
  denied." on every page and from the API. Before, a blocked address could still open the site. You cannot lock yourself
  out: your own address is refused, the sign-in page stays open and signed-in administrators are never blocked.
- **Notifications at any size:** **Community › Send a notification** has one form, **New notification**, in place of
  the **To everyone** tab. It sends to every member, to members with or without a confirmed e-mail, to Premium members
  or to chosen members (by member number), says how many members each choice reaches and sends in small batches. The
  Sent list goes page by page, its counts stop at "More than 100,000", and **Delete all...** empties it at once.
- Publishing on a channel with 100,000 subscribers takes under a second; before, the page ran until the server stopped
  it. The new video reaches every subscriber in small batches that the cron job starts within a minute (100,000
  subscribers: about 20 seconds of work on MariaDB, about a minute on MySQL 8), and a notification you send to every
  member goes out the same way.
- **Mark all as read** and **Delete all** answer at once, even with a million notifications, and so does deleting a
  member with many notifications. The rest is done in small batches in the background.
- Old notifications are removed once a day: those read more than 180 days ago, and all after a member's newest 1,000.
  Unread ones from the last 30 days always stay. On a big site, the first clean-up after the update works in short steps
  while the site keeps working (3 million notifications: about 25 minutes); after that it takes a few seconds a day.
- **Settings › Maintenance mode:** apps and the site's own scripts now get the maintenance answer too (before, the API
  kept answering); messages from Stripe and PayPal and uploads already running still go through. While you browse your
  own site, a strip at the top says that maintenance mode is on, with a link to its settings. Pages that you, the
  **Secret preview link** or an allowed internet address see are never kept by a CDN, and **Forgot password** works, so
  an administrator who forgot the password can still get back in. The maintenance page checks every 2 minutes (more
  often when **Tell Google to come back after** is shorter) whether the site is back, and says so.
- **Settings › Email:** while the **Alerts e-mail** or the **Support e-mail** is empty or still holds an example address
  the package shipped (noreply@example.com, support@example.com, server@example.com, admin@example.com), the site now
  uses a real address instead (the one in `.env`, SERVER_EMAIL or SUPPORT_EMAIL, else your contact e-mail), so failed
  background jobs and failed scheduled tasks reach you; the page shows the address in use. Nothing stored changes until
  you save another address. New installations put the administrator's e-mail in both fields.
- **Money › Earnings**, **Money › Payment setup** and Home now say when members cannot buy premium: a payment service
  without its webhook value (Stripe's **Webhook signing secret**, PayPal's **Webhook ID**), or no plan with the price ID
  of a connected service. **Money › Payment setup** gives the webhook address for Stripe or PayPal, ready to copy, and a
  button to the missing field. Before, the checklist said a service was connected while checkout stayed closed.
- **Money › Ads:** the main homepage list of the Spotlight layout (the layout of new sites) and of Cinema rows, Mosaic,
  Shorts and Control room gets its own list ad: **Ad code for the main homepage list**, under **Advanced › Different ads
  per list**. Until now that list never showed a list ad. The Classic grid layout keeps its own (**Ad code for the
  Classic grid homepage list**).
- When you update, the main homepage list gets the ad code and shape of your Classic grid homepage list, if that has a
  code. A code you add or clear later is never changed.
- **Ad after every** and **Most ads per list** now work in the main homepage list too. An empty ad code in **Different
  ads per list** shows no ad, as the card now says.
- **No ads on the Premium page:** the Premium page and the pages members see after paying with Stripe or PayPal show no
  ads (no header, footer, phone strip or pop-under ad).
- **Appearance › Themes › Complete looks › "Let visitors try the looks"** (off by default): for demo sites, a round
  palette button in the bottom corner of every page lets each visitor try the 12 looks, in dark or light, in their own
  browser; "The site's look" goes back. Your published look, your settings and what other visitors see do not change.
- **Money › Ads › Ads on or off › More options › "Demo site: ads are off until a visitor turns them on"** (off by
  default; needs **Show ads**): every ad, the video ad before the video too, stays off for each visitor until they turn
  ads on with the "Demo · Ads off" button at the bottom of the page; it changes only their own browser. Premium members
  are told they see no ads.
- While one of these two demo settings is on, your pages are no longer kept by Cloudflare or another shared cache (each
  visitor's choice stays theirs); with both off, pages are cached exactly as before.
- **Money › Payment setup:** a new **Invoices** card for the seller name, address, tax id and tax rate on invoices.
  Invoices have the columns Price and Total paid. A refused payment key says what it should look like (for example "A
  webhook signing secret starts with whsec_.").
- **Money › Payment setup** no longer shows "Premium badge on members": it never did anything. The Premium badge members
  see is the header's (**Appearance › Header, menus & footer › Premium badge in the header**), and searching the old
  name leads there.
- **Money › Premium page text:** new sites start with **Feature box: downloads** off, because the site has no download
  button for members. Your site keeps its own choice.
- The plan cards say "Your billing starts on" with the date (or "Your billing starts today.") and "Cancel anytime.",
  and the yearly saving shows once, on the yearly plan, worked out from your prices. When you update, these texts get
  the new wording if they are still the ones older versions installed; texts you changed stay as they are.
- The comparison's "Ads on videos" row shows "No ads" with a tick for Premium (before, a cross and "No"), and the help
  of **Free plan has ads** and **Premium has ads** says what each choice shows.
- **Content › All videos:** while videos are banned, the bulk bar has **Unban**. **Make public**, **Make private** and
  **Make unlisted** leave banned videos banned, and **Make public** now publishes the videos at once. **Ban** on several
  videos tells each creator, like a single Ban. Banning a video that is already banned no longer sends its creator a
  second notice: the page says that nothing was changed.
- With the optional Meilisearch search, banning and unbanning videos with the bulk bar or by blocking a member, and the
  bulk bar's visibility and category changes, update the search at once; before, bulk-banned videos stayed in its
  results. If the search server does not answer, the change is still saved (before, members saving their own videos got
  an error page) and the search is updated again in the background.
- **Content › All videos** points out public videos that were never published (an older version's Unban or Make public
  left them without a publish date): **Make public** publishes them, **Make private** hides them.
- Messages no longer cover the save bar. Admin pages that refresh parts of themselves no longer swallow a one-time
  message, and a page left open keeps you signed in. A view-only demo account is no longer sent back and forth between
  two closed pages, and its account menu says "View-only demo" and shows its choices without letting them change. In
  the account menu, a choice that cannot be saved goes back at once, and the count of things that need you on Home
  always matches its **Needs you** panel.
- **View-only demo accounts** can no longer open the AVDBAPI and XVIDAPI import pages and no longer see your import
  settings, provider addresses or schedules. The pages closed to them (imports, exports, members' invoices, backups,
  logs, the health check, background jobs, the database and the developer tools) are no longer offered to them: not in
  the menus of the new and the old admin, not on Home, not in the Ctrl+K search or the search on **Settings**, and not
  as buttons in the admin.
- A view-only demo account gets one "This is a demo" window whenever something it tries is not saved or a page is not
  open in the demo: in the admin and its lists, the studio, **Account settings** and on the site, instead of a red
  message or a note. Escape or **Got it** closes only that window, and what the demo refuses no longer ends in "Too many
  tries".
- On phones, a view-only demo account's note ("View-only demo: look around freely, changes are switched off.") is now a
  slim bar at the top of each page, as on the member pages; before, "Back to top" covered it, the phone bottom bar hid
  it in landscape and it lay over the video player. Larger screens keep it at the bottom; on tablets, where the note in
  a longer language reached "Back to top", it now sits just above the button.
- **Tools › Logs › Admin actions** now records what administrators do: making or removing an administrator, blocking and
  unblocking members, turning view-only demo accounts on and off, changed member details (which fields, never their
  values), every settings save (which settings, never their values), video bans and unbans, the bulk actions of every
  list, deletions, **Log in as this member**, the tools and security pages (commands, backups, database, the `.env`
  file, sessions, API keys, blocked addresses), and the changes administrators make to your content and setup
  (categories, tags, redirects, announcements, languages and their texts, premium plans, info pages, homepage picks,
  imports and their schedules, Clean All, Health check Auto-fix and cache clears). An action that changes nothing writes
  nothing, and clearing the log leaves a first entry that says who cleared it. No entry ever holds a password, key or
  payment detail.
- **Admin actions** and the activity cards of **Tools › Background jobs** (Queue and Scheduled tasks) name each action
  in plain words and show its details in your admin language, instead of internal keys and English sentences.
  **Admin actions** shows the details in full (**Show all** for a long one) and lists the newest first, also within the
  same second.
- **Content › Import videos › AVDBAPI:** the estimate and the answers of **Run now** and **Run Due Now** are in your
  admin language and count right.

### Telegram links

- The AVDBAPI Telegram group has moved to a new private group, and personal support is now @avdbapi_support. The
  footer's **"Support" links** of new sites, **Content › Import videos › AVDBAPI** and the installer link both.
- When you update, your **Telegram channel** link (**Settings › Site basics**, card **Social profiles**) and every other
  setting that still links the old group (t.me/avdb_api) get the new group's link. The footer's **"Support" links**
  (**Appearance › Header, menus & footer**, card **Footer**) get the new list (Contact, Telegram group, Telegram
  support, Terms of Service) when they are still the links of 1.2.2. **Tools › Logs › Admin actions** says what
  changed. **Restore my previous look** and **Use the new look again** on Home, and **Undo** in the design history,
  keep the new links too: they never bring the old group back.

### One-click updates

- Language files you changed on **Settings › Languages** now get the texts a release corrects: a text that is still
  exactly as an older version shipped it gets the new wording. Your own texts and keys stay, and the result says how
  many texts were corrected per language. The update also removes the texts this version no longer uses, when they are
  still exactly as a release shipped them (your own texts and keys stay), and says how many. **Undo this update** puts
  the files back as they were.
- This happens when an update finishes, with **Finish update**, with **Correct the texts** on Home or **Tools ›
  Updates** after an update by hand, or over SSH with `php artisan updates:texts`.
- **Tools › Updates** writes sizes the way **Tools › Backups** does (KB below 1 MB), names the database backup of an
  update exactly as **Tools › Backups** lists it (in the **Undo this update** dialog too), and on phones its buttons sit
  under the text.
- **Undo this update** back to 1.2.2 or older: a member's upload that Upload18 is still processing stays an unfinished
  draft, and updating again finishes it. Back to 1.2.0 or 1.2.1, the three development files that 1.2.2 removed stay
  removed.
- The database changes run while your site keeps working. On hosts with a short time limit (for example 30 seconds),
  the update finishes in steps of about 20 seconds, and the page sends the next step by itself. **Finish update** on
  Home works the same way: when a click gets too little time, it says "Some of the database changes are done. Click
  Finish update again for the rest." If the host stops a step anyway, the next one goes on at once (it waits only while
  your database still works on a change).
- Some work goes on in the background through the cron job: the times of views, subscriptions, saved playlists, likes
  and dislikes and the dates videos were added to playlists recorded before the update (a little every minute), the
  deletion of the old cache files and, on a big site, the recount of likes and comments, the hiding of videos with
  blocked words, the new notification indexes (with more than 100,000 notifications) and the video indexes of 1.2.3
  (with more than 200,000 videos). Until the notification indexes are built, only the admin's list of sent notifications
  can be as slow as in 1.2.2.
- The update removes the outdated database dump and the Docker files of the original project's development setup (they
  could not start) that 1.2.0 to 1.2.2 shipped, and the unused pictures of the old sign-in and error pages; a dump you
  made yourself, a Docker file you changed and any picture you changed stay, and **Tools › Logs › Admin actions** says
  how many files went. For containers, the setup guide describes 1Panel. It also gives the starting data of new installs
  the new version's copy while your site still has an unchanged copy of an older one, so a new install made from your
  site folder starts with a working Upload18 key.
- **Undo this update** brings back exactly the files the update removed or replaced when it finished (the old database
  dump, the Docker files, those pictures and the starting data of new installs), and the Undo window says so.
- The old version's style and script files that an update keeps for pages still open in browsers are now removed by the
  clean-up 14 days after the update (into the update's backup, so Undo still works); before, they stayed until the next
  update.
- **Windows hosts:** when the update to 1.2.3 finishes, and when you undo it, a moment in which another program (a virus
  scanner, a backup tool) holds the update's plan file no longer stops it: it tries again for a second, and otherwise
  says that the file is held open instead of "disk full or read-only".
- **Tools › Updates:** an update that stopped after its new files were in place now asks only to continue it (cancelling
  is no longer possible at that point).
- The result of an update and **Tools › Logs › Admin actions** count every database change, also when a step was cut
  off, and count the files the same way (merged language files on their own). The clean-up after an update no longer
  says "nothing is left" while a folder still waits, and the step log of an update from 1.2.0 shows every line in your
  site's time zone. When **Undo this update** cannot start because PHP may not write a folder, **Show details** no
  longer says that files were put back.

### Security

- Links in e-mails (password reset, sign-up confirmation) always use your site address: a visitor can no longer fake
  headers to send reset links to another site.
- On nginx servers that serve the whole site folder, private files (logs with reset links, backups, invoices) could be
  downloaded. The installer, Home and **Tools › Health check** now find this and say how to fix it. On aaPanel, the site
  also writes its nginx rules when the URL rewrite holds aaPanel's laravel5 preset, and **Auto-fix** in **Tools › Health
  check** writes the same rules (it wrote a bare rewrite rule). It writes them only into the URL rewrite of its own
  aaPanel site, which it finds in aaPanel's site files: a request with another site's name can no longer change another
  site's rules.
- Logs, backups, exports and invoices get names nobody can guess. **Tools › Logs** shows the logs as mail-….log,
  laravel-….log, stripe-….log and paypal-….log. The update renames your files, and the install lock no longer keeps the
  administrator's e-mail address. When you change the site key (APP_KEY in `.env`), invoices and logs move to the new
  names by themselves.
- The `.env` file gets mode 640 where your site's PHP owns it (on a new site, with **Auto-fix** in
  **Tools › Health check** and with the update; a stricter 600 stays): only your site can change it, and other hosting
  accounts on the server cannot read it. Its copies get names nobody can guess.
- The scheduler's logs can no longer be written by other accounts on the server.
- Running `config:cache` or `optimize` on **Tools › Developer zone › Commands** no longer copies your settings (for
  example the mail password and API keys) into the cached configuration file, and the page now shows the command's
  output.
- Members can no longer delete other members' comments or reports, put others' private videos into their playlists, or
  read hidden replies and the replies under private videos. **Save to playlist** also refuses another member's video
  whose publish date is still to come or that is still uploading, and a video that became private can still be taken out
  of a playlist. Likes, dislikes and reports accept only what the member can see on the site: a comment a moderator hid,
  or one under another member's video that is private or has its comments off, can no longer be liked, disliked or
  reported.
- Only the creator of a video (and administrators) can see who liked or disliked it.
- Trying video numbers no longer tells whether another member's private video, draft or video scheduled for later
  exists, or whether a video was deleted: Watch later, **Save to playlist**, **Report**, likes and dislikes, the
  studio's pages and the apps' addresses that take a video's number answer exactly as for a video that does not exist.
  **Save to playlist** and **Report** answer in the member's language when a video, playlist, comment or channel is
  gone, and reporting a comment under a deleted video no longer ends in an error.
- Trying numbers no longer tells whether another member's playlist, history entry, report, invoice, notification or
  device exists, whether a comment a moderator hid exists, or whether a member is suspended or has not yet confirmed
  their address (reports, Subscribe, the channel bell, profile pictures): each answers exactly as for a number that does
  not exist. The apps' addresses that take a video's link (the video, its player, comments, progress and views) and a
  video's picture and file answer for another member's private video, draft or video scheduled for later exactly as for
  a link that does not exist, and so do the addresses of another member's private playlist. A suspended member's channel
  page still says that the account is suspended.
- Unlisted videos and playlists stay with the people who have their link: a member can save another member's unlisted
  video (**Save to playlist**, Watch later) or report it only after opening its link, and can save another member's
  unlisted playlist only after opening its page; trying numbers no longer collects their links. What members saved
  before stays where it is. The apps can send the video's link token along with its number when they save it to a
  playlist or Watch later, or report it.
- The ten-digit numbers in new video links (and the names the site gives new pictures) now come from the system's
  secure random generator instead of the clock, so they cannot be worked out from the time a video was uploaded. A new
  number is never one that another video, even one in the bin, already has. Existing links do not change.
- A blocked member can no longer use the site's API (the mobile app).
- PayPal: coming back from PayPal can only activate the member's own subscription. Stripe: messages without a valid
  signature are refused.
- Deleting an account, or cancelling or deleting a subscription in the admin, also cancels it at Stripe or PayPal, so
  the member is no longer charged. When Stripe or PayPal does not confirm, the admin asks you to cancel it in their
  dashboard.
- **Log in as this member**, the members export, unlinking Google or Facebook and signing out can no longer be started
  by a link on another site. Opening the sign-out address asks first. A link on another site can no longer change a
  member's saved language (the language of their e-mails) either: the language menus of the header and footer change the
  language of the pages, the account menu and **Account settings** save it.
- The **Admin access lock** (**Settings › Security**) also covers logging in as a member, the other admin actions
  outside the admin pages, a watch page's playlist panel, the preview of unpublished design changes and the way through
  maintenance mode.
- The installer stays closed on an installed site, and `/install` no longer shows server details.
- Members export: values that start with =, +, -, @ or a tab can no longer run as spreadsheet formulas.
- A crafted address can no longer send your visitors to another site, also through the language switch and with **Always
  use the secure address (https)** on.
- The password reset page no longer passes its link to other sites.
- The premium pop-up and **Settings › Languages** show e-mail addresses and language names as plain text.
- View-only demo accounts can no longer open members' invoices, or confirm a guessed e-mail address on the **Password
  resets** tab of **Members › Logins & devices**.
- Admin tools that fetch an address you type refuse addresses on your own server or private network, and check https
  certificates.
- Sign-in cookies are for your site's own address only, not for its subdomains. Members stay signed in.
- A CDN never keeps a visitor's sign-in cookie with a page, picture, public file or list: an answer that sets a cookie
  is never marked for shared caches. Sitemaps, robots.txt and the IndexNow key file set no cookies at all, so a CDN can
  keep them.
- Changing an e-mail address sends at most 3 confirmation e-mails per hour, and e-mails show user names and video titles
  as plain text.
- Changing the password, signing out all other devices and deleting an account allow 5 wrong passwords in 10 minutes,
  then wait 10 minutes.
- **Uploads per minute** counts all of a member's upload requests, and making thumbnails on your server runs at most
  twice a minute per member.
- With **Announce new videos instantly** on (**Settings › Google & sharing**, card **Instant indexing**, under
  Advanced), each video address goes to IndexNow at most once an hour, and members can change the visibility of their
  videos in bulk at most 10 times a minute: nobody can flood your IndexNow key.
- Contact form: the e-mail comes from your site's own address (Reply goes to the visitor), and links and blocked words
  in the name are refused.
- **Community › Spam filter:** the blocked words and e-mail addresses now apply to comments too ("Spam detected").
- Notifications open only pages of your own site, another member's notification answers "not found", and marking
  notifications read can no longer be started by a link.
- An Upload18 upload belongs to the member who uploaded it, for good, and the site tells its Upload18 video code only to
  that member. Before, another member could claim it after 30 minutes.
- Upload thumbnails are stored only when they are real JPEG pictures, and every thumbnail is sent as a picture, so a
  file with hidden HTML or script can no longer run as a page.
- With the server rules of 1.2.3 (see the checklist), scripts never run in the upload folders, and database dumps, logs
  and backup copies in the public folder are never handed out; your site does not hand them out from the uploaded files
  either. Your downloads of backups and logs in the admin still work.
- On nginx, with the rules of 1.2.3 for a site whose web root is the site folder (see the checklist), UPGRADE.md,
  CHANGELOG.md, LICENSE, NOTICE, the version manifest and the docs folder are no longer handed out: they told visitors
  the exact version and every file's checksum.
- The AVDB import stores a downloaded poster only when it is a real picture.
- Your site sends its Upload18 key only in a request header, never in a web address.
- No account in the starter database of a new site has a password that others know.
- **Forgot password** and **Choose a new password** give the same answer for every address, and **Forgot password** also
  takes the same time, so it no longer tells who has an account (where PHP runs as CGI, for example with Apache's
  mod_fcgid, each of its answers takes about as long as sending one e-mail). **Choose a new password** now allows 5
  tries a minute per internet address. A suspended account is never signed in, also not through **Keep me signed in**.
- Signing in, on the sign-in page and in the apps, now takes the same time whether or not an account has the name or
  e-mail address that was typed, so the time of the answer no longer tells which addresses have an account. A password
  saved with another hashing strength than your site's is saved again with your site's when the member next signs in.
- A new password that the site refuses is never kept.
- A page that shows a one-time message is never stored by a CDN for everybody.
- The site's caches keep only the public details of accounts: the side menu's lists, the home hero, the creator
  spotlight, search results and the old admin's cached lists no longer hold password hashes, e-mail addresses, phone
  numbers or internet addresses.
- A crafted request can no longer make guests see a later page of the home feed in place of its first page.
- When your site refuses a message from a payment service, its answer no longer shows your setup.

### Speed

- The main stylesheet is about 85 KB smaller, and the video page is about a third lighter: on weak phones it shows its
  content about a second sooner.
- Thumbnails load with the page, the header search box and bell show at once, and pages no longer blink while they load.
- Weak computers get the balanced effects level after a short speed check, and Font Awesome icons appear right away.
  Public and member pages preload about 20 KB of icon fonts instead of 183 KB: they hold only the icons the site uses.
  An icon you choose outside them still shows: a page that shows one also loads the full icon font (158 KB).
- **Studio:** the dashboard needs far fewer database queries, also when its figures are refreshed, and typing in Video
  details no longer stalls slow devices.
- **Member pages** are lighter: Profile and Channel settings download 10 to 12 KB less, the channel page and History
  load no video player script, and a page of 20 notifications is about half its earlier size.
- **Home page:** creator avatars and tag cards in other languages no longer cost one query each, and a signed-in
  member's home shows its first row of videos with the page, as a guest's does.
- **Big sites:** the side menu's and the Categories page's video counts are kept until a video, a category or a member
  changes (and counted again at the moment a scheduled video goes public), and Home's lists, Trending, the most viewed
  and most commented lists and a category's videos read only the videos they show, also when the newest videos (or all
  the videos in a visitor's language) are in the bin, private, scheduled for later or of a banned member. With 300,000
  videos the first view of Home with nothing cached needs about half a second of database work (about a second on a busy
  MySQL server) instead of 8 to 37 seconds; Trending, Categories and a category's page take under a second, and cached
  pages a few hundredths of a second. Visitors browsing in a language few of your videos are in get these pages as fast
  as everyone else.
- **Most commented** list: Home's Most commented follows the number of public comments each video keeps (the same order
  as before), so on big sites it opens as fast as the other lists instead of taking several seconds.
- **Discovery rails on big sites:** Home's tags with the most videos, creators posting now and trending actors, its
  shorts shelf and the Ctrl+K window's **Popular searches** are worked out in the background by the cron job instead
  of while a visitor waits, with the same tags, creators, actors and counts as before and as often as before (every 10
  minutes; the shorts every 3 minutes, the Popular searches every hour). Right after an update or after the cache is
  cleared, Home shows its categories in those rails' place for up to a minute. Sites with fewer videos work them out on
  the page as before.
- **A category's page** loads its videos in a few hundredths of a second on big sites, also when you scroll far (each
  page used to sort every video of the category first).
- The hourly clean-up of unconfirmed accounts works at most 50 seconds per run, in the background, and continues in the
  next run, so a wave of sign-ups never holds up the other scheduled tasks.
- **Content › Import videos › AVDBAPI** opens as fast as the other admin pages: the PHP program for background imports
  is worked out once instead of on every visit, and again at once when that PHP stops working (**Tools › Health check ›
  Re-probe** looks again).
- **Notifications:** the bell and the **Notifications** page stay quick with any number of notifications, even a
  million for one member.
- Scrolling the watch and channel pages no longer stalls weak laptops: the mini player no longer measures the player on
  every scroll frame, and the footer's language menu is the browser's own (on phones, the phone's own picker). It lists
  the language names (typing a letter finds one) and opens at once; the box shows the flag of the page's language.
- Searchable lists, for example Country in **Account settings**, no longer freeze slow phones at the first search, and
  typing without accents still finds names with accents.
- The **Today's design** styles load only where the sheet is shown, the contact page's side picture is 174 KB instead of
  1.09 MB and looks the same, and admin pages no longer load the admin styles twice.
- **Clear All** on the **Likes** tab of **Content › Views & searches** deletes in small batches.

### Fixes

- **"Load more"** no longer fails with a server error or skips videos when a public video has no publish date. A "load
  more" link that is cut, edited or from before the update shows the first page instead of an error.
- **Unban** gives a video back the status it had before the ban. Before, every unbanned video became public, also a
  private video or a draft. A video banned before this update comes back private: make it public on its page if it
  should be.
- **Content › All videos:** the Undo shown right after banning a video now works for private, unlisted and scheduled
  videos too.
- Like, dislike and comment counts stay right when likes are deleted, comments are hidden or a member is removed. The
  update counts every video again once, and your site checks the counts once a day.
- A video's **Visibility** field in the admin no longer causes a server error. **Scheduled** can be kept only for a
  video that has a planned time, and **Content › All videos** no longer ends in a server error when a scheduled video
  has no planned time.
- On sites with Meilisearch, a public video without a publish date no longer stops the search index.
- A video imported from AVDBAPI that has no translation in a language now opens in that language with its own title and
  description, as that language's lists (Home, Trending, categories, search) already showed it; before, visitors who
  clicked its card got a "This video is not available in this language" page. Such a page is marked not to be indexed,
  and sitemaps and language alternates leave it out.
- Video sitemaps list a video imported from AVDBAPI in a language only when it has a translation of that language (or in
  English and the site's default language), and the language alternates, in the sitemaps and on the video's page, point
  only to those pages.
- The page of an imported video in a language it is translated into now names itself as its canonical page, so search
  engines can list it in that language. Before, it pointed to the default-language page.
- Your choices on **Settings › Google & sharing** now reach search engines through robots.txt: **Custom robots.txt**,
  "Hide the whole site from Google" and the full address of your sitemap. Before, web servers served the shipped file,
  which had a relative sitemap address. The site writes the file when you change one of these three settings, when you
  open **Settings › Google & sharing**, and at the end of an install. A robots.txt you edited yourself is never lost: it
  stays until you change one of these settings, and then the site keeps it next to the new file and tells you where. If
  the server does not let the site write the file, the page tells you what to do.
- A channel's playlists all show when you load more, and lists no longer skip a row at a page edge.
- The home spotlight's "Liked" share counts real votes and stays hidden when the creator hides likes. A deleted video
  leaves the spotlight at once.
- A picture that a member uploaded is no longer replaced by a generated avatar from an older copy.
- **Studio:** saving a video no longer removes it from other members' playlists, playlists of any length save (one of
  5,000 videos could not be saved before), and removing a comment also removes its replies.
- **Studio:** Activity's Likes and Dislikes filters work, a missing invoice PDF is made again, and a new draft no longer
  asks "Leave site?" when nothing changed.
- **Studio:** the dashboard says "No recent views" only for a new video that nobody has watched, guests included. In
  Arabic, comments, titles and names written left to right keep their punctuation at their own end. Comments and replies
  a moderator hid are no longer listed or counted in the studio (Comments, replies, Video details, the dashboard and My
  videos), as on the watch page.
- **View-only demo accounts** see why an action was not saved (in the "This is a demo" window), also in the studio, when
  they save to Watch later and on the Watch later, History, Library, saved playlists and **Manage subscriptions** pages.
- **Account settings:** passwords keep working after the site raises its password hashing strength, and a refused save
  of the channel look keeps the member's choices. A saved phone number that can no longer be read (for example after a
  database import) no longer ends in an error page: it counts as no number, and the member can enter a new one. The
  profile picture and channel banner pickers explain a refused file in plain, translated words (the file type message
  printed the word "allowedTypes"). Closing the picture crop window or the Welcome to Premium window with the keyboard
  puts the focus back on the page instead of sending it to the top.
- **Messages to members:** every "Too many tries" message says how long to wait, in seconds or minutes, in the member's
  language, also on **Account settings**, the other member forms and in the apps (it said "Too many attempts !"). An old
  or cut e-mail link says "This link does not work any more" and offers a new link instead of "Invalid signature.", the
  password reset messages are plain words in the member's language instead of English with "token", and a refused page
  or action says why in the member's language. A member who must confirm the e-mail first is told how to get a new link,
  and a page that timed out says so in the member's language, also in the apps, and no longer speaks of an "admin
  session".
- **E-mail confirmation:** after clicking the link, members see "Your e-mail is confirmed", and a member whose e-mail is
  confirmed is never asked to confirm it again. When the confirmation e-mail cannot be sent, members see a plain
  message; the mail setup hint is shown to administrators only.
- **Sign-in pages:** links and the show-password button react when you point at them.
- **Signing in from a page in another language:** the sign-in page is in that language, and the member comes back to the
  page in it (or in the language picked on the sign-in page). Signing in no longer changes the member's saved language
  (the language of their e-mails).
- **Robot check:** with the built-in check (a small sum on the sign-in, sign-up, contact and password-reset forms), a
  wrong answer gets "That answer was wrong. Answer the new question." and an expired question gets "The security
  question expired. Answer the new one." Before, both got "Please complete the security check."
- **Contact page:** clear field names and messages ("Your e-mail", "Enter your e-mail.") and a plain thank-you message.
- **E-mails:** "Your video was suspended" now shows the video's picture (it travels inside the e-mail), and amounts show
  the currency symbol, written the member's way (5,00 € in French, €5.00 in English), instead of "EUR 5.00".
- **Invoices:** an invoice PDF made by an older version (it printed another company and a fixed tax line) is made again
  with your invoice details before a member downloads it, and after a database restore a member never gets another
  member's invoice. The receipt e-mail now carries the invoice PDF; before, the file was empty.
- **Channel and History:** the Playlists tab showed only 6 playlists, long channel names broke in the middle of a word,
  History days were a day off west of UTC, full-width ads left holes in the channel's Videos tab, and a channel link in
  another language that used an older name or other capitals lost that language. History, the studio and the saved
  playlists show the times of views, subscriptions, saved playlists, likes and dislikes and the dates videos were added
  to playlists in your site's time zone, also those recorded before the update. After the update, the cron job converts
  these once in the background, into the time zone your site has when you update, whatever the time zone of your
  database. **Tools › Logs › Admin actions** says how many it changed and in which time zone, or that they already
  showed your time zone.
- **Save to playlist:** a video you save to a playlist gets its Date added in your site's time zone, like the other ways
  of adding videos, so a playlist sorted by Date added keeps the order you added them in.
- **Content › Categories:** a category whose only videos are in the bin can be deleted (those videos lose the category)
  instead of showing a server error.
- Deleting a member, a video, a playlist or a comment for good now removes what belongs to it on every site; on sites
  installed with **Run migrations and seeders**, replies, saved playlists, subtitles and views were left in the
  database.
- The hourly clean-up of accounts whose e-mail address was never confirmed no longer deletes an account that has videos
  (a member you marked as not confirmed keeps their channel; **Members › Delete** still removes it).
- **Shorts Feed look:** the small decorative stars no longer appear behind the text of Account settings, the Premium
  page and the studio.
- **Light/dark switches** in the side menu and the footer follow the device when it changes between light and dark (with
  **Follow device** in **Appearance › Themes**, or a member's **Device** choice).
- **Comments on phones and tablets:** the **Delete**, **Report** and **Pin** windows opened from the comments panel no
  longer keep the phone busy, and keyboard focus stays in them. Video details, the draft page and page changes in
  Safari no longer leave script errors in the browser.
- **Notifications:** the menu of the notifications panel (Select, Notification settings, Delete all) works with the
  keyboard: Enter, Space and the arrow keys open it and move in it, Home and End jump, and Delete all and Delete move
  the keyboard focus to their confirmation and back. On **History**, **Watch later**, **Liked videos** and playlist
  pages, a row's menu opens and moves with the arrow keys too.
- **Save to playlist:** the keyboard focus stays on the playlist box after a view-only demo account's message closes.
- **Content › All videos:** **Check durations again** no longer ends in a server error, **Change category** without a
  category shows an error instead of a success message, and the **Published** date filter stays on the screen on
  phones, tablets and right-to-left pages.
- **Content › Import videos › AVDBAPI:** switched to **Weekly**, the ready-made schedule of a new site runs on Mondays
  (it was saved as Sunday). A schedule your site already has keeps its day: choose the day you want when you switch it
  to **Weekly**.
- On Windows servers whose PHP prints an OPcache start-up warning, the admin reads the PHP version correctly again, so
  imports and scheduled tasks start in the background instead of inside the page.
- **Admin lists and tools** no longer show an error page for crafted addresses with unknown filters or lists of values.
- **Tools › Cache & storage:** clearing a very large folder stops after about 10 seconds and says "More files are left:
  click Clear again to remove them." In the old admin, the page also opens quickly on sites with very many files.
- **Old admin:** the colour contrast check works for every colour. On phones in dark mode, its dashboard cards are
  readable, its header buttons no longer break inside words and its Recent Users table scrolls. It is readable in dark
  and in light mode on every page: card headers, the settings pages' header and cards, the Appearance studio panels, the
  PHP information table, the search preview, code, coloured buttons and badges, the selected log file, form fields and
  drop-down selects (now with a visible edge), and the log viewer.
- **Tools › Logs** no longer says that its front-end assets are outdated. On Linux servers it no longer ends in an error
  when your site may not read a log file, such as the web server's own logs or one written by the server's own cron:
  such files are left out of the list (**Site logs** says how many). Downloading a backup or an export that your site
  may not read says so instead of showing an error page.
- **Settings › Site basics:** the examples of **Date format** are in your admin language, and the "Default" under
  **Site name** (and under the other settings whose default comes from the server's configuration, such as the mail
  server) is the real default, so **Reset** works.
- **Admin:** the links of **What moved where**, the page path at the top, the check boxes of the XVIDAPI import, the
  Download and Delete buttons of the log files and the old Views list's delete button are bigger targets, the disk usage
  bar of **Tools › Health check › Server** is readable with the admin colour
  **Black and white** in dark mode, and Safari no longer reports errors when you leave an admin page that is still
  loading.
- **Money › Subscribers** and **Money › Payments & invoices** write "PayPal" (it showed "Paypal").
- **Money:** every amount on **Money › Earnings**, **Money › Payments & invoices**, **Money › Premium plans** and Home's
  Earnings tile, also in the old admin, is in your site's currency. Before, the lists of payments, invoices and plans
  showed "$" or "EUR" whatever you sold in, and with a currency without cents, such as yen, the earnings were 100 times
  too small.
- **Always use the secure address (https)** no longer sends search engines round in circles behind a proxy that does not
  tell your site about https.
- **Install:** **Run migrations and seeders** works on MySQL and MariaDB, and an installation that fails or is cut off
  leaves the earlier `.env` file as it was. An admin e-mail address or username that a deleted account still holds no
  longer ends the installation in a database error: the installer takes that account over, or, when the database already
  has an administrator, says in plain words to choose another one. **From email** is no longer filled in with the
  example address noreply@example.com: left empty, your site sends its mail from your admin e-mail. On phones and
  tablets, each step's form now starts on the first screen.
- **The installer works in steps:** its database work runs in short steps with a progress page (keep it open until the
  result appears), so a host that stops long requests, for example after 30 seconds, no longer cuts an installation off
  half way: a step the server stops early is picked up again by itself, from a safe point. The `.env` file is written
  only at the very end, after the database and the admin account are ready; before, a cut-off installation left a site
  that said "Already Installed" without an admin account. An installation that did not finish can be continued from
  `/install` with the password of its database (everybody else only sees that it is not finished); after many wrong
  database passwords from other addresses, the installer asks for one more proof: an empty file with a name it shows,
  created in the site's storage/app folder with your hosting's file manager, then the password again in the same
  browser, so strangers cannot keep you out for an hour at a time. INSTALLER_ALLOW_REINSTALL stays in `.env` until the
  new installation has finished.
- **Install permissions:** when PHP may not write the site's folder or its `.env` file, the installer's **Filesystem**
  step says so first, with the fix (on Linux: the user PHP runs as, the owner of the files and the command); before, the
  installation failed at its very last step. The "Runtime Permissions Required" page no longer tells you to upload the
  package again but gives the same fix, and the setup guide gives the whole site folder to the user PHP runs as, on
  1Panel too, instead of making two folders writable for everyone.
- **Mobile apps (the site's API):** signing in and signing up (with **Sign-ups from apps** on) no longer end in a server
  error, and the list of followed channels loads past its first page.
- **Sites whose address has a port** (for example an IP address with a port): members' likes, comments, subscriptions,
  **Save**, the bell and History now work; before, they failed with "Unauthenticated". New installs write the right
  setting, and sites installed before are fixed without a change to `.env`.
- **E-mails sent in the background** (reminders, account deletion, exports, video notices) now use the mail server,
  sender, site name, time zone, date format and languages of your settings, like the pages do; before, they used the
  values of the installation, so a mail server set up later in **Settings › Email** was never used for them. The
  free-trial reminder comes as many days before the end as **Remind members before the trial ends** (**Money › Premium
  plans**) says, and a running queue worker takes changed settings from its next e-mail on.
- **Scheduled tasks on Linux hosting:** each site now locks its cron line inside its own folder, so sites in folders
  with the same name (for example public_html) no longer stop each other's scheduled tasks, also sites of other accounts
  on the same server. The cron line your site added itself is updated by itself; every other line of the crontab stays
  as it is, also a line you switched off with # or changed yourself.
- Your site no longer starts its scheduled tasks after page views while the server's cron runs them, so members no
  longer get the same reminder e-mail twice. The free-trial and card reminders go out once, also when two runs meet in
  the same minute, and no longer stop at a member who deleted the account. A new site no longer runs its scheduled tasks
  while the installer is still at work, so its log no longer starts with error lines.
- **Tools › Background jobs › Scheduled tasks** shows how the tasks start (the server's cron, only page views of your
  site, or not at all), whether your account's crontab has your site's line, and the lines to add: the cron line for
  your account's crontab, the command for a cron job in a hosting panel (without the `* * * * *`, which a panel's
  command field cannot run and so never started the scheduler), and the command for panels that run cron jobs as root
  (on Windows, the command that creates the task in the Task Scheduler). **Content › Import videos › AVDBAPI** offers
  the same commands, and adding a command next to the site's own line no longer runs the scheduled tasks twice a minute.
  Home's warning now counts only the server's cron; before, page views made it look as if the cron ran. The cron's runs
  and the runs your site starts have separate logs, which grow line by line instead of being overwritten and are trimmed
  when they get large.
- **Videos stored on your own server** no longer answer with a server error on nginx: your site checks whether nginx can
  send them and otherwise streams them itself. The nginx rules of 1.2.3 let nginx send them (see the checklist), and
  **Tools › Health check** says which one does.
- **Clean addresses on nginx:** links (also in e-mails), canonical addresses, language alternates and sitemaps drop
  /index.php once your site has checked that your server sends clean addresses to it, and old /index.php/... links keep
  working. Without the rewrite rule, or when it disappears later, the site keeps or goes back to /index.php/... links.
  **Tools › Health check** shows the answer under **Clean addresses** (**Re-probe** checks again at once), and
  APP_CLEAN_URLS=true or false in `.env` decides it for good. Language alternates no longer point to addresses like
  /fr/index.php, which answered "not found" on nginx.
- **Servers without the rewrite rule:** the next page of a list, a filter with an empty field, a form sent back after a
  mistake, signing in after opening a members-only page and the **Sign in** links under comments keep /index.php and
  work, also for visitors whose visit began before the update (before, the comments' **Sign in** led to the web server's
  404 page), and the e-mail confirmation links no longer answer with an error there.
- Opening one of the site's own folders in the browser (for example /storage/logs/) is refused and no longer ends in
  "too many redirects".
- **Sites installed in a folder of their domain** (for example https://example.com/site) work everywhere again: signing
  in and out, every member page, the studio, the admin, the language switch, e-mail links, social previews and updates
  keep the folder, also on servers without rewrite rules (/index.php addresses). The installer suggests the address with
  the folder and gives the folder's own server setup, and the setup guide has the steps for Apache and nginx. Members of
  such a site stay signed in through the update.
- Signing in from a video page no longer fails now and then with "The security question expired" or "Page expired": the
  video still loading in the page you came from could replace the sign-in form's question or its security token.
- **Google and Facebook sign-in** on servers without rewrite rules (addresses with /index.php): the address the provider
  sends the member back to now has /index.php too (it was the web server's 404 page). On such a site, enter
  https://your-site/index.php/oauth/callback/google (or /facebook) at Google or Facebook; a site with clean addresses
  keeps its address. An address you set as GOOGLE_REDIRECT_URI or FACEBOOK_REDIRECT_URI in `.env` is used as it is.
- The redirect from www (or from http) to your site's own address keeps the page's language and your site address's port
  (http://www.example.com/fr/trend went to the English page).
- **Over SSH:** `./artisan` works on Linux servers; it stopped with `/usr/bin/env: 'php\r': No such file or directory`
  (`php artisan` always worked).

### Translations

- Every new text of the site and the admin is in all 17 languages, with each language's plural forms (for example
  "3 unsaved changes": three forms in Russian, six in Arabic), also on the pages of the old admin. The installer stays
  in English.
- Notifications show in the reader's language, also many from before the update.
- Numbers and dates follow the reader's language: view counts ("11 537 vues" in French) and the short counts on cards
  ("18,8 k"). Invoices and their PDF, **Account settings › Premium**, the Premium page and the Premium e-mails write
  dates and prices the member's way, in your site's currency (5 nov. 2026 and 5,00 € in French, Oct 5, 2026 and €5.00 in
  English); the admin's **Money** pages keep one form. The invoice PDF is in English for members whose language is
  Arabic, Chinese, Hindi, Japanese, Korean or Thai, because its font cannot print these languages.
- Corrected words: the language picker is called "Change language", and "Playlists" no longer reads "My playlists" (14
  languages). Also "Open account menu", "More actions", "Preview", "Review" (it read as a noun, such as a book review or
  an exam, in 12 languages), "Custom" (it read as "tradition"), the "Short" label of short videos, the video tools (the
  tool bar's "Sleep" read as a verb in 10 languages) and the words of **Today's design**. On the Premium page, Arabic
  and Malay say "payment" (they said "exit").
- Several option names of **Appearance › Creator channels** and of the channel form in **Account settings** are
  corrected in Arabic, Hindi, French, Indonesian, German, Chinese, Russian, Spanish, Turkish and other languages, and
  German calls **Appearance › Video cards** "Videokacheln" (it said graphics cards). In German, "Clear selection" and
  "Dislikes" are corrected; in French, "Unlisted" is spelled "Non répertoriée"; in Arabic, "Upload video" says upload,
  not download; and in Filipino the category "Amateur" starts with a capital like the others.
- German: **Account settings**, the Premium page and the other texts that still said "du" (some admin help texts too)
  now say "Sie", like the rest of the site; only the texts of the upload window and of a video that is still processing
  are unchanged. The Premium page's free trial reminder ("We will email you 7 days before your trial ends") now reads
  correctly in German, French, Russian, Turkish, Vietnamese, Hindi and Arabic.
- Form messages read better, for example "Username is already taken." instead of "The Username has already been taken."
  The messages under the fields (too short, not a valid e-mail, a picture that could not be uploaded and the others) are
  in plain words in every language and name the field as the form does. Before, 16 languages still had many of them in
  English, and outside English the admin's forms often showed a field's code name (such as ip_address). While you keep
  the shipped texts, the Premium page says the free trial's days in each language's forms, also for 1 day ("1 day
  trial").
- **Admin:** every message after an action, **Tools › Health check** (its checks, the names of a generic web server or
  hosting panel, automatic configuration lines and repair results), the scheduled tasks, the commands,
  **Tools › Database**, the e-mail test, both **Import videos** pages and the messages that refuse an unusable video
  link format are in your admin language. "Block", "Test connection", "Premium" (no longer an insurance premium or an
  instalment) and "webhook" are corrected, and so are Arabic words such as "Confirm". **Payment setup** names
  developer.paypal.com right in every language, and in Arabic, menu paths, key prefixes and the change figures on Home
  keep their order.
- In the admin in Arabic and the other right-to-left languages, cron lines, commands, run times and every file, database
  and disk size read left to right ("3.6 MB", on one line). In Arabic, Chinese and Japanese, lists inside a line
  (settings changed, More options, Admin actions details) use the language's own commas, and admin lists in Arabic and
  Filipino write times in words instead of with English unit letters (such as "5m").
- "missing" no longer reads as "missing persons", and the update's file counts read right in Russian, French and Hindi.
- The homepage layout **Shorts** is no longer translated as the clothing (Spanish, Indonesian, Korean, Malay, Turkish).
  **Spotlight** and the "main homepage list" labels are corrected in six languages each.
- The import log no longer writes "item(s)" or "ID(s)".
- One word for one thing in each language: the video player is a media player everywhere (it read as a person who plays,
  such as Spanish "jugador", German "Spieler", French "joueur", Russian "игрок"), and a playlist (German no longer mixes
  "Playlist" and "Wiedergabeliste"), a Premium plan, the site's header and footer, theater mode, the language switcher,
  an administrator (in Arabic), a video and an upload each keep one name. French says "mettre en ligne" for an upload
  (it said "télécharger", which means download), and Spanish says "reportar" in the report dialogs like the rest of the
  site. The default footer tagline and the default titles of the homepage's creators row use each language's word for a
  creator.
- Counts use each language's plural forms on the watch page, the cards (comments, views, results, replies) and in the
  admin: Arabic uses its six forms and its dual ("تعليقان", "38 فيديو", two views and two videos), and Russian uses the
  right form after 21, 22 and the like ("21 просмотр", "2 комментария").
- Portuguese is Brazilian Portuguese everywhere, the member e-mails, the invoices page, the maintenance page, the
  password-change message and the form error messages included (excluir, senha, arquivo, curtida, "Assistir mais tarde",
  "Entre em contato conosco", "Baixar o PDF"); the delete dialogs and their buttons use the same word. The member
  e-mails about Premium say Premium in the language of the pages (Arabic, Hindi, Japanese, Korean, Russian, Thai,
  Chinese).
- Japanese writes each word one way (カテゴリ, サーバー, フォルダー, ブラウザ, フィルター) and calls a phone スマホ.
- **Premium page:** the buttons use one form in each language (Spanish, Turkish, Japanese, Hindi, Korean), Portuguese
  says "Downloads" (it was Spanish), "Monthly" and "Annually" are corrected in several languages, and the free trial is
  named one way across **Money › Premium plans**, **Money › Premium page text** and **Money › Subscribers** (several
  languages called it a software trial, a probation or an evaluation); the help texts of the number settings now say
  what the number means.
- Filipino: the side menu, the studio menu and the old admin's menu are translated (some titles were English); in the
  other languages the side menu's Trend item uses the trending page's own name.
- The admin reads in the right sense in every language, on the Appearance pages, the settings, **Money › Ads**,
  **Tools › Health check**, **Tools › Background jobs** and the old admin: rows are not railways, a feed is not animal
  food, a homepage is not a house, category chips are not potato chips, a complete look is not a gaze, a colour's hex
  code is not a hexagon, routes, drivers, jobs and workers are not roads, chauffeurs, vacancies or labourers, and
  "Blade" in the Blade view cache is not a knife. Folder, file and setting names in the admin's texts are written as
  they are (for example disable_functions), and the switches' helps no longer leave the English "Off". Words that share
  a name with imported category names no longer show category translations: "Plain", "Words", "Multiple" and "Japanese"
  in the admin, the theme colour "Bronze", the homepage labels "Travel" and "Documentary" of two themes and the video
  tools' "Anime" colour filter. Imports are not called trade imports in Japanese, Korean or Chinese.
- Admin texts that name a page, a switch or a button ("Discover channels", "Use my custom code", "Back up now", "Read
  more") use its exact words in every language, and on **Settings › Google & sharing** the helps on words in {braces}
  call them braces (several languages said square brackets or parentheses, or kept the English word).
- **Community › Reports** reads right in every language ("Block it", "Waiting", "Open on site"), and a visitor's report
  is called by the same word wherever it appears (the report button setting, the security limits, the admin Home and the
  member's report dialog); several languages called it a document report. In the old admin, an administrator's badge and
  the Admin actions column say "Administrator" (other languages showed the word for the admin area), and the header's
  search, upload, sign-in and register switches say what they show (they read as a CPU register or a title). The admin's
  "Clear ..." buttons read right (Malay "Clear Guests" read "obvious guests"), and **Appearance › Effects & speed** and
  **Appearance › Header, menus & footer** read right (style names, the footer's bottom and copyright lines, the default
  "Terms" link, the link formats). On the homepage, the themes' headings say what they mean ("Fresh drops", "Dreamy
  shorts", "Top plays" and "Breakout" were read literally).
- One name per thing on the Appearance pages (the category tiles, the homepage rows and their small title lines, the top
  banner, view counts and the ring around creator pictures), on **Settings › Advanced** (the creator tips box, the
  creator alerts box and the comeback checklist) and on **Money › Premium page text** (the feature boxes), and the admin
  names your site's homepage one way in each language (apart from the admin's own Home). **Appearance › Homepage:** the
  section animation choices are named as animations ("Lift" was an elevator, "Slide" a playground slide, "Fade"
  discolouring), the row-direction choices are directions ("Right" read "correct", "Down" read "low"), and the live
  preview's phone button is now "Mobile phone" (it said "phone number" in Japanese, Korean and Chinese). The old
  settings page's channel section shows its default channel settings' names in your admin language and says the accent
  colour and banner style as the Appearance pages do. After a number box, the unit (seconds, minutes, days) fits any
  number in every language, and the label before the box reads as one phrase with the number.
- **Admin wording:** Portuguese says "Configurações" for Settings everywhere, Russian tells the Premium page's download
  settings from the upload ones, and the switch for creators' own accent colour reads "Creators can change their own
  accent colour".
- The upload window and its messages use the site's words in more languages: French "mettre en ligne", Arabic رفع,
  Spanish "subida", Portuguese "upload" and Indonesian "unggahan" for an upload, Japanese 動画 and Korean 동영상 for a video,
  and "Upgrade to premium" names Premium (Arabic said "instalment"). The upload window's refusals (uploads switched off,
  the free plan's video count and space limits) are written in the member's language, with the sizes in its own form.
- Dates, sizes and punctuation follow the reader's language: Library, History, playlist, channel and video pages write
  dates in each visitor's language and in the site's Date format (28. Okt. in German, 28 oct. in French), a channel's
  "Joined" month too, and a planned video's line says when it goes public in the site's time zone; file sizes on Video
  details, the draft page and the Premium page are written the language's way ("1,09 Mo" in French, "1,09 МБ" in
  Russian); and a label and its value are written with each language's punctuation (a space before the colon in French,
  a full-width colon with no space after it in Chinese) on the admin's pages, its search and calendar, the header search
  and the playlist editor.
- **Library:** the See all and Manage links are named with their own words in Russian, Indonesian, Japanese, Spanish and
  Portuguese, so voice control can reach them.
- More wording polish in many languages: the Japanese buttons on the admin's member page each say what they do again,
  Japanese and Korean labels are clearer, the creator studio and the uploads, downloads, storage and ads rows of the
  Premium page settings each use one word, and the phrasing is more natural.
- Chinese no longer shows an extra space after a colon on admin labels, on the member and plan edit page titles and in
  the colour-copy message. French puts a no-break space before "?" in every question.
- The old settings page (developer mode) shows the unit (days, ms, px) after its number boxes in every translated
  language.

### Not changed

- The video player, its video ads and the embed pages work as in 1.2.2. Only what is around and over the player changed,
  as "Watch page and the rest of the site" says: for example, the player's tool bar and tool windows use plainer words
  (**Video tools** instead of "Cinema Suite & Tools" with a "PRO" badge) and name their buttons for screen readers, a
  snapshot is stamped with the video's real size, on phones the "Back to the video" bar stays above the footer and the
  sleep timer's message fits with its Resume button, and the page centres the player on tablets and with the layout
  **Wide player on top**. The embed pages now show your site's browser tab icon (they linked three icon files that did
  not exist).

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
