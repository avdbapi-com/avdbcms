"""Generate config/admin_settings.php (and the Ctrl+K settings rows) from the admin design spec.

The redesigned admin places every one of the 635 settings on a page and a card with a plain label, a one-line help
text, a control type and (for 22 risky switches) the consequence shown before saving. All of that comes from
admin_design_spec.json (ia.sections[].pages[].cards + mapping.settings + mapping.tab_redirects); this script turns it
into a PHP config file so the settings pages, the Appearance pages and the Ctrl+K search all read one source. Places
decided after the spec was written are applied on top (MOVED_CARDS). The same data is written as
resources/admin/lib/settings-index.json, the settings rows of the Ctrl+K search (App\\Support\\AdminSearchIndex).

usage:
  python scripts/admin_settings/generate_config.py [--spec PATH] [--out PATH] [--index PATH] [--schema PATH] [--check]

  --spec    admin_design_spec.json (default: D:/avd-notes/redesign/spec/admin/spec/admin_design_spec.json)
  --out     output file (default: config/admin_settings.php next to this script's repository)
  --index   Ctrl+K settings rows (default: resources/admin/lib/settings-index.json next to this script's repository)
  --schema  optional JSON dump of SettingsController::settingsSchema() ({group: {key: {type, rules}}}) to verify that
            every setting is saved by the group and with the type the spec says
  --check   do not write; exit 1 when an output file is missing or out of date

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
# that holds settings is rendered by its own package (Appearance: WP8, list pages: WP9); its cards are still listed.
RENDERED_BY = {
    "settings": "settings",
    "money.ads": "money",
    "money.setup": "money",
    "money.premium_page": "money",
}

# Topics of the settings hub ("What do you want to change?"), in the order of the mockup (spec/admin/mockups,
# 04 hub): page id, icon, one line, examples.
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

# Spec defaults that are PHP expressions of the old settings view, written out.
DEFAULT_LITERALS = {
    "$uploadAllowedFormatsDefault": "mp4,webm,avi,mov,mkv",
    "\\App\\Enums\\VideoStatus::PUBLIC->value": "0",
    "$viewHistoryDefaults['retention_days'] ?? 90": "90",
}


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

    if schema is not None:
        problems = []
        for key, entry in settings.items():
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

    return {
        "tab_redirects": spec["mapping"]["tab_redirects"],
        "hub": hub,
        "synonyms": synonyms,
        "pages": pages,
        "settings": settings,
    }


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
    """The Ctrl+K settings rows, in the spec's order: every setting that is not hidden, where it lives now (page, card
    title), its label and help, and its old name when that differs."""
    rows = []
    for s in spec["mapping"]["settings"]:
        if s["level"] == "hidden":
            continue
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
        "|",
        "*/",
        "",
        "return [",
        "    'tab_redirects' => " + php_value(data["tab_redirects"], 1) + ",",
        "    'hub' => " + php_value(data["hub"], 1) + ",",
        "    'synonyms' => " + php_value(data["synonyms"], 1) + ",",
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
    ap.add_argument("--schema")
    ap.add_argument("--check", action="store_true")
    a = ap.parse_args()
    spec = json.load(open(a.spec, encoding="utf-8"))
    schema = json.load(open(a.schema, encoding="utf-8")) if a.schema else None
    data = build(spec, schema)
    outputs = [(a.out, render(data, a.spec)), (a.index, search_rows(spec, data))]
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
    print("wrote %s (%d settings, %d bytes) and %s (%d rows)" % (
        a.out, len(data["settings"]), len(outputs[0][1].encode("utf-8")), a.index, outputs[1][1].count('{"key":')))
    return 0


if __name__ == "__main__":
    sys.exit(main())
