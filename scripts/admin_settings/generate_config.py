"""Generate config/admin_settings.php (and the Ctrl+K settings rows) from the admin design spec.

The redesigned admin places every setting (the spec's 635 and those added later) on a page and a card with a plain
label, a one-line help text, a control type and (for 22 risky switches) the consequence shown before saving. All of
that comes from admin_design_spec.json (ia.sections[].pages[].cards + mapping.settings + mapping.tab_redirects); this
script turns it into a PHP config file so the settings pages, the Appearance pages and the Ctrl+K search all read one
source. Places decided after the spec was written are applied on top (MOVED_CARDS), and so are texts and defaults
corrected later (SETTING_FIXES, CARD_FIXES), levels decided later (LEVEL_FIXES), cards added later (ADDED_CARDS),
settings added later (ADDED_SETTINGS) and search words added later (ADDED_SYNONYMS, and the labels a renamed setting had
in the new admin before: FORMER_LABELS). The same data is written as resources/admin/lib/settings-index.json, the
settings rows of the Ctrl+K search (App\\Support\\AdminSearchIndex).

Every setting can be found (1.2.3): the search lists every setting at every level. A setting that has no row of its
own says where it is changed instead: a gallery (SETTING_LINKS: the page that sets it and a marker of that page) or,
for the hidden ones, the setting that replaced it (HIDDEN_TARGETS: "see" another setting, or a "link", with a short
"hint" shown in the search). Settings the code reads that have no admin field on purpose are listed with the reason
(NO_FIELD -> 'no_field', read by tests/Feature/AdminSettingsCoverageTest.php), and the tabs of the old settings page
(OLD_TABS -> 'old_tabs') feed "What moved where".

usage:
  python scripts/admin_settings/generate_config.py [--spec PATH] [--out PATH] [--index PATH] [--former-out PATH]
                                                   [--schema PATH] [--former-122 TREE] [--check]

  --spec        admin_design_spec.json (default: D:/avd-notes/redesign/spec/admin/spec/admin_design_spec.json)
  --out         output file (default: config/admin_settings.php next to this script's repository)
  --index       Ctrl+K settings rows (default: resources/admin/lib/settings-index.json next to this script's repository)
  --former-out  the settings' former names by language, for the admin's searches (default:
                resources/admin/lib/former-names.json; made from FORMER_NAMES_FILE)
  --schema      optional JSON dump of SettingsController::settingsSchema() ({group: {key: {type, rules}}}) to verify
                that every setting is saved by the group and with the type the spec says
  --former-122  the 1.2.2 package tree: make FORMER_NAMES_FILE from it first (R3-071; after the language files change)
  --check       do not write; exit 1 when an output file is missing or out of date

Settings that no release ever read are taken out (REMOVED_SETTINGS); their former names lead to the setting that does
what owners looked for.

Labels, help texts, confirms, card titles and descriptions stay in English here: they are translation keys and the
views translate them with __() at render time (never inside a config file).
"""
import argparse
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DEFAULT_SPEC = os.path.join('D:/avd-notes', "redesign", "spec", "admin",
                            "spec", "admin_design_spec.json")

# The pages the settings controllers render (route admin.settings.page {page} and admin.money.*). Every other page
# that holds settings is rendered by its own controller (Appearance, list pages); its cards are still listed.
RENDERED_BY = {
    "settings": "settings",
    "money.ads": "money",
    "money.setup": "money",
    "money.premium_page": "money",
}

# Topics of the settings hub ("What do you want to change?"), in the order of the hub mockup:
# page id, icon, one line, examples.
HUB_TOPICS = [
    ("settings.site", "globe", "Name, description, contact email, language and time zone.", "Site name, Time zone, Social profiles"),
    ("settings.features", "sliders", "Big switches for sign-ups, uploads, comments, embedding.", "Let visitors create accounts, Comments"),
    ("settings.members", "users", "Sign-up rules, email confirmation and comment rules.", "Ask new members to confirm their email"),
    ("settings.videos", "upload", "What members may upload, thumbnails and embedding.", "Longest video, Allowed file types"),
    ("settings.email", "mail", "How your site sends emails, with a test button.", "Email server, Send emails from"),
    ("settings.seo", "search", "How your site looks in Google and when shared.", "Sharing picture, Video page title"),
    ("settings.security", "shield", "Robot checks, secure address and abuse limits.", "Robot check, Always use https"),
    ("settings.languages", "globe", "Add languages, translations and the switcher.", "Language switcher, Right-to-left"),
    ("settings.maintenance", "pause", "A \"back soon\" page for visitors while you work.", "Message for visitors, Secret preview link"),
    ("settings.advanced", "layers", "View counting, clean-up and creator helpers.", "3 more pages for experienced owners"),
]

# The 22 confirms that explain a consequence, and when they are asked: on = switched on, off = switched off,
# change = any new value, filled = a new non-empty value. The 13 other spec confirms only say "Confirm this change?
# It affects every visitor immediately after saving." - not a consequence, so they are left out (the save bar is the
# confirmation for those).
CONFIRM_WHEN = {
    "registration_enabled": "off",
    "mail_mailer": "change",
    "email_verification_required": "on",
    "custom_js": "filled",
    "custom_head_html": "filled",
    "profile_uid_urls_enabled": "change",
    "video_url_prefix": "change",
    "video_url_pattern": "change",
    "robots_noindex": "on",
    "robots_nofollow": "on",
    "force_https": "on",
    "hsts_enabled": "on",
    "csp_enabled": "on",
    "frame_options_header": "change",
    "captcha_provider": "change",
    "admin_ip_whitelist": "filled",
    "global_ip_blacklist": "filled",
    "default_locale": "change",
    "cors_allowed_origins": "change",
    "cors_supports_credentials": "on",
    "maintenance_mode_enabled": "on",
    "maintenance_allow_admins": "off",
}
GENERIC_CONFIRM = "Confirm this change? It affects every visitor immediately after saving."

# Button label of each confirm dialog: named after the action, never "OK" / "Are you sure?".
CONFIRM_ACTION = {
    "registration_enabled": "Turn off sign-ups",
    "mail_mailer": "Change how emails are sent",
    "email_verification_required": "Ask for email confirmation",
    "custom_js": "Run this code",
    "custom_head_html": "Add this code",
    "profile_uid_urls_enabled": "Change channel addresses",
    "video_url_prefix": "Change video addresses",
    "video_url_pattern": "Change video addresses",
    "robots_noindex": "Hide my site from Google",
    "robots_nofollow": "Stop following links",
    "force_https": "Always use https",
    "hsts_enabled": "Remember https",
    "csp_enabled": "Turn on security rules",
    "frame_options_header": "Change frame blocking",
    "captcha_provider": "Change the robot check",
    "admin_ip_whitelist": "Lock the admin",
    "global_ip_blacklist": "Block these addresses",
    "default_locale": "Change the language",
    "cors_allowed_origins": "Change API access",
    "cors_supports_credentials": "Allow signed-in requests",
    "maintenance_mode_enabled": "Turn on maintenance mode",
    "maintenance_allow_admins": "Hide the site from admins too",
}

# Choices whose values the spec only names in words (radio cards / labelled segmented controls).
OPTION_LABELS = {
    "mail_mailer": {
        "smtp": "Email server (SMTP)",
        "sendmail": "Hosting mail",
        "log": "Log only (testing)",
    },
    "theme": {"dark": "Dark", "light": "Light", "auto": "Follow device"},
    "performance_tier_mode": {
        "auto": "Auto - recommended",
        "rich": "Always all effects",
        "lite": "Always light",
    },
    "smtp_encryption": {"tls": "TLS", "ssl": "SSL", "": "None"},
    # Money > Payment setup (PAYMENT_KEY_SETTINGS): the .env value PAYPAL_MODE.
    "PAYPAL_MODE": {"sandbox": "Sandbox (testing)", "live": "Live (real payments)"},
}
OPTION_HELP = {
    "mail_mailer": {
        "smtp": "Your email provider sends them: Gmail, Zoho, Brevo or the mailbox from your host.",
        "sendmail": "Your hosting sends them. Easy to set up, but emails often land in spam.",
        "log": "Nothing is delivered. Emails are written to a log file.",
    },
}
MORE_OPTIONS = {
    # "Other services" select next to the radio cards.
    "mail_mailer": {
        "ses": "Amazon SES",
        "postmark": "Postmark",
        "resend": "Resend",
        "mailgun": "Mailgun",
        "failover": "Failover (several services)",
        "roundrobin": "Round robin (several services)",
    },
}

# Cards placed on another page than the spec says, by a later decision: (page, card) -> (page, card), placed after a
# card of the new page. OD3 (2026-10-18): the announcement bar sits above the header of every page, so it is edited,
# previewed and published with the header on Appearance > Header, menus & footer (Community > Announcements only
# manages the dated notices). Config, the Ctrl+K rows and the pages then agree.
MOVED_CARDS = [
    {"from": ("community.announcements", "bar"), "to": ("appearance.menus", "announcement"), "after": "header"},
]

# Setting texts and defaults corrected after the spec was written: key -> {field: (spec value it replaces, new
# value)}. The spec stays as it was reviewed; apply_setting_fixes() puts the new value in before the config and the
# Ctrl+K rows are built, and stops when the spec holds neither value (the spec was edited: check the fix again).
SETTING_FIXES = {
    # 1.2.3 member redesign (member design 6.19, QA channel-11): a channel's accent follows the look until the creator
    # picks one. New installs start with the look colour; a site that has "coral" stored keeps it (never rewritten).
    "channel_default_accent": {
        "default": ("coral", "look"),
    },
    # 1.2.3: hidden (LEVEL_FIXES): a channel page shows each playlist as one card, so nothing reads these two sizes
    "channel_default_playlist_video_columns": {
        "level_reason": (None, "No code reads it since 1.2.3: the channel page shows each playlist as one card. Stored values stay."),
    },
    "channel_default_playlist_video_rows": {
        "level_reason": (None, "No code reads it since 1.2.3: the channel page shows each playlist as one card. Stored values stay."),
    },
    # 1.2.3 (QA channel-18): the switch lets creators show "Last upload: today" in their channel's facts line (the
    # creator's own switch is "Last upload"); off, no channel shows it. It never changed an upload schedule.
    "channel_customization_upload_rhythm_enabled": {
        "label": ("Creators can change the upload schedule", "Creators can show \"Last upload\""),
        "help": (
            "Lets creators change the upload rhythm of their own channel page. Off keeps your site design on every channel.",
            "Lets creators show \"Last upload: today\" on their channel page. Off hides it on every channel.",
        ),
    },
    # 1.2.3 (lane AD pass 2, review AD-R8): "the own accent colour" is no English; the label of the Accent colour list
    # R3-078 corrected. The 1.2.2 label stays a search word of the setting (--former-122, R3-071).
    "channel_customization_custom_accent_enabled": {
        "label": ("Creators can change the own accent colour", "Creators can change their own accent colour"),
    },
    # 1.2.1: the phone bottom bar (resources/views/components/layout.blade.php, avd-mobile-bottom-nav) shows Home,
    # Categories and Random (Explore when the Random video button is off), then Login and Register for visitors or
    # Library and Profile for members. It never had Trending, Upload or Account buttons.
    "mobile_bottom_nav_enabled": {
        "help": (
            "Home, Trending, Upload and Account buttons at the bottom of the phone screen.",
            "Home, Categories and Random at the bottom of the phone screen, then Login and Register for visitors or "
            "Library and Profile for members. Explore replaces Random when the Random video button is off.",
        ),
    },
    # 1.2.0 rename (AVDBClip -> AVDBCMS): the shipped config has the new footer line; old sites keep their stored
    # line and AppearanceSettings::RENAMED_FOOTER_TEXT shows the new one for the old factory line.
    "footer_text": {
        "default": ("(c) 2026 AVDBClip. All rights reserved.", "(c) 2026 AVDBCMS. All rights reserved."),
    },
    # 1.2.3: the public AVDBAPI Telegram group of 1.2.2 is gone. The Support column links the new (private) group
    # and personal support, as App\Support\FooterSettings::defaults() does (addresses: App\Support\TelegramLinks);
    # migration 2026_10_26_200000_new_telegram_links gives an existing site's stored list the same change.
    "footer_support_links": {
        "default": (
            "Contact|/contact\nTelegram Support|https://t.me/avdb_api\nTerms of Service|/terms",
            "Contact|/contact\nTelegram group|https://t.me/+jj6McO-iYfA0MTM1\n"
            "Telegram support|https://t.me/avdbapi_support\nTerms of Service|/terms",
        ),
    },
    # 1.2.2: help texts checked against what the code does.
    # allow_uploads: VideoPolicy::upload() refuses every upload while it is off (administrators too) and the upload
    # window says so; the header Upload button follows header_upload_button_enabled only.
    "allow_uploads": {
        "help": (
            "Shows the Upload button to signed-in members. Your own imports are not affected.",
            "When off, nobody can upload, administrators too: the upload window says uploads are disabled. The Upload "
            "button stays until you hide it in Appearance > Header, menus & footer. Imports are not affected.",
        ),
    },
    "header_upload_button_enabled": {
        "help": (
            "For signed-in members who may upload.",
            "Shown to every signed-in member, also while uploads are switched off.",
        ),
    },
    # upload_rate_limit: the 'upload' rate limiter (RouteServiceProvider) counts every request of the upload API per
    # member: start (config), the status checks while the video is processed, the finish and, when the site's own
    # server stores the file, each chunk. Too many answer 429 "Too many attempts !" until the minute is over.
    "upload_rate_limit": {
        "help": (
            "How many uploads one member may start per minute.",
            "How many requests one member's uploads may send to your site per minute: the start, the checks while "
            "the video is processed, the finish, and each piece of the file when your server stores it. More are "
            "refused until the minute is over.",
        ),
    },
    # ad_preroll_duration: Ad.tsx plays the video ad for this many seconds (1-60) and then starts the video; a shorter
    # ad video ends the break when it ends; the Skip button works after min(3, seconds - 1) seconds.
    "ad_preroll_duration": {
        "label": ("Skip button after", "Ad break length"),
        "help": (
            "Seconds before viewers can skip the video ad.",
            "How long the video ad plays before the video, from 1 to 60 seconds. A shorter ad video ends sooner. "
            "Viewers can skip it after 3 seconds.",
        ),
        "control": ("number", 'number + "seconds" suffix'),
    },
    # The plan limits are shown on Money > Premium plans. VideoPolicy::upload(): a free member is stopped at
    # free_max_uploads videos (0 stops every upload), free_max_storage and the plan's largest file; a premium member
    # only by premium_max_file_size (the premium count and space are shown on the Premium page, never enforced). The
    # sizes are stored in bytes and typed in MB or GB (control "size").
    "free_max_uploads": {
        "help": ("0 = no limit.", "How many videos one free member may upload. 0 = free members cannot upload."),
    },
    "free_max_file_size": {
        "help": ("In MB.", "The biggest video file a free member may upload."),
        "control": ("number", "size (MB or GB)"),
    },
    "free_max_storage": {
        "help": ("In MB.", "All the videos of one free member together."),
        "control": ("number", "size (MB or GB)"),
    },
    "premium_max_uploads": {
        "help": (
            "Leave empty for no limit.",
            "Only shown on the Premium page: premium members can always upload more. Empty or 0 shows Unlimited.",
        ),
    },
    "premium_max_file_size": {
        "help": ("In MB.", "The biggest video file a premium member may upload."),
        "control": ("number", "size (MB or GB)"),
    },
    "premium_max_storage": {
        "help": (
            "In MB. Leave empty for no limit.",
            "Only shown on the Premium page: premium members are never stopped by total space. Empty or 0 shows "
            "Unlimited.",
        ),
        "control": ("number", "size (MB or GB)"),
    },
    # trial_period_days: PremiumController::subscribe() gives it to Stripe as trial_period_days for a member without
    # a premium subscription; PayPal checkouts have no trial.
    "trial_period_days": {
        "help": (
            "Days of free premium for plans that include a trial.",
            "Free premium days before the first card payment, for members who never had premium. 0 = no trial.",
        ),
    },
    # 1.2.3: the texts of the settings the new admin shows again, checked against what the code does.
    # global_ip_blacklist (Members > Blocked addresses, card "Blocked ranges"): App\Support\BlockedAddresses checks every
    # line as an address or a CIDR range (IPv4 and IPv6) through Security; the save refuses a line that is neither and a
    # list that holds the saving admin's own address (App\Rules\BlockedAddressList); the sign-in page stays open and a
    # signed-in administrator is never blocked, so an owner cannot lock themselves out.
    "global_ip_blacklist": {
        "help": (
            "One address or range per line. These visitors cannot open your site at all.",
            "One address (203.0.113.7) or range (203.0.113.0/24) per line. These visitors cannot open your site. "
            "Administrators can always sign in, so you cannot lock yourself out.",
        ),
        "confirm": (
            "Block these addresses? Anyone using them will not be able to open your site at all.",
            "Block these addresses? Anyone using them will no longer be able to open your site.",
        ),
    },
    # 1.2.3 round 1 (R1-159): the brand is written PayPal (App\Support\PaymentProviders), here too.
    "premium_paypal_label": {
        "label": ("Premium page: Paypal text", "Premium page: PayPal text"),
    },
    # rtl_support: the layout (components/layout.blade.php) and the admin (AdminTheme::direction()) read right to left
    # when it is on, in EVERY language; Arabic, Persian, Hebrew and Urdu do so by themselves (config/languages.php rtl).
    # So it is no "turn on for Arabic" switch: it forces right to left everywhere (LEVEL_FIXES: under More options).
    "rtl_support": {
        "label": ("Right-to-left languages", "Show every language right to left"),
        "help": (
            "Turn on if you use Arabic, Hebrew, Persian or Urdu so the layout flips correctly.",
            "Arabic, Persian, Hebrew and Urdu already show right to left by themselves. Turn this on only if every "
            "page, the admin too, should read right to left.",
        ),
    },
    # cors_allowed_origins: App\Support\CorsConfig turns "*" into this site's own address while signed-in requests from
    # other sites are allowed (cors_supports_credentials, on by default); only with that switch off is "*" any site.
    "cors_allowed_origins": {
        "help": (
            "Other websites allowed to load data from your site. * = any site.",
            "Other websites that may load data from your site, separated by commas. * means any site while signed-in "
            "requests from other sites are off, and only your own site while they are on.",
        ),
    },
    # cors_max_age is required by the save; an empty default (the spec's) showed an empty field that a page posted
    # without JavaScript could not save. 0 is what config/cors.php uses when nothing is stored.
    "cors_max_age": {
        "default": (None, "'0'"),
    },
    # terms_content: pages/terms.blade.php prints it as HTML, and shows the standard terms while it is empty.
    "terms_content": {
        "help": (
            "The text of your Terms page. You can use simple formatting.",
            "The text of your Terms page, in HTML (for example <p>...</p> around each paragraph). Leave it empty to show "
            "the standard terms.",
        ),
    },
    # 1.2.3 (OD-M7, account-6): members have no download feature (videos play through the Upload18
    # embed), so the Premium page's "Downloads" feature box is off for new sites; an owner who switched it on keeps it
    # (App\Support\PremiumPageSettings has the same default; a stored value is never changed).
    "premium_feature_downloads_enabled": {
        "default": ("1", "0"),
        "help": (
            "Shows feature box: downloads when on. Turn it off to hide it for every visitor.",
            "Off for new sites: this site has no download button for members. Turn it on only if your members really "
            "get downloads.",
        ),
    },
    # 1.2.3 (member design 6.0): the Premium page is drawn by the member pages' own design now, so
    # the "Info pages" switches no longer change it (contact, terms and the other info pages keep them).
    "secondary_page_design_enabled": {
        "help": (
            "Contact, Premium, Terms and similar pages use the theme design.",
            "Contact, Terms and similar pages use the theme design.",
        ),
    },
    "secondary_page_table_polish_enabled": {
        "help": (
            "Theme-styled tables, for example plan comparisons.",
            "Theme-styled tables on these pages.",
        ),
    },
    # Hidden settings are only seen in the search results: plain labels and help (the hint says where they are now).
    "font_family": {
        "help": (
            "Has no effect: the Fonts choice above decides the fonts. Hidden in the new admin.",
            "An old setting: the Fonts choice decides the fonts now.",
        ),
    },
    "video_card_progress_enabled": {
        "help": (
            "Not connected yet: no page reads it. Hidden until it works.",
            "Not in use: this setting has no effect on your site yet.",
        ),
    },
    "mobile_tap_preview_enabled": {
        "help": (
            "Not connected yet: no page reads it. Hidden until it works.",
            "Not in use: this setting has no effect on your site yet.",
        ),
    },
    "mobile_bottom_dock_enabled": {
        "label": ("(old copy of \"Bottom bar on phones\")", "Bottom bar on phones (old copy)"),
        "help": (
            "Kept in step automatically. Not shown.",
            "An old copy of \"Bottom bar on phones\": it always follows that setting.",
        ),
    },
    # After 1.2.2: the list ad of "new_videos" is the main list of the Classic grid homepage layout only; the homepage layouts
    # with a top banner and one feed use the homepage feed's, which takes the "main homepage list" labels (ADDED_SETTINGS,
    # App\Support\ListAdContexts).
    "ad_grid_layout_new_videos": {
        "label": ("Ad shape in the main homepage list", "Ad shape in the Classic grid homepage list"),
    },
    "ad_code_grid_new_videos": {
        "label": ("Ad code for the main homepage list", "Ad code for the Classic grid homepage list"),
        "help": (
            "Leave empty to use no ad there.",
            "Used by the Classic grid homepage layout. Leave empty to use no ad there.",
        ),
    },
    # 1.2.3 (R1-114): the Premium page's plan card read "Your billing starts on <date> for the month plan. Cancel
    # anytime" (no full stop, "the month plan") and the page said "Save 2 months" twice: the yearly card's own badge says
    # what the plan saves, from its real prices, so the note under the plans is empty unless the owner writes one. The
    # same texts as App\Support\PremiumPageSettings::defaults(); migration 2026_10_27_130000 gives a stored value that is
    # exactly the old text the new one (RETIRED_DEFAULTS).
    "premium_billing_trial_summary": {
        "default": ("Your billing starts on :date for the :period plan.", "Your billing starts on :date."),
    },
    "premium_billing_without_trial_summary": {
        "default": ("Your billing starts today for the :period plan.", "Your billing starts today."),
    },
    "premium_cancel_anytime_label": {
        "default": ("Cancel anytime", "Cancel anytime."),
    },
    "premium_annual_savings_text": {
        "default": ("Save 2 months with the annual subscription", ""),
    },
    # 1.2.3 (R1-123): "Ads on videos" shows the benefit: a plan without ads reads "No ads" with a tick, a plan with ads a
    # plain "Yes" (resources/views/pages/premium.blade.php), so these two switches no longer put a tick in the column.
    "premium_comparison_ads_free_enabled": {
        "help": ("Tick shown in the Free column.",
                 "On: the Free plan column says \"Yes\". Off: it shows a tick and \"No ads\"."),
    },
    "premium_comparison_ads_premium_enabled": {
        "help": ("Tick shown in the Premium column.",
                 "On: the Premium plan column says \"Yes\". Off: it shows a tick and \"No ads\"."),
    },
    # 1.2.3 (R2R-09): the header's "Premium button" is no button. It is a small badge inside the site's name link, shown
    # only to Premium members (resources/views/layouts/menus/headers/brand.blade.php; the same in 1.2.2), only where
    # the header has room for the name and the badge (from 660px, from 992px in Imperial Salon's crest bar:
    # resources/sass/header.scss), and a click on it opens the home page like the name: it never opened the Premium page.
    # The 1.2.0-1.2.2 labels stay search words (FORMER_LABELS).
    "header_premium_chip_enabled": {
        "label": ("Premium button", "Premium badge in the header"),
        "help": (
            "A small button that opens the Premium page.",
            "Shows a small Premium badge next to the site's name for Premium members, on screens wide enough for both.",
        ),
    },
    "header_premium_label": {
        "label": ("Premium button text", "Premium badge text"),
        "help": (
            "The words shown for premium button text. Leave empty to use the default text (translated automatically).",
            "The words of the Premium badge. Leave empty to use the default text (translated automatically).",
        ),
    },
    # 1.2.3 (owner: "make sure in admin setting have docs or note for user know how to get new key from they
    # account upload18"): which key to use. Members' browsers only get short-lived upload tokens made with it
    # (App\Support\Upload18\BrowserUploadKey); the card checks what the saved key may do (Upload18 GET /keyinfo), its
    # note (CARD_FIXES) says how to make one, and the link beside its title opens upload18.org/info/api.
    "upload18_api_key": {
        "help": (
            "Connects member uploads to your Upload18 account. Keep it private.",
            "Use a restricted key with Upload + Read made for this site in Upload18 > API > Keys & security, "
            "never your master key. Keep it private.",
        ),
    },
}

# Spec defaults that are PHP expressions of the old settings view, written out.
DEFAULT_LITERALS = {
    "$uploadAllowedFormatsDefault": "mp4,webm,avi,mov,mkv",
    "\\App\\Enums\\VideoStatus::PUBLIC->value": "0",
    "$viewHistoryDefaults['retention_days'] ?? 90": "90",
}

# Levels decided after the spec was written (basic, advanced, expert = Developer mode only, hidden): setting key or
# (page id, card id) -> (spec level it replaces, new level), applied like SETTING_FIXES. 1.2.3 (owner: "in admin setting
# not see setting api key for upload18.org"): the Upload18 connection is an everyday setting of the owner, not a
# Developer mode one: Settings > Videos & uploads shows the card under "Advanced" without Developer mode, and the key
# stays a write-only secret (Saved / Replace / Remove, never shown back). Its texts (which key to use, how to make one)
# come with 1.2.3.
LEVEL_FIXES = {
    ("settings.videos", "upload18"): ("expert", "advanced"),
    "upload18_api_key": ("expert", "advanced"),
    "upload18_api_url": ("expert", "advanced"),
    "upload18_default_cid": ("expert", "advanced"),
    "upload18_default_fid": ("expert", "advanced"),
    "upload18_default_mycid": ("expert", "advanced"),
    "upload18_chunk_size_mb": ("expert", "advanced"),
    "upload18_thumbnail_count": ("expert", "advanced"),
    # "Show every language right to left" (SETTING_FIXES rtl_support) is rarely wanted: under More options.
    "rtl_support": ("basic", "advanced"),
    # 1.2.3: a channel page shows each playlist as one card, so the sizes of a playlist's own video grid change nothing
    # (the creator's form no longer offers them either). Hidden; the stored values stay (HIDDEN_TARGETS says why).
    "channel_default_playlist_video_columns": ("advanced", "hidden"),
    "channel_default_playlist_video_rows": ("advanced", "hidden"),
}


# Card texts corrected after the spec was written: (page id, card id) -> {field: (spec value it replaces, new value)}.
CARD_FIXES = {
    # 1.2.2: the trial is not a plan option: Stripe checkouts of members who never had premium start with it.
    ("money.plans", "trial"): {
        "description": ("For plans that include a trial.", "Free premium days for new members who pay by card."),
    },
    # After 1.2.2: there is no main list ad code: a list whose own code is empty shows no ad (only the shape has "Same as
    # above"), so "Empty = use the main setting" sent owners looking for an ad that never showed.
    ("money.ads", "per_section"): {
        "description": (
            "Use a different ad or shape in each list. Empty = use the main setting.",
            "Each list has its own ad code and shape. An empty ad code shows no ad in that list.",
        ),
    },
    # 1.2.3: every owner sees the Upload18 card now (LEVEL_FIXES), and should paste a key of their own (members'
    # browsers only get upload tokens; the key help says which key, the link beside the title how to make one).
    # Round 1 (R1-153): the menu path holds Upload18's own screen names only (the steps on it follow in words), so a
    # right-to-left page can keep it in one piece (App\Support\AdminText::isolatePaths()).
    ("settings.videos", "upload18"): {
        "description": (
            "The service that stores member uploads. Only change these with Upload18 support.",
            "New sites start with a shared AVDBCMS key, so uploads work at once. For your own site, make your own key: "
            "open upload18.org > API > Keys & security > Restricted keys, tick Upload and Read, click Create key and "
            "paste the key here.",
        ),
    },
    # 1.2.3: the Premium page follows the member pages' design, not the "Info pages" switches.
    ("appearance.other_pages", "info_pages"): {
        "description": (
            "Contact, Premium, Terms and other simple pages.",
            "Contact, Terms and similar pages.",
        ),
    },
    # 1.2.3 (R2R-09): the card also holds the words of the header's Premium badge, which is no button.
    ("appearance.menus", "header_text"): {
        "description": (
            "Change the words on the header buttons.",
            "Change the words on the header buttons and on the Premium badge.",
        ),
    },
    # 1.2.3: the list takes addresses and CIDR ranges (App\Support\BlockedAddresses), not "whole ranges" of any form.
    ("members.blocked", "ranges"): {
        "description": (
            "Whole ranges of addresses, one per line.",
            "Block whole ranges of addresses at once, such as 203.0.113.0/24.",
        ),
    },
}

# Settings set by a gallery or picker instead of a row of their own: the search sends them to the page part that sets
# them; "marker" is a piece of that page's HTML that tests/Feature/AdminSettingsCoverageTest.php looks for.
SETTING_LINKS = {
    "theme_preset": {"link": "/admin/appearance?tab=themes", "marker": "data-ad-themes"},
    "theme_flavor": {"link": "/admin/appearance?tab=themes", "marker": "data-ad-themes"},
}

# Hidden settings (no row: level "hidden" with its level_reason) say in the search where they are changed now: "see"
# (the setting that replaced it, found on its page), "link" (a page part, with its marker) or "card" (the card the
# setting belongs to, #card-<id> of its page), and a one-line "hint".
HIDDEN_TARGETS = {
    "active_master_experience": {
        "link": "/admin/appearance?tab=looks",
        "marker": "data-ad-look=",
        "hint": "Set when you publish a complete look.",
    },
    "font_family": {
        "see": "typography_pairing",
        "hint": "An old setting: the Fonts choice decides the fonts now.",
    },
    "video_card_progress_enabled": {
        "card": True,
        "hint": "Not in use: this setting has no effect on your site yet.",
    },
    "mobile_tap_preview_enabled": {
        "card": True,
        "hint": "Not in use: this setting has no effect on your site yet.",
    },
    "mobile_bottom_dock_enabled": {
        "see": "mobile_bottom_nav_enabled",
        "hint": "An old copy of \"Bottom bar on phones\": it always follows that setting.",
    },
    "channel_default_playlist_video_columns": {
        "card": True,
        "hint": "Not in use: a channel page shows each playlist as one card.",
    },
    "channel_default_playlist_video_rows": {
        "card": True,
        "hint": "Not in use: a channel page shows each playlist as one card.",
    },
}

# Words owners search with that no label holds, added after the spec: term -> setting keys / page ids (merged into the
# spec's search_synonyms). The Upload18 key under the names owners use for it. A page named here lists all its settings
# in both searches (App\Support\AdminSettingsSearch), so "upload18" names the key only: the other settings of the
# Upload18 card hold the word themselves.
ADDED_SYNONYMS = {
    "invoice": ["invoice_seller_name", "invoice_seller_address", "invoice_seller_tax_id", "invoice_tax_label", "invoice_tax_rate"],
    "vat": ["invoice_seller_tax_id", "invoice_tax_label", "invoice_tax_rate"],
    "company": ["invoice_seller_name", "invoice_seller_tax_id"],
    "upload18": ["upload18_api_key"],
    "upload key": ["upload18_api_key"],
    "api key": ["upload18_api_key"],
    "upload18 key": ["upload18_api_key"],
    # 1.2.3 round 1 (R1-155): "premium" opens the switch that sells Premium first in both searches (a dozen labels start
    # with "Premium page: ...").
    "premium": ["premium_enabled"],
}

# Labels the new admin of 1.2.0-1.2.2 showed for a setting that has another label now (1.2.3, R1R-18), in each language of
# the admin as it showed them (the lang/<locale>.json of those packages): an owner who searches with the name they knew
# still finds the setting, first, in both searches. Each one becomes a search word of its setting (merged into the
# synonyms like ADDED_SYNONYMS; the search folds case, accents, "-" and "_"), as shown and as typed without its final
# full stop and quote marks (former_terms()). These are words to search with, never shown: no translation keys, and the
# only non-English words of this file. Found by comparing the settings-index.json of the 1.2.0, 1.2.1 and 1.2.2 packages
# with this version's labels (tools of lane BU, round 1b). Not here: "Ad shape in the main homepage list" and "Ad code
# for the main homepage list" (1.2.2 names of ad_grid_layout_new_videos and ad_code_grid_new_videos), which are the
# labels of their successors ad_grid_layout_home_feed and ad_code_grid_home_feed on purpose (ADDED_SETTINGS): the search
# finds those by their label. build() refuses a former label that is a setting's label now, and one that misses a
# language of the admin.
FORMER_LABELS = {
    # 1.2.0-1.2.2; now: Creators can show "Last upload" (SETTING_FIXES).
    "channel_customization_upload_rhythm_enabled": {
        "en": "Creators can change the upload schedule",
        "ar": "يمكن لمنشئي المحتوى تغيير جدول التحميل",
        "de": "Ersteller können den Upload-Zeitplan ändern",
        "es": "Las creadoras pueden cambiar el horario de carga.",
        "fil": "Maaaring baguhin ng mga creator ang iskedyul ng pag-upload",
        "fr": "Les créateurs peuvent modifier le calendrier de mise en ligne",
        "hi": "क्रिएटर्स अपलोड शेड्यूल बदल सकते हैं",
        "id": "Kreator dapat mengubah jadwal upload",
        "ja": "クリエイターはアップロードスケジュールを変更できます",
        "ko": "크리에이터는 업로드 일정을 변경할 수 있습니다.",
        "ms": "Pencipta boleh menukar jadual muat naik",
        "pt": "Os criadores podem alterar a programação de upload",
        "ru": "Авторы могут изменить расписание загрузки.",
        "th": "ครีเอเตอร์สามารถเปลี่ยนกำหนดการอัปโหลดได้",
        "tl": "Maaaring baguhin ng mga creator ang iskedyul ng pag-upload",
        "tr": "İçerik oluşturucular yükleme planını değiştirebilir",
        "vi": "Nhà sáng tạo được đổi lịch tải lên",
        "zh": "创作者可以更改上传时间表",
    },
    # 1.2.0-1.2.2; now: Show every language right to left (SETTING_FIXES).
    "rtl_support": {
        "en": "Right-to-left languages",
        "ar": "اللغات التي تكتب من اليمين إلى اليسار",
        "de": "Von rechts nach links geschriebene Sprachen",
        "es": "Idiomas de derecha a izquierda",
        "fil": "Kanan-papuntang-kaliwa na mga wika",
        "fr": "Langues de droite à gauche",
        "hi": "दाएं से बाएं भाषाएं",
        "id": "Bahasa kanan ke kiri",
        "ja": "右から左に記述する言語",
        "ko": "오른쪽에서 왼쪽으로 쓰는 언어",
        "ms": "Bahasa kanan ke kiri",
        "pt": "Idiomas da direita para a esquerda",
        "ru": "Языки с письмом справа налево",
        "th": "ภาษาจากขวาไปซ้าย",
        "tl": "Kanan-papuntang-kaliwa na mga wika",
        "tr": "Sağdan sola diller",
        "vi": "Ngôn ngữ viết từ phải sang trái",
        "zh": "从右到左的语言",
    },
    # 1.2.0-1.2.2 (the same in the three packages); now: Premium badge in the header (SETTING_FIXES, R2R-09).
    "header_premium_chip_enabled": {
        "en": "Premium button",
        "ar": "زر مميز",
        "de": "Premium-Taste",
        "es": "Botón premium",
        "fil": "Button ng premium",
        "fr": "Bouton Premium",
        "hi": "प्रीमियम बटन",
        "id": "Tombol premium",
        "ja": "プレミアムボタン",
        "ko": "프리미엄 버튼",
        "ms": "Butang premium",
        "pt": "Botão premium",
        "ru": "Премиум-кнопка",
        "th": "ปุ่มพรีเมี่ยม",
        "tl": "Button ng premium",
        "tr": "Premium düğmesi",
        "vi": "Nút Premium",
        "zh": "高级按钮",
    },
    # 1.2.0-1.2.2 (the same in the three packages); now: Premium badge text (SETTING_FIXES, R2R-09).
    "header_premium_label": {
        "en": "Premium button text",
        "ar": "نص الزر المميز",
        "de": "Premium-Schaltflächentext",
        "es": "Texto del botón premium",
        "fil": "Teksto ng premium na button",
        "fr": "Texte du bouton Premium",
        "hi": "प्रीमियम बटन टेक्स्ट",
        "id": "Teks tombol premium",
        "ja": "プレミアムボタンのテキスト",
        "ko": "프리미엄 버튼 텍스트",
        "ms": "Teks butang premium",
        "pt": "Texto do botão premium",
        "ru": "Текст кнопки премиум-класса",
        "th": "ข้อความปุ่มพรีเมี่ยม",
        "tl": "Teksto ng premium na button",
        "tr": "Premium düğme metni",
        "vi": "Chữ trên nút Premium",
        "zh": "高级按钮文本",
    },
    # 1.2.0-1.2.1; Ad break length since 1.2.2 (SETTING_FIXES).
    "ad_preroll_duration": {
        "en": "Skip button after",
        "ar": "زر التخطي بعد",
        "de": "Schaltfläche „Überspringen“ danach",
        "es": "Botón saltar después",
        "fil": "Skip button pagkatapos",
        "fr": "Bouton Passer après",
        "hi": "बाद में छोड़ें बटन",
        "id": "Lewati tombol setelahnya",
        "ja": "次のスキップボタン",
        "ko": "다음 이후에는 건너뛰기 버튼",
        "ms": "Butang langkau selepas",
        "pt": "Botão pular depois",
        "ru": "Кнопка «Пропустить» после",
        "th": "ปุ่มข้ามตามหลัง",
        "tl": "Skip button pagkatapos",
        "tr": "Şundan sonra atla düğmesi",
        "vi": "Nút bỏ qua sau",
        "zh": "之后跳过按钮",
    },
}

# The name the old settings page of 1.2.0-1.2.2 gave a setting (its 'old'), as those packages showed it in the admin's
# other languages, where 1.2.3 words that name differently (R1-124: "Upload Rhythm" was translated as "upload a rhythm";
# 1.2.3 shows the corrected name, and the English one is the setting's 'old' itself). Search words of their setting like
# FORMER_LABELS (as shown and as typed: former_terms()), never shown: an owner who searches with the name their admin
# showed still finds the setting, first. build() refuses a setting without an old name, a language the admin does not
# have and a search word of another setting.
FORMER_OLD_NAMES = {
    # 1.2.0-1.2.2 (lang/<locale>.json of the three packages, all the same); now: Creators can show "Last upload".
    "channel_customization_upload_rhythm_enabled": {
        "ar": "تحميل الإيقاع",
        "de": "Rhythmus hochladen",
        "es": "Subir ritmo",
        "fil": "Mag-upload ng Rhythm",
        "fr": "Rythme de téléchargement",
        "hi": "लय अपलोड करें",
        "id": "Unggah Irama",
        "ja": "アップロードリズム",
        "ko": "리듬 업로드",
        "ms": "Muat naik Irama",
        "pt": "Carregar ritmo",
        "ru": "Загрузить ритм",
        "th": "อัพโหลด ริธึม",
        "tl": "Mag-upload ng Rhythm",
        "tr": "Ritim Yükle",
        "vi": "Tải lên nhịp điệu",
        "zh": "上传节奏",
    },
}

# The languages the admin's texts are translated into (lang/<locale>.json besides en.json): FORMER_LABELS holds a label for
# each of them and for English.
ADMIN_LOCALES = ["ar", "de", "es", "fil", "fr", "hi", "id", "ja", "ko", "ms", "pt", "ru", "th", "tl", "tr", "vi", "zh"]


def former_terms(text):
    """The search words of a former label: as it was shown, and as an owner types it, without its final full stop and
    without quote marks ("Schaltfläche „Überspringen“ danach" is also "schaltfläche überspringen danach")."""
    shown = re.sub(r"\s+", " ", text).strip().lower()
    typed = re.sub(r"\s+", " ", re.sub(r"[\"'«»„“”‘’「」『』]", "", shown)).strip().rstrip(".。．")
    return [term for term in dict.fromkeys([shown, typed]) if term]


# Settings the code reads or stores that have no admin field on purpose, with the reason (for maintainers: these
# reasons are not shown in the admin, so they are no translation keys). "site.<name>" entries are config('site.<name>')
# values that are no setting of their own. tests/Feature/AdminSettingsCoverageTest.php fails for a stored, saved or
# read setting that is neither in the catalogue nor here.
NO_FIELD = {
    # Theme catalogue v2 bookkeeping (SettingsController::NOT_SAVED_BY_PAGES): written by the upgrade only.
    "appearance_schema_version": "Internal: the version of the appearance data, written by the theme catalogue upgrade.",
    "theme_preset_legacy": "Internal: the old theme a site came from, kept by the theme catalogue upgrade.",
    "suggested_master_upgrade": "Internal: the look the theme catalogue upgrade suggests once.",
    # Values derived from a setting that has a field.
    "site.admin_email": "Derived: the Contact email (contact_email).",
    "site.maintenance_mode": "Derived: Maintenance mode (maintenance_mode_enabled).",
    "site.robots_txt_custom": "Derived: Custom robots.txt (robots_txt).",
    "site.theme_id": "Derived: the theme chosen in Appearance (theme_preset).",
    "site.upload_chunk_size": "Derived: Upload piece size (upload18_chunk_size_mb).",
    "site.thumbnails_count": "Derived: Thumbnails made per upload (upload18_thumbnail_count).",
    # Found by the site itself; an admin field could only break them.
    "site.trusted_proxies": "Automatic: this server, private networks and Cloudflare are trusted by themselves; a "
                            "server owner adds others in the .env file (TRUSTED_PROXIES, Developer mode > Developer "
                            "zone). A wrong list in the admin could lock owners out or let visitors fake their address.",
    "site.panel": "Automatic: the hosting panel found at start (Tools > Health shows it).",
    "site.web_server": "Automatic: the web server found at start (Tools > Health shows it).",
    "site.environment_adjustments": "Automatic: what the site adjusted for this server at start (Tools > Health).",
    "site.stream_accelerator": "Automatic: found for the web server; a server owner can set STREAM_ACCELERATOR in .env.",
    "site.stream_accelerator_prefix": "Internal: the internal address prefix of the stream accelerator.",
    "site.avdb_import_php_binary": "Automatic: the PHP program scheduled imports run with.",
    "site.xvid_import_php_binary": "Automatic: the PHP program scheduled imports run with.",
    "site.avdb_import_queue": "Internal: the queue name imports use.",
    "site.avdb_api_base_url": "Fixed: the address of the AVDBAPI service.",
    "site.xvid_api_base_url": "Fixed: the address of the XVIDAPI service.",
    "site.feed_cache_ttl": "Internal: how long video lists are cached (30 seconds).",
    "site.sidebar_playlists_fetch_limit": "Internal: how many playlists the side menu loads at most.",
    "site.sidebar_subscriptions_fetch_limit": "Internal: how many subscriptions the side menu loads at most.",
    # 1.2.3 (R3X-01): taken out of the admin (REMOVED_SETTINGS); older sites and example.sql may still store it.
    "show_premium_badge": "Not in use: 1.2.0-1.2.3 saved and loaded it, nothing ever showed a badge for it. The badge "
                          "Premium members see is the header's (header_premium_chip_enabled); the old names find that one.",
}

# Settings of the spec that no release ever read, taken out of the admin (1.2.3, R3X-01: "never leave a switch that does
# nothing"): key -> the setting an owner who searches with its old names finds instead. Their 1.2.0-1.2.2 names, in
# every language of the admin, stay search words of that setting (FORMER_NAMES_FILE), and NO_FIELD says why the value an
# older site may still store is read by nothing. apply_removed_settings() takes them out of the spec in memory.
REMOVED_SETTINGS = {
    # Money > Payment setup "Premium badge on members" (the old settings page's "Show Premium Badge"): 1.2.0-1.2.3 saved
    # and loaded it (config site.show_premium_badge) and nothing read it, while the help site said it shows a badge next
    # to paying members. The badge Premium members do see is the header's (Premium badge in the header).
    "show_premium_badge": "header_premium_chip_enabled",
}

# R3-071: the names the admin of 1.2.2 showed for a setting in each of its languages (its label, and its name on the old
# settings page) where 1.2.3 shows another one: 1.2.3 corrected many translations (Arabic 69 settings, German 48 ...),
# and only the English renames were search words (FORMER_LABELS). Each such name is a search word of its setting in its
# own language (config 'former_names': locale => search word => setting keys; App\Support\AdminSearchIndex::synonyms()
# adds those of the admin's language, and the English ones in every language), so an owner who searches with the name
# they knew still finds the setting, first, in both searches. The file is made from the 1.2.2 package by
#   python scripts/admin_settings/generate_config.py --former-122 <the 1.2.2 tree>
# (its resources/admin/lib/settings-index.json and lang/<locale>.json, against this tree's current language files): run
# that again whenever the language files change a setting's name (the merge of a translation pass does), then --check.
FORMER_NAMES_FILE = os.path.join(ROOT, "scripts", "admin_settings", "former_names_122.json")

# The tabs of the old settings page (resources/views/admin/settings/index.blade.php), with the names it showed, for
# "What moved where". A tab whose settings all moved to one page that is not a settings page names it ("page").
OLD_TABS = {
    "general": {"label": "General"},
    "appearance": {"label": "Appearance"},
    "discovery": {"label": "Discovery"},
    "uploads": {"label": "Uploads"},
    "notifications": {"label": "Notifications"},
    "email": {"label": "Email"},
    "comments": {"label": "Comments"},
    "users": {"label": "Users"},
    "monetization": {"label": "Monetization"},
    "seo": {"label": "SEO"},
    "pages": {"label": "Pages"},
    "security": {"label": "Security"},
    "localization": {"label": "Localization"},
    "validation": {"label": "Validation"},
    "planlimits": {"label": "Plans"},
    "api": {"label": "API"},
    "sociallinks": {"label": "Social"},
    "maintenance": {"label": "Maintenance"},
    "system": {"label": "System", "page": "tools.health"},
}


# Cards added after the spec was written: (page id) gets the card right after the card named by "after". Its settings
# come from ADDED_SETTINGS ("new_card": True on the first one, which follows the last setting of the card before it).
ADDED_CARDS = [
    # 1.2.3 (account-1, OD-M8): the seller on the invoice PDF of a Premium payment. Before, every site
    # printed a French company (its SIRET and VAT numbers) and a fixed 20% tax line. Until the owner fills these in, an
    # invoice names the site only and has no tax line (App\Services\InvoiceService).
    {
        "page": "money.setup",
        "after": "methods",
        "card": {
            "id": "invoices",
            "title": "Invoices",
            "description": "What the invoices of Premium payments say about you, the seller. Until you fill this in, "
                           "invoices show your site name only and no tax line.",
            "level": "basic",
        },
    },
    # 1.2.3 (lane KS): the words that keep AVDBAPI and XVIDAPI items out of the site (App\Support\ContentSafety). One card
    # for both importers, the first of Content > Import ("after": None), shown on both import pages
    # (resources/views/admin/imports/blocked-words.blade.php).
    {
        "page": "content.import",
        "after": None,
        "card": {
            "id": "blocked_words",
            "title": "Blocked words",
            "description": "Used by every AVDBAPI and XVIDAPI import, the scheduled ones too. Videos already on your site "
                           "are not changed by this list.",
            "level": "basic",
        },
    },
    # 1.2.3 (the owner's request of 29 Oct, lane DW): a demo site lets each visitor try the looks in their own browser
    # (ADDED_SETTINGS visitor_look_picker_enabled), next to the Complete looks it offers. Drawn by the Themes page
    # (resources/views/admin/appearance/themes.blade.php, the Complete looks tab).
    {
        "page": "appearance.themes",
        "after": "looks",
        "card": {
            "id": "visitors",
            "title": "Visitors try the looks",
            "description": "For demo sites: a palette button where each visitor tries the looks in their own browser.",
            "level": "advanced",
        },
    },
]

# Settings added after the spec was written: spec mapping rows (the same fields as the spec's), each placed on its page
# and card right after the setting named by "after", in the card and in the setting order. apply_added_settings() puts
# them into the spec in memory before the config and the Ctrl+K rows are built; validation.settings_total counts them.
ADDED_SETTINGS = [
    # After 1.2.2: the homepage feed (home_feed), the main video list of the homepage layouts with a top banner and one
    # feed (Spotlight, the layout of a new site, Cinema rows, Mosaic, Shorts and Control room): its own list ad, the ad
    # context of that list in all five layouts (App\Support\ListAdContexts). It takes the labels "main homepage list"
    # (the admin's word for it, as on Appearance > Homepage), which name the Classic grid list from now on
    # (SETTING_FIXES), so an owner finds the code they had under the same name: the migration
    # 2026_10_25_120000_add_homepage_feed_list_ad copies it. Saved with only_when_sent rules
    # (SettingsController::settingsSchema).
    {
        "after": "ad_code_grid_new_videos",
        "setting": {
            "key": "ad_grid_layout_home_feed",
            "save_group": "monetization",
            "old": {"tab": "monetization", "section": "Feed ad slots", "label": "Homepage Feed", "help": ""},
            "new": {"section": "money", "page": "money.ads", "url": "/admin/money/ads#ad_grid_layout_home_feed",
                    "card": "per_section", "card_title": "Different ads per list"},
            "level": "advanced",
            "label": "Ad shape in the main homepage list",
            "help": "Card, wide strip, or the same as the main setting.",
            "copy_source": "rule:ad-per-section",
            "control": "segmented",
            "default": None,
            "type": "string",
        },
    },
    {
        "after": "ad_grid_layout_home_feed",
        "setting": {
            "key": "ad_code_grid_home_feed",
            "save_group": "monetization",
            "old": {"tab": "monetization", "section": "Feed ad slots", "label": "Homepage Feed", "help": ""},
            "new": {"section": "money", "page": "money.ads", "url": "/admin/money/ads#ad_code_grid_home_feed",
                    "card": "per_section", "card_title": "Different ads per list"},
            "level": "advanced",
            "label": "Ad code for the main homepage list",
            "help": "Used by the Spotlight, Cinema rows, Mosaic, Shorts and Control room homepage layouts. Leave empty "
                    "to use no ad there.",
            "copy_source": "rule:ad-per-section",
            "control": "code box (paste ad code)",
            "default": None,
            "type": "string",
        },
    },
    # 1.2.3 (an audit of the settings the code reads without an admin field). The strong-password rule of
    # RegisterRequest, ResetPasswordRequest, Profile > Password and Members > one member (config
    # site.require_strong_password, filled from security.require_strong_password by AppServiceProvider) had no switch.
    # Off by default, as before; passwords set earlier keep working. Saved with only_when_sent rules
    # (SettingsController::settingsSchema), so the old settings page never writes it.
    {
        "after": "allow_account_deletion",
        "setting": {
            "key": "require_strong_password",
            "save_group": "security",
            "old": {"tab": None, "section": None, "label": "", "help": ""},
            "new": {"section": "settings", "page": "settings.members", "url": "/admin/settings/members#require_strong_password",
                    "card": "signup", "card_title": "Sign-up & login"},
            "level": "advanced",
            "label": "Ask for strong passwords",
            "help": "New passwords need upper and lower case letters, a number and a symbol. Passwords set before "
                    "keep working.",
            "copy_source": "written",
            "control": "toggle row",
            "default": "'0'",
            "type": "bool",
        },
    },
    # 1.2.3: sign-ups through the API (Api\RegisterController: site.api_registration, filled from
    # api.api_registration) had no switch, so an app could never sign members up. Off by default, as before.
    {
        "after": "require_strong_password",
        "setting": {
            "key": "api_registration",
            "save_group": "api",
            "old": {"tab": None, "section": None, "label": "", "help": ""},
            "new": {"section": "settings", "page": "settings.members", "url": "/admin/settings/members#api_registration",
                    "card": "signup", "card_title": "Sign-up & login"},
            "level": "advanced",
            "label": "Sign-ups from apps",
            "help": "Lets an app that uses your site's API create member accounts. Leave it off unless you run such "
                    "an app. It also needs \"Let visitors create accounts\".",
            "copy_source": "written",
            "control": "toggle row",
            "default": "'0'",
            "type": "bool",
        },
    },
    # 1.2.3 (account-1): the invoice seller (ADDED_CARDS money.setup/invoices). Saved in the
    # monetization group with only_when_sent rules (SettingsController::settingsSchema), read by
    # App\Services\InvoiceService::seller(): empty values print the site name only and no tax line.
    {
        "after": "paypal_enabled",
        "new_card": True,
        "setting": {
            "key": "invoice_seller_name",
            "save_group": "monetization",
            "old": {"tab": None, "section": None, "label": "", "help": ""},
            "new": {"section": "money", "page": "money.setup", "url": "/admin/money/setup#invoice_seller_name",
                    "card": "invoices", "card_title": "Invoices"},
            "level": "basic",
            "label": "Seller name",
            "help": "Your name or your company's name, as invoices should show it. Empty: your site name.",
            "copy_source": "written",
            "control": "text",
            "default": None,
            "type": "string",
        },
    },
    {
        "after": "invoice_seller_name",
        "setting": {
            "key": "invoice_seller_address",
            "save_group": "monetization",
            "old": {"tab": None, "section": None, "label": "", "help": ""},
            "new": {"section": "money", "page": "money.setup", "url": "/admin/money/setup#invoice_seller_address",
                    "card": "invoices", "card_title": "Invoices"},
            "level": "basic",
            "label": "Seller address",
            "help": "Street, postcode, city and country, one line each. Leave it empty to print no address.",
            "copy_source": "written",
            "control": "textarea",
            "default": None,
            "type": "string",
        },
    },
    {
        "after": "invoice_seller_address",
        "setting": {
            "key": "invoice_seller_tax_id",
            "save_group": "monetization",
            "old": {"tab": None, "section": None, "label": "", "help": ""},
            "new": {"section": "money", "page": "money.setup", "url": "/admin/money/setup#invoice_seller_tax_id",
                    "card": "invoices", "card_title": "Invoices"},
            "level": "basic",
            "label": "Tax or company number",
            "help": "Your VAT or company registration number, if you have one. Leave it empty to print none.",
            "copy_source": "written",
            "control": "text",
            "default": None,
            "type": "string",
        },
    },
    {
        "after": "invoice_seller_tax_id",
        "setting": {
            "key": "invoice_tax_label",
            "save_group": "monetization",
            "old": {"tab": None, "section": None, "label": "", "help": ""},
            "new": {"section": "money", "page": "money.setup", "url": "/admin/money/setup#invoice_tax_label",
                    "card": "invoices", "card_title": "Invoices"},
            "level": "basic",
            "label": "Tax name",
            "help": "The name of the tax line, for example VAT or GST. Only used when the tax rate is above 0.",
            "copy_source": "written",
            "control": "text",
            "default": None,
            "type": "string",
        },
    },
    {
        "after": "invoice_tax_label",
        "setting": {
            "key": "invoice_tax_rate",
            "save_group": "monetization",
            "old": {"tab": None, "section": None, "label": "", "help": ""},
            "new": {"section": "money", "page": "money.setup", "url": "/admin/money/setup#invoice_tax_rate",
                    "card": "invoices", "card_title": "Invoices"},
            "level": "basic",
            "label": "Tax rate",
            "help": "The tax included in your prices, in percent. 0 = invoices have no tax line.",
            "copy_source": "written",
            "control": 'number + "%" suffix',
            "default": "'0'",
            "type": "string",
        },
    },
    # 1.2.3 (lane KS): the owner's blocked words of the importers (ADDED_CARDS content.import/blocked_words), one word or
    # phrase per line, read by App\Support\ContentSafety::siteWords(); its default is the list of ContentSafety
    # (config content_safety.default_words). Saved in the uploads group with only_when_sent rules
    # (SettingsController::settingsSchema), so the old settings page never writes it.
    {
        "after": "terms_content",
        "new_card": True,
        "setting": {
            "key": "import_blocked_words",
            "save_group": "uploads",
            "old": {"tab": None, "section": None, "label": "", "help": ""},
            "new": {"section": "content", "page": "content.import", "url": "/admin/avdb-import#import_blocked_words",
                    "card": "blocked_words", "card_title": "Blocked words"},
            "level": "basic",
            "label": "Blocked words",
            # (round 2: the limits the save checks, App\Support\ContentSafety::problems(), and how words of other scripts
            # match, are said here)
            "help": "Videos whose title, tags or categories contain one of these words or phrases are not imported. One "
                    "per line, at least 3 letters (2 in Chinese, Japanese, Korean or Thai), at most 500 of at most 100 "
                    "characters. Words in Chinese, Japanese or Thai also match inside longer words. Words that present a "
                    "child or a school-age minor are always blocked, even when they are not in this list.",
            "copy_source": "written",
            "control": "textarea",
            "default": "config('content_safety.default_words')",
            "type": "string",
        },
    },
    # 1.2.3 (the owner's request of 29 Oct, lane DW): the visitor buttons of a demo site (App\Support\VisitorChoices), both
    # off by default, so every other site is unchanged. Each choice is a cookie of the visitor's browser; nothing is saved
    # on the server. Saved with only_when_sent rules (SettingsController::settingsSchema), so the old settings page never
    # writes them. The look picker is a setting of the general group drawn on the Themes page (its draft and publish
    # write it like the Appearance pages' other settings of other groups).
    {
        "after": "active_master_experience",
        "new_card": True,
        "setting": {
            "key": "visitor_look_picker_enabled",
            "save_group": "general",
            "old": {"tab": None, "section": None, "label": "", "help": ""},
            "new": {"section": "appearance", "page": "appearance.themes", "url": "/admin/appearance#visitor_look_picker_enabled",
                    "card": "visitors", "card_title": "Visitors try the looks"},
            "level": "advanced",
            "label": "Let visitors try the looks",
            "help": "Adds a palette button to the corner of every page. Visitors can try the 12 looks in their own "
                    "browser; your published look and what other visitors see do not change.",
            "copy_source": "written",
            "control": "toggle row",
            "default": "'0'",
            "type": "bool",
            "preview": {"how": "draft preview (?avd_draft=token)"},
        },
    },
    {
        "after": "ads_hide_for_premium",
        "setting": {
            "key": "demo_ads_switch_enabled",
            "save_group": "monetization",
            "old": {"tab": None, "section": None, "label": "", "help": ""},
            "new": {"section": "money", "page": "money.ads", "url": "/admin/money/ads#demo_ads_switch_enabled",
                    "card": "switch", "card_title": "Ads on or off"},
            "level": "advanced",
            "label": "Demo site: ads are off until a visitor turns them on",
            "help": "Every ad, the video ad too, stays off until a visitor turns ads on with the \"Demo · Ads off\" "
                    "button at the bottom of the page. It changes only their own browser. Needs \"Show ads\".",
            "copy_source": "written",
            "control": "toggle row",
            "default": "'0'",
            "type": "bool",
        },
    },
]


# 1.2.3 round 1 (R1-077): the payment keys of Money > Payment setup are values of the .env file, saved by their own form
# (MoneyController::updateKeys, with the admin's password; resources/views/admin/money/payment-keys.blade.php), not
# settings of the database. Both searches still list them ("webhook" found nothing, although the 1.2.3 notes send owners
# to the Webhook signing secret): each is a setting of the catalogue marked "env" (the settings form never draws or
# saves it, App\Http\Controllers\Admin\SettingsPageController::form()), on two cards named like the form's sections
# (their ids are the sections' ids, card-payment-keys and card-payment-keys-paypal), and a search lands on its row
# (id="<KEY>-row"). Labels as the form shows them; the keys are the .env names owners know from the docs.
PAYMENT_KEY_CARDS = [
    {
        "page": "money.setup",
        "after": "invoices",
        "card": {
            "id": "payment-keys",
            "title": "Card payments (Stripe)",
            "description": "From your Stripe dashboard: Developers > API keys, and Developers > Webhooks for the signing secret.",
            "level": "basic",
        },
    },
    {
        "page": "money.setup",
        "after": "payment-keys",
        "card": {
            "id": "payment-keys-paypal",
            "title": "PayPal",
            "description": "From developer.paypal.com: Apps & Credentials. Use Sandbox to test, Live to take real payments.",
            "level": "basic",
        },
    },
]


def payment_key(key, after, card, label, help_text, control, default=None, secret=True, new_card=False):
    """One PAYMENT_KEY_SETTINGS row: a spec mapping row for a .env payment key of Money > Payment setup."""
    row = {
        "after": after,
        "setting": {
            "key": key,
            "save_group": "env",
            "old": {"tab": None, "section": None, "label": "", "help": ""},
            "new": {"section": "money", "page": "money.setup", "url": "/admin/money/setup#" + key, "card": card,
                    "card_title": "Card payments (Stripe)" if card == "payment-keys" else "PayPal"},
            "level": "basic",
            "label": label,
            "help": help_text,
            "copy_source": "written",
            "control": control,
            "default": default,
            "type": "string",
            "env": True,
        },
    }
    if secret:
        row["setting"]["secret"] = True
    if new_card:
        row["new_card"] = True
    return row


PAYMENT_KEY_SETTINGS = [
    payment_key("STRIPE_KEY", "invoice_tax_rate", "payment-keys", "Publishable key",
                "From your Stripe dashboard: Developers > API keys. It starts with pk_live_ (pk_test_ for tests).",
                "secret", new_card=True),
    payment_key("STRIPE_SECRET", "STRIPE_KEY", "payment-keys", "Secret key",
                "From your Stripe dashboard: Developers > API keys. It starts with sk_live_ or rk_live_. Keep it private.",
                "secret"),
    payment_key("STRIPE_WEBHOOK_SECRET", "STRIPE_SECRET", "payment-keys", "Webhook signing secret",
                "From your Stripe dashboard: Developers > Webhooks, the endpoint of your site. It starts with whsec_. "
                "Without it, members cannot pay by card.",
                "secret"),
    payment_key("CASHIER_CURRENCY", "STRIPE_WEBHOOK_SECRET", "payment-keys", "Currency",
                "The currency of your plan prices and card payments: three letters, for example usd or eur.",
                "text", default="'usd'", secret=False),
    payment_key("PAYPAL_MODE", "CASHIER_CURRENCY", "payment-keys-paypal", "PayPal mode",
                "Sandbox to test, Live to take real payments.",
                "select", default="'sandbox'", secret=False, new_card=True),
    payment_key("PAYPAL_CLIENT_ID", "PAYPAL_MODE", "payment-keys-paypal", "Client ID",
                "From developer.paypal.com: Apps & Credentials, your app.",
                "secret"),
    payment_key("PAYPAL_CLIENT_SECRET", "PAYPAL_CLIENT_ID", "payment-keys-paypal", "Secret",
                "From developer.paypal.com: Apps & Credentials, your app. Keep it private.",
                "secret"),
    payment_key("PAYPAL_WEBHOOK_ID", "PAYPAL_CLIENT_SECRET", "payment-keys-paypal", "Webhook ID",
                "From developer.paypal.com: the webhook of your app for your site. Without it, members cannot pay with PayPal.",
                "secret"),
]


def apply_removed_settings(spec):
    """Take REMOVED_SETTINGS out of the spec (in memory only): the mapping row, the key in its card's fields and one from
    validation.settings_total. A removed key must not be named by any other list of this file."""
    rows = spec["mapping"]["settings"]
    for key, target in REMOVED_SETTINGS.items():
        at = next((i for i, s in enumerate(rows) if s["key"] == key), None)
        if at is None:
            raise ValueError("REMOVED_SETTINGS: %s is no setting of the spec" % key)
        if target == key or not any(s["key"] == target for s in rows):
            raise ValueError("REMOVED_SETTINGS: %s leads to %s, which is no other setting of the spec" % (key, target))
        for name, table in (("SETTING_FIXES", SETTING_FIXES), ("LEVEL_FIXES", LEVEL_FIXES), ("HIDDEN_TARGETS", HIDDEN_TARGETS),
                            ("FORMER_LABELS", FORMER_LABELS), ("FORMER_OLD_NAMES", FORMER_OLD_NAMES), ("OPTION_LABELS", OPTION_LABELS)):
            if key in table:
                raise ValueError("REMOVED_SETTINGS: %s is still in %s" % (key, name))
        del rows[at]
        for section in spec["ia"]["sections"]:
            for page in section["pages"]:
                for card in page.get("cards") or []:
                    card["fields"] = [f for f in card["fields"] if f["key"] != key]
        for term, targets in list(spec["copy"]["search_synonyms"].items()):
            if key in targets:
                raise ValueError("REMOVED_SETTINGS: %s is a target of the search word %r" % (key, term))
        spec["validation"]["settings_total"] -= 1
    return spec


def fold(text):
    """App\\Support\\AdminSettingsSearch::fold(): lower case, accents off, "_" and "-" as spaces, one space between words."""
    import unicodedata
    text = unicodedata.normalize("NFD", text.lower())
    text = re.sub("[\u0300-\u036f]", "", text)
    return re.sub(r"\s+", " ", text.replace("_", " ").replace("-", " ")).strip()


def former_names_122(tree, data):
    """--former-122: the names the 1.2.2 admin showed for each setting in each language (FORMER_NAMES_FILE). For every
    setting of the 1.2.2 package's settings-index.json (a removed one: its REMOVED_SETTINGS target; a 1.2.2 label that
    names another setting now: that setting, as ADDED_SETTINGS decided for the successors) and every language of the
    admin and English: its label and its old settings page name as lang/<locale>.json of 1.2.2 translated them, kept when
    the setting does not show that name now (its label and old name in that language and in English, by the current
    language files of this tree), and when it is not the label of another setting in that language now (an English name:
    in any language). A search word of a kept name that is the label of another setting now (the name as typed:
    former_terms()) is not made: a "skipped" entry with its "term" and "setting" says so (the current label wins)."""
    rows = json.load(open(os.path.join(tree, "resources", "admin", "lib", "settings-index.json"), encoding="utf-8"))
    settings = data["settings"]
    locales = ["en"] + ADMIN_LOCALES

    def lang(base, locale):
        if locale == "en":
            return {}
        return json.load(open(os.path.join(base, "lang", locale + ".json"), encoding="utf-8"))

    then = {loc: lang(tree, loc) for loc in locales}
    now = {loc: lang(ROOT, loc) for loc in locales}
    owner_of_label = {}
    for key, entry in settings.items():
        owner_of_label.setdefault(entry["label"].strip().lower(), key)
    shown_now = {}
    labels_now = {}
    for loc in locales:
        for key, entry in settings.items():
            texts = set()
            for english in (entry["label"], entry["old"][1] or ""):
                if english:
                    texts.add(fold(english))
                    texts.add(fold(now[loc].get(english, english)))
            shown_now[(loc, key)] = texts
            labels_now.setdefault(loc, {}).setdefault(fold(now[loc].get(entry["label"], entry["label"])), set()).add(key)

    def label_of_another(loc, text, owner):
        """The settings other than owner whose label the admin shows now is the text (as the searches read it), in
        that language; an English name is a search word in every language (AdminSearchIndex::formerNames()), so for
        it: in every language."""
        found = set()
        for shown_in in (locales if loc == "en" else [loc]):
            found |= labels_now.get(shown_in, {}).get(fold(text), set())
        return found - {owner}

    names = {loc: {} for loc in locales}
    skipped = []
    for row in rows:
        key = row["key"]
        target = key if key in settings else REMOVED_SETTINGS.get(key)
        if target is None:
            skipped.append({"key": key, "why": "no setting of this version and no REMOVED_SETTINGS target"})
            continue
        for kind, english in (("label", row.get("label") or ""), ("old", row.get("old") or "")):
            if not english:
                continue
            owner = owner_of_label.get(english.strip().lower(), target) if kind == "label" else target
            for loc in locales:
                name = re.sub(r"\s+", " ", then[loc].get(english, english)).strip()
                folded = fold(name)
                if not folded or folded in shown_now[(loc, owner)]:
                    continue
                others = label_of_another(loc, name, owner)
                if others:
                    skipped.append({"key": key, "locale": loc, "name": name, "why": "the label of %s now" % ", ".join(sorted(others))})
                    continue
                names[loc].setdefault(name, set()).add(owner)
                # A search word of the name (former_terms(): also as typed, without quote marks and final full stop) that
                # is the label of another setting now is not made: the current label wins (G1, merge c5: the Indonesian
                # 1.2.2 name 'Judul "Kategori".' of the footer's "Categories" heading, typed, is the label of the side
                # menu's Categories heading now). load_former_names() leaves out the search words these entries name.
                for term in former_terms(name):
                    others = label_of_another(loc, term, owner)
                    if others:
                        skipped.append({"key": key, "locale": loc, "name": name, "term": term, "setting": owner,
                                        "why": "as typed, the label of %s now" % ", ".join(sorted(others))})

    out = {
        "about": "The names the admin of AVDBCMS 1.2.2 showed for a setting (label, old settings page name) in each language "
                 "where 1.2.3 shows another: search words of that setting in that language (R3-071). Made by "
                 "scripts/admin_settings/generate_config.py --former-122 from the 1.2.2 package; do not edit by hand.",
        "names": {loc: {name: sorted(keys) for name, keys in sorted(found.items())} for loc, found in names.items() if found},
        "skipped": skipped,
    }
    with open(FORMER_NAMES_FILE, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(json.dumps(out, ensure_ascii=False, indent=1, sort_keys=False) + "\n")
    print("wrote %s (%d names, %d skipped)" % (FORMER_NAMES_FILE, sum(len(v) for v in out["names"].values()), len(skipped)))


def former_names_json(former):
    """resources/admin/lib/former-names.json, read by App\\Support\\AdminSearchIndex::formerNames() for the admin's
    searches only (not a config file: every request loads the config, and these words are only for the two searches)."""
    return json.dumps(former, ensure_ascii=False, separators=(",", ":"), sort_keys=True) + "\n"


def load_former_names(settings):
    """FORMER_NAMES_FILE as the admin's searches read it (former_names_json()): locale => search word (as shown and as
    typed: former_terms()) => setting keys, without the search words --former-122 found to be the label of another
    setting now ("skipped" entries with a "term"). A name of a setting this version does not have is an error (run
    --former-122 again)."""
    if not os.path.exists(FORMER_NAMES_FILE):
        return {}
    data = json.load(open(FORMER_NAMES_FILE, encoding="utf-8"))
    names = data.get("names", {})
    # the search words --former-122 found to be the label of another setting now (the current label wins)
    not_made = {(entry["locale"], entry["term"], entry["setting"]) for entry in data.get("skipped", []) if "term" in entry}
    out = {}
    for loc, found in sorted(names.items()):
        if loc != "en" and loc not in ADMIN_LOCALES:
            raise ValueError("FORMER_NAMES_FILE: %s is no language of the admin" % loc)
        for name, keys in found.items():
            for key in keys:
                if key not in settings:
                    raise ValueError("FORMER_NAMES_FILE: %s (%s %r) is no setting: run --former-122 again" % (key, loc, name))
            for term in former_terms(name):
                kept = [key for key in keys if (loc, term, key) not in not_made]
                if kept:
                    out.setdefault(loc, {}).setdefault(term, set()).update(kept)
    return {loc: {term: sorted(keys) for term, keys in sorted(terms.items())} for loc, terms in out.items()}


def apply_added_cards(spec):
    """Put ADDED_CARDS into the spec's pages (in memory only), each right after the card named by "after" ("after":
    None: the first card of the page, e.g. of a page that had none)."""
    for added in ADDED_CARDS + PAYMENT_KEY_CARDS:
        page = next((p for sec in spec["ia"]["sections"] for p in sec["pages"] if p["id"] == added["page"]), None)
        if page is None:
            raise ValueError("ADDED_CARDS: no page %s" % added["page"])
        cards = page.get("cards") or []
        ids = [c["id"] for c in cards]
        if added["card"]["id"] in ids:
            raise ValueError("ADDED_CARDS: %s/%s is already a card of the spec" % (added["page"], added["card"]["id"]))
        if added["after"] is not None and added["after"] not in ids:
            raise ValueError("ADDED_CARDS: %s has no card %s to follow" % (added["page"], added["after"]))
        card = dict(added["card"])
        card["fields"] = []
        cards.insert(0 if added["after"] is None else ids.index(added["after"]) + 1, card)
        page["cards"] = cards
    return spec


def apply_added_settings(spec):
    """Put ADDED_SETTINGS into the spec (in memory only): the mapping row after its anchor (with an order between the
    anchor's and the next setting's), and the key into its card's fields after the anchor."""
    settings = spec["mapping"]["settings"]
    known = {s["key"] for s in settings}
    for added in ADDED_SETTINGS + PAYMENT_KEY_SETTINGS:
        row = json.loads(json.dumps(added["setting"]))
        key, anchor = row["key"], added["after"]
        if key in known:
            raise ValueError("ADDED_SETTINGS: %s is already a setting of the spec" % key)
        at = next((i for i, s in enumerate(settings) if s["key"] == anchor), None)
        if at is None:
            raise ValueError("ADDED_SETTINGS: %s should follow %s, which is not a setting" % (key, anchor))
        new_card = bool(added.get("new_card"))
        if not new_card and (settings[at]["new"]["page"], settings[at]["new"]["card"]) != (row["new"]["page"], row["new"]["card"]):
            raise ValueError("ADDED_SETTINGS: %s and %s are not on the same card" % (key, anchor))
        following = [s["new"]["order"] for s in settings if s["new"]["order"] > settings[at]["new"]["order"]]
        row["new"]["order"] = (settings[at]["new"]["order"] + min(following)) / 2 if following else settings[at]["new"]["order"] + 1
        settings.insert(at + 1, row)
        known.add(key)
        placed = False
        for section in spec["ia"]["sections"]:
            for page in section["pages"]:
                if page["id"] != row["new"]["page"]:
                    continue
                for card in page.get("cards") or []:
                    if card["id"] != row["new"]["card"]:
                        continue
                    keys = [f["key"] for f in card["fields"]]
                    if new_card:
                        if keys:
                            raise ValueError("ADDED_SETTINGS: %s opens the card %s/%s, which has settings" % (key, page["id"], card["id"]))
                        card["fields"].append({"key": key})
                        placed = True
                        continue
                    if anchor not in keys:
                        raise ValueError("ADDED_SETTINGS: the card %s/%s has no %s" % (page["id"], card["id"], anchor))
                    card["fields"].insert(keys.index(anchor) + 1, {"key": key})
                    placed = True
        if not placed:
            raise ValueError("ADDED_SETTINGS: no card %s/%s for %s" % (row["new"]["page"], row["new"]["card"], key))
        spec["validation"]["settings_total"] += 1
    return spec


def apply_card_fixes(spec):
    """Put CARD_FIXES into the spec's cards (in memory only), like apply_setting_fixes()."""
    cards = {}
    for section in spec["ia"]["sections"]:
        for page in section["pages"]:
            for card in page.get("cards") or []:
                cards[(page["id"], card["id"])] = card
    for where, fields in CARD_FIXES.items():
        if where not in cards:
            raise ValueError("CARD_FIXES: %s/%s is not a card of the spec" % where)
        for field, (old, new) in fields.items():
            current = cards[where].get(field)
            if current not in (old, new):
                raise ValueError("CARD_FIXES: the spec's %s/%s %s is now %r (expected %r): check the fix" % (where + (field, current, old)))
            cards[where][field] = new
    return spec


def apply_level_fixes(spec):
    """Put LEVEL_FIXES into the spec's settings and cards (in memory only), like apply_setting_fixes()."""
    settings = {s["key"]: s for s in spec["mapping"]["settings"]}
    cards = {}
    for section in spec["ia"]["sections"]:
        for page in section["pages"]:
            for card in page.get("cards") or []:
                cards[(page["id"], card["id"])] = card
    for where, (old, new) in LEVEL_FIXES.items():
        target = cards.get(where) if isinstance(where, tuple) else settings.get(where)
        if target is None:
            raise ValueError("LEVEL_FIXES: %s is not a setting or card of the spec" % (where,))
        if target.get("level") not in (old, new):
            raise ValueError("LEVEL_FIXES: the spec's level of %s is now %r (expected %r): check the fix" % (where, target.get("level"), old))
        target["level"] = new
    return spec


def apply_setting_fixes(spec):
    """Put SETTING_FIXES into the spec's settings (in memory only; the spec file is never written)."""
    by_key = {s["key"]: s for s in spec["mapping"]["settings"]}
    for key, fields in SETTING_FIXES.items():
        if key not in by_key:
            raise ValueError("SETTING_FIXES: %s is not a setting of the spec" % key)
        for field, (old, new) in fields.items():
            current = by_key[key].get(field)
            if current not in (old, new):
                raise ValueError("SETTING_FIXES: the spec's %s %s is now %r (expected %r): check the fix" % (key, field, current, old))
            by_key[key][field] = new
    return spec


def parse_literal(text):
    text = text.strip()
    if re.fullmatch(r"-?\d+", text):
        return int(text)
    m = re.fullmatch(r"'((?:[^'\\]|\\.)*)'", text)
    if m:
        return m.group(1).replace("\\'", "'").replace("\\\\", "\\")
    if text == "null":
        return None
    raise ValueError("not a literal: %r" % text)


def convert_default(value):
    """Spec default -> PHP config value: a literal, or ['config' => key, 'fallback' => x] / ['translate' => text]."""
    if value is None or isinstance(value, (int, float)):
        return value
    text = value
    if text in DEFAULT_LITERALS:
        return DEFAULT_LITERALS[text]
    m = re.fullmatch(r"(?:\$env\['\w+'\]\s*\?\?\s*)?config\('([\w.]+)'(?:,\s*(.+))?\)", text.strip())
    if m:
        out = {"config": m.group(1)}
        if m.group(2) is not None:
            out["fallback"] = parse_literal(m.group(2))
        return out
    m = re.fullmatch(r"__\('((?:[^'\\]|\\.)*)'\)", text.strip())
    if m:
        return {"translate": m.group(1)}
    m = re.fullmatch(r"'((?:[^'\\]|\\.)*)'", text)
    if m:
        return m.group(1).replace("\\'", "'")
    if text.startswith(("$", "\\", "config(")):
        raise ValueError("unhandled default expression %r" % text)
    return text


def convert_control(key, spec_control):
    """Spec control text -> {'control': id, ...extras}."""
    c = spec_control.strip()
    rules = [
        (r"toggle row", lambda m: {"control": "toggle"}),
        (r"text", lambda m: {"control": "text"}),
        (r"textarea", lambda m: {"control": "textarea"}),
        (r"number", lambda m: {"control": "number"}),
        (r'number \+ "([^"]+)" suffix', lambda m: {"control": "number", "suffix": m.group(1)}),
        # A size stored in bytes, typed as a number with an MB / GB choice (SettingsPageController::sizeBytes()).
        (r"size \(MB or GB\)", lambda m: {"control": "size"}),
        (r"url", lambda m: {"control": "url"}),
        (r"email", lambda m: {"control": "email"}),
        (r"secret", lambda m: {"control": "secret"}),
        (r"select", lambda m: {"control": "select"}),
        (r"select \(searchable\)", lambda m: {"control": "select", "searchable": True}),
        (r"segmented", lambda m: {"control": "segmented"}),
        (r"segmented \(number of videos per row\)", lambda m: {"control": "segmented", "columns": True}),
        (r"segmented \(([^)]+)\)", lambda m: {"control": "segmented"}),
        (r"radio cards \((.+?)\)(?: \+ \"Other services\" select \((.+)\))?", lambda m: {"control": "radio_cards"}),
        (r"code box \(paste ad code\)", lambda m: {"control": "code"}),
        (r"code editor \((\w+), loaded on demand\)", lambda m: {"control": "code_editor", "lang": m.group(1)}),
        (r"visual picker \(wireframe tiles\)", lambda m: {"control": "picker"}),
        (r"colour field.*", lambda m: {"control": "color"}),
        (r"link list editor.*", lambda m: {"control": "link_list"}),
        (r"upload.*", lambda m: {"control": "upload"}),
        (r"gallery.*", lambda m: {"control": "gallery"}),
        (r'set by "Effects level".*', lambda m: {"control": "effects_level"}),
    ]
    for pattern, make in rules:
        m = re.fullmatch(pattern, c)
        if m:
            return make(m)
    raise ValueError("unknown control for %s: %r" % (key, spec_control))


def confirm_parts(key, setting):
    text = setting.get("confirm")
    if not text or text == GENERIC_CONFIRM:
        if key in CONFIRM_WHEN:
            raise ValueError("%s: expected a consequence confirm" % key)
        return {}
    if key not in CONFIRM_WHEN:
        raise ValueError("%s: confirm text without a CONFIRM_WHEN rule: %r" % (key, text))
    return {"confirm": text, "confirm_when": CONFIRM_WHEN[key], "confirm_action": CONFIRM_ACTION[key]}


def build(spec, schema=None):
    pages = {}
    for section in spec["ia"]["sections"]:
        for page in section["pages"]:
            if not page.get("cards"):
                continue
            pid = page["id"]
            # Settings pages with a new route (template T4) are rendered from this file; the Languages list page
            # keeps its own view and only lists its cards here.
            renderer = RENDERED_BY.get(pid) or (RENDERED_BY.get(section["id"]) if page["template"] == "T4" else None)
            pages[pid] = {
                "section": section["id"],
                "slug": page["url"].rstrip("/").split("/")[-1] if renderer else None,
                "url": page["url"],
                "route": page["route"].replace(" (new)", ""),
                "label": page["label"],
                "description": page["description"],
                "level": page["level"],
                "template": page["template"],
                "rendered_by": renderer,
                "cards": {
                    card["id"]: {
                        "title": card["title"],
                        "description": card.get("description", ""),
                        "level": card["level"],
                        "fields": [f["key"] for f in card["fields"]],
                    }
                    for card in page["cards"]
                },
            }

    settings = {}
    groups = {}
    for s in sorted(spec["mapping"]["settings"], key=lambda x: x["new"]["order"]):
        key = s["key"]
        entry = {
            "group": s["save_group"],
            "type": s["type"],
            "page": s["new"]["page"],
            "card": s["new"]["card"],
            "level": s["level"],
            "label": s["label"],
            "help": s["help"],
        }
        entry.update(convert_control(key, s["control"]))
        if key in OPTION_LABELS:
            entry["options"] = OPTION_LABELS[key]
        if key in OPTION_HELP:
            entry["option_help"] = OPTION_HELP[key]
        if key in MORE_OPTIONS:
            entry["more_options"] = MORE_OPTIONS[key]
        entry["default"] = convert_default(s.get("default"))
        entry.update(confirm_parts(key, s))
        if s.get("secret"):
            entry["secret"] = True
        if s.get("env"):
            # A value of the .env file with a form of its own (PAYMENT_KEY_SETTINGS): searched, never drawn or saved
            # by the settings form.
            entry["env"] = True
        if s.get("reveal_with_password"):
            entry["reveal"] = True
        if s.get("public_by_design"):
            entry["public"] = True
        if s.get("phone_only"):
            entry["phone_only"] = True
        if s.get("impact"):
            entry["impact"] = {k: s["impact"][k] for k in ("cost", "runs_on", "why") if k in s["impact"]}
        if s.get("preview"):
            # 'draft' = previewed through the Appearance draft link; otherwise the URL override that previews it today.
            entry["preview"] = s["preview"].get("param") or "draft"
        if s["level"] == "hidden" and s.get("level_reason"):
            entry["level_reason"] = s["level_reason"]
        # Old tab and label, so the search still finds a setting by the name it had in the old admin.
        entry["old"] = [s["old"]["tab"], s["old"]["label"]]
        settings[key] = entry
        groups.setdefault(s["save_group"], []).append(key)

    move_cards(pages, settings)

    for pid, page in pages.items():
        for cid, card in page["cards"].items():
            for key in card["fields"]:
                if settings[key]["page"] != pid or settings[key]["card"] != cid:
                    raise ValueError("%s is listed on %s/%s but placed on %s/%s" % (key, pid, cid, settings[key]["page"], settings[key]["card"]))

    apply_links(pages, settings)

    if schema is not None:
        problems = []
        for key, entry in settings.items():
            if entry.get("env"):
                continue
            in_schema = [g for g, fields in schema.items() if key in fields]
            if not in_schema:
                problems.append("%s: not in the current schema (new key, needs a save rule)" % key)
            elif entry["group"] not in in_schema:
                problems.append("%s: spec group %s, schema group %s" % (key, entry["group"], in_schema))
            elif schema[entry["group"]][key]["type"] != entry["type"]:
                problems.append("%s: spec type %s, schema type %s" % (key, entry["type"], schema[entry["group"]][key]["type"]))
        for line in problems:
            print("schema: " + line, file=sys.stderr)

    hub = []
    for pid, icon, line, examples in HUB_TOPICS:
        nav_page = next(p for sec in spec["ia"]["sections"] for p in sec["pages"] if p["id"] == pid)
        hub.append({"page": pid, "label": nav_page["label"], "url": nav_page["url"], "icon": icon, "description": line, "examples": examples})

    confirms = sorted(k for k, v in settings.items() if v.get("confirm"))
    if len(confirms) != 22:
        raise ValueError("expected 22 consequence confirms, got %d" % len(confirms))
    if len(settings) != spec["validation"]["settings_total"]:
        raise ValueError("expected %d settings, got %d" % (spec["validation"]["settings_total"], len(settings)))

    # Words owners search with that are not in any label ("smtp", "adsense", "dark mode"): term -> keys / page ids.
    synonyms = {term.lower(): targets for term, targets in spec["copy"]["search_synonyms"].items()}
    for term, targets in ADDED_SYNONYMS.items():
        for target in targets:
            if target not in settings and target not in {p["id"] for sec in spec["ia"]["sections"] for p in sec["pages"]}:
                raise ValueError("ADDED_SYNONYMS: %s -> %s is no setting or page" % (term, target))
        if term.lower() in synonyms:
            raise ValueError("ADDED_SYNONYMS: %s is already a synonym of the spec" % term)
        synonyms[term.lower()] = list(targets)

    # A setting's former labels (FORMER_LABELS), in every language of the admin, as search words of that setting.
    labels_now = {entry["label"].strip().lower(): key for key, entry in settings.items()}
    for key, shown in FORMER_LABELS.items():
        if key not in settings:
            raise ValueError("FORMER_LABELS: %s is no setting" % key)
        if sorted(shown) != sorted(["en"] + ADMIN_LOCALES):
            raise ValueError("FORMER_LABELS: %s needs its label in every language of the admin" % key)
        english = shown["en"].strip().lower()
        if english in labels_now:
            raise ValueError("FORMER_LABELS: %r is the label of %s now" % (shown["en"], labels_now[english]))
        for text in shown.values():
            for term in former_terms(text):
                if synonyms.get(term, [key]) != [key]:
                    raise ValueError("FORMER_LABELS: %r is already a search word of %s" % (term, synonyms[term]))
                synonyms[term] = [key]

    # The old settings page's name of a setting as 1.2.0-1.2.2 showed it in the admin's other languages (R1-124).
    for key, shown in FORMER_OLD_NAMES.items():
        if key not in settings or not settings[key]["old"][1]:
            raise ValueError("FORMER_OLD_NAMES: %s is no setting with an old name" % key)
        if not set(shown) <= set(ADMIN_LOCALES):
            raise ValueError("FORMER_OLD_NAMES: %s has a language the admin does not have" % key)
        for text in shown.values():
            for term in former_terms(text):
                if synonyms.get(term, [key]) != [key]:
                    raise ValueError("FORMER_OLD_NAMES: %r is already a search word of %s" % (term, synonyms[term]))
                synonyms[term] = [key]

    for key, reason in NO_FIELD.items():
        name = key[5:] if key.startswith("site.") else key
        if key in settings or (key.startswith("site.") and name in settings):
            raise ValueError("NO_FIELD: %s has a field (it is a setting of the catalogue)" % key)
        if not reason.strip():
            raise ValueError("NO_FIELD: %s needs a reason" % key)

    old_tabs = {}
    for tab, info in OLD_TABS.items():
        entry = {"label": info["label"]}
        if "page" in info:
            entry["page"] = info["page"]
        old_tabs[tab] = entry
    unknown_tabs = sorted({s["old"][0] for s in settings.values() if s["old"][0] is not None} - set(OLD_TABS))
    if unknown_tabs:
        raise ValueError("OLD_TABS: no name for the old tab(s) %s" % ", ".join(unknown_tabs))

    return {
        "tab_redirects": spec["mapping"]["tab_redirects"],
        "hub": hub,
        "synonyms": synonyms,
        "former_names": load_former_names(settings),
        "old_tabs": old_tabs,
        "no_field": dict(NO_FIELD),
        "pages": pages,
        "settings": settings,
    }


def apply_links(pages, settings):
    """SETTING_LINKS and HIDDEN_TARGETS onto their settings. Every hidden setting must say where it is changed now, and
    a target must be a setting the admin shows (or a page part with a marker)."""
    for key, link in SETTING_LINKS.items():
        if key not in settings or settings[key]["level"] == "hidden":
            raise ValueError("SETTING_LINKS: %s is no setting the admin shows" % key)
        if not link["link"].startswith("/admin") or not link.get("marker"):
            raise ValueError("SETTING_LINKS: %s needs an /admin address and a marker" % key)
        settings[key]["link"] = link["link"]
        settings[key]["marker"] = link["marker"]

    hidden = {key for key, s in settings.items() if s["level"] == "hidden"}
    missing = sorted(hidden - set(HIDDEN_TARGETS))
    if missing:
        raise ValueError("HIDDEN_TARGETS: say where these hidden settings are changed now: %s" % ", ".join(missing))
    for key, target in HIDDEN_TARGETS.items():
        if key not in hidden:
            raise ValueError("HIDDEN_TARGETS: %s is not a hidden setting" % key)
        if not target.get("hint"):
            raise ValueError("HIDDEN_TARGETS: %s needs a hint" % key)
        kinds = [k for k in ("see", "link", "card") if target.get(k)]
        if len(kinds) != 1:
            raise ValueError("HIDDEN_TARGETS: %s needs exactly one of see, link or card" % key)
        if "see" in kinds:
            see = target["see"]
            if see not in settings or settings[see]["level"] == "hidden":
                raise ValueError("HIDDEN_TARGETS: %s points to %s, which the admin does not show" % (key, see))
            settings[key]["see"] = see
        elif "link" in kinds:
            if not target["link"].startswith("/admin") or not target.get("marker"):
                raise ValueError("HIDDEN_TARGETS: %s needs an /admin address and a marker" % key)
            settings[key]["link"] = target["link"]
            settings[key]["marker"] = target["marker"]
        else:
            settings[key]["anchor"] = "card-" + settings[key]["card"]
        settings[key]["hint"] = target["hint"]


def move_cards(pages, settings):
    """Apply MOVED_CARDS: the card and its settings change page; a page left without cards holds no settings."""
    for move in MOVED_CARDS:
        (src_page, src_card), (dst_page, dst_card), after = move["from"], move["to"], move["after"]
        card = pages[src_page]["cards"].pop(src_card)
        if not pages[src_page]["cards"]:
            del pages[src_page]
        cards = {}
        for cid, other in pages[dst_page]["cards"].items():
            cards[cid] = other
            if cid == after:
                cards[dst_card] = card
        if dst_card not in cards:
            raise ValueError("%s has no card %s to place %s after" % (dst_page, after, dst_card))
        pages[dst_page]["cards"] = cards
        for key in card["fields"]:
            if (settings[key]["page"], settings[key]["card"]) != (src_page, src_card):
                raise ValueError("%s is not on %s/%s" % (key, src_page, src_card))
            settings[key]["page"], settings[key]["card"] = dst_page, dst_card
        # Keep the settings in page and card order: the moved ones follow the last setting of the card they follow.
        anchor = pages[dst_page]["cards"][after]["fields"][-1]
        moved = {key: settings.pop(key) for key in card["fields"]}
        order = list(settings.items())
        at = next(i for i, (key, _) in enumerate(order) if key == anchor) + 1
        settings.clear()
        settings.update(order[:at] + list(moved.items()) + order[at:])


def search_rows(spec, data):
    """The Ctrl+K settings rows, in the spec's order: every setting at every level, where it lives now (page, card
    title), its label and help, and its old name when that differs. A setting without a row of its own carries where it
    is changed instead ("link", "see" or "anchor") and, when hidden, the "hint" the search shows."""
    rows = []
    for s in spec["mapping"]["settings"]:
        entry = data["settings"][s["key"]]
        page = data["pages"].get(entry["page"])
        card = page["cards"][entry["card"]]["title"] if page and entry["card"] in page["cards"] else s["new"].get("card_title") or ""
        row = {
            "key": s["key"],
            "label": s["label"],
            "help": re.sub(r"\s+", " ", s.get("help") or "").strip(),
            "page": entry["page"],
            "card": card,
            "level": s["level"],
        }
        old = s.get("old") or {}
        if old.get("label") and old["label"].strip().lower() != s["label"].strip().lower():
            row["old"] = old["label"].strip()
        for field in ("link", "see", "anchor", "hint"):
            if entry.get(field):
                row[field] = entry[field]
        rows.append(row)
    return json.dumps(rows, ensure_ascii=False, separators=(",", ":")) + "\n"


# ---------------------------------------------------------------------------------------------------- PHP writer

def php_string(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def php_scalar(value):
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, int):
        return str(value)
    return php_string(str(value))


def is_flat(value):
    return all(not isinstance(v, (dict, list)) for v in (value.values() if isinstance(value, dict) else value))


def php_value(value, indent):
    pad = "    " * indent
    inner = "    " * (indent + 1)
    if isinstance(value, dict):
        if not value:
            return "[]"
        if is_flat(value) and len(value) <= 3 and sum(len(str(k)) + len(str(v)) for k, v in value.items()) < 70:
            return "[" + ", ".join("%s => %s" % (php_string(str(k)), php_scalar(v)) for k, v in value.items()) + "]"
        lines = ["%s%s => %s," % (inner, php_string(str(k)), php_value(v, indent + 1)) for k, v in value.items()]
        return "[\n" + "\n".join(lines) + "\n" + pad + "]"
    if isinstance(value, list):
        if not value:
            return "[]"
        flat = ", ".join(php_scalar(v) for v in value) if is_flat(value) else None
        if flat is not None and len(flat) < 100:
            return "[" + flat + "]"
        lines = ["%s%s," % (inner, php_value(v, indent + 1)) for v in value]
        return "[\n" + "\n".join(lines) + "\n" + pad + "]"
    return php_scalar(value)


def php_inline(value):
    if isinstance(value, dict):
        return "[" + ", ".join("%s => %s" % (php_string(str(k)), php_inline(v)) for k, v in value.items()) + "]"
    if isinstance(value, list):
        return "[" + ", ".join(php_inline(v) for v in value) + "]"
    return php_scalar(value)


def render(data, spec_path):
    head = [
        "<?php",
        "",
        "/*",
        "|--------------------------------------------------------------------------",
        "| Admin settings catalogue (generated)",
        "|--------------------------------------------------------------------------",
        "|",
        "| Where each of the %d settings lives in the redesigned admin: its page and" % len(data["settings"]),
        "| card, plain label, one-line help, control, default and, for risky switches,",
        "| the consequence shown before saving. Read by the settings and money pages,",
        "| the Appearance pages and the Ctrl+K search.",
        "|",
        "| Generated by scripts/admin_settings/generate_config.py from the admin design",
        "| spec (%s). Do not edit by hand:" % os.path.basename(spec_path),
        "| change the spec or the generator and run it again.",
        "|",
        "| Strings are English translation keys: translate them at render time with",
        "| __(), never here (the locale is not known when the config is cached).",
        "| The reasons of 'no_field' are for maintainers only (never shown, not",
        "| translated).",
        "|",
        "*/",
        "",
        "return [",
        "    'tab_redirects' => " + php_value(data["tab_redirects"], 1) + ",",
        "    'hub' => " + php_value(data["hub"], 1) + ",",
        "    'synonyms' => " + php_value(data["synonyms"], 1) + ",",
        "    // The tabs of the old settings page, for \"What moved where\".",
        "    'old_tabs' => " + php_value(data["old_tabs"], 1) + ",",
        "    // Settings the code reads or stores that have no admin field on purpose, with the reason; 'site.<name>' is",
        "    // a config('site.<name>') value that is no setting of its own.",
        "    'no_field' => " + php_value(data["no_field"], 1) + ",",
        "    'pages' => " + php_value(data["pages"], 1) + ",",
        "    // One line per setting, in page and card order.",
        "    'settings' => [",
    ] + ["        %s => %s," % (php_string(k), php_inline(v)) for k, v in data["settings"].items()] + [
        "    ],",
        "];",
        "",
    ]
    return "\n".join(head)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--spec", default=DEFAULT_SPEC)
    ap.add_argument("--out", default=os.path.join(ROOT, "config", "admin_settings.php"))
    ap.add_argument("--index", default=os.path.join(ROOT, "resources", "admin", "lib", "settings-index.json"))
    ap.add_argument("--former-out", dest="former_out", default=os.path.join(ROOT, "resources", "admin", "lib", "former-names.json"))
    ap.add_argument("--schema")
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--former-122", dest="former_122", help="the 1.2.2 tree: make FORMER_NAMES_FILE from it first (R3-071)")
    a = ap.parse_args()
    spec = apply_level_fixes(apply_card_fixes(apply_added_settings(apply_added_cards(apply_setting_fixes(apply_removed_settings(json.load(open(a.spec, encoding="utf-8"))))))))
    schema = json.load(open(a.schema, encoding="utf-8")) if a.schema else None
    data = build(spec, schema)
    if a.former_122:
        if a.check:
            raise SystemExit("--former-122 writes a file: run it without --check")
        former_names_122(a.former_122, data)
        data["former_names"] = load_former_names(data["settings"])
    outputs = [(a.out, render(data, a.spec)), (a.index, search_rows(spec, data)), (a.former_out, former_names_json(data["former_names"]))]
    if a.check:
        stale = 0
        for path, text in outputs:
            current = open(path, encoding="utf-8", newline="").read() if os.path.exists(path) else None
            if current != text:
                print("%s is out of date: run scripts/admin_settings/generate_config.py" % path)
                stale += 1
            else:
                print("%s is up to date" % path)
        return 1 if stale else 0
    for path, text in outputs:
        with open(path, "w", encoding="utf-8", newline="\n") as fh:
            fh.write(text)
    print("wrote %s (%d settings, %d bytes), %s (%d rows) and %s (%d former names)" % (
        a.out, len(data["settings"]), len(outputs[0][1].encode("utf-8")), a.index, outputs[1][1].count('{"key":'),
        a.former_out, sum(len(terms) for terms in data["former_names"].values())))
    return 0


if __name__ == "__main__":
    sys.exit(main())
