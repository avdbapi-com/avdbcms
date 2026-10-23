"""Set the appearance rows of database/install/example.sql to the v2 factory default (fresh installs land on the
YouTube Spotlight Pro look: Midnight Spotlight / signature colour style, demo1 parity).

Only `settings` rows of group 'appearance' are rewritten, added or removed; every other byte of the file stays as it
is (checked before writing). Nothing from the file is printed except appearance keys. Re-runnable: a second run
changes nothing.

usage: python scripts/themes/update_example_sql.py [--spec PATH] [--check]
"""
import hashlib
import json
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SQL = ROOT / "database" / "install" / "example.sql"
DEFAULT_SPEC = Path("D:/avd-notes") / "redesign" / "spec" / "theme" / "theme_spec.json"
COLOUR_KEYS = {
    "primary_color", "brand_secondary_color", "accent_color",
    "dark_bg_color", "dark_surface_color", "light_bg_color", "light_surface_color",
}
STAMP = "2026-10-20 00:00:00"


def arg(name, default=None):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else default


def fail(message):
    print("update_example_sql: " + message, file=sys.stderr)
    sys.exit(2)


def tuples(values_sql):
    """(start, end) of every top-level (...) tuple; quotes and backslash escapes respected."""
    spans, i, n = [], 0, len(values_sql)
    while i < n:
        if values_sql[i] != "(":
            i += 1
            continue
        start, depth, j, in_str = i, 0, i, False
        while j < n:
            c = values_sql[j]
            if in_str:
                if c == "\\":
                    j += 2
                    continue
                if c == "'":
                    in_str = False
            elif c == "'":
                in_str = True
            elif c == "(":
                depth += 1
            elif c == ")":
                depth -= 1
                if depth == 0:
                    spans.append((start, j + 1))
                    break
            j += 1
        i = j + 1
    return spans


def fields(tuple_sql):
    body, vals, cur, in_str, i = tuple_sql[1:-1], [], "", False, 0
    while i < len(body):
        c = body[i]
        if in_str:
            if c == "\\" and i + 1 < len(body):
                cur += body[i:i + 2]
                i += 2
                continue
            in_str = c != "'"
            cur += c
        elif c == "'":
            in_str = True
            cur += c
        elif c == ",":
            vals.append(cur.strip())
            cur = ""
        else:
            cur += c
        i += 1
    vals.append(cur.strip())
    return vals


def unquote(v):
    if v == "NULL":
        return None
    return re.sub(r"\\(.)", lambda m: {"n": "\n", "r": "\r", "t": "\t", "0": "\0"}.get(m.group(1), m.group(1)), v[1:-1])


def quote(s):
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'").replace("\n", "\\n").replace("\r", "\\r") + "'"


def factory_look(spec):
    master = next(m for m in spec["masters"]["items"] if m["id"] == "youtube_spotlight_pro")
    look = {k: str(v) for k, v in master["apply_settings"].items() if k not in COLOUR_KEYS}
    if (look.get("theme_preset"), look.get("theme_flavor")) != ("tokyo_neon", "signature"):
        fail("youtube_spotlight_pro is not tokyo_neon/signature in the spec")
    look["daily_design_scope"] = "palette_only"      # D5: colours only on new installs
    look["appearance_schema_version"] = "2"          # the upgrade migration skips fresh installs
    return look


def main():
    spec = json.loads(Path(arg("--spec", os.environ.get("AVD_THEME_SPEC") or str(DEFAULT_SPEC))).read_text(encoding="utf-8"))
    look = factory_look(spec)
    with open(SQL, encoding="utf-8", newline="") as handle:
        original = handle.read()
    match = re.search(r"^INSERT INTO `settings` VALUES\s*(.*?);\s*$", original, re.M | re.S)
    if not match:
        fail("settings INSERT not found")
    values_sql = match.group(1)
    spans = tuples(values_sql)
    separator = values_sql[spans[0][1]:spans[1][0]] if len(spans) > 1 else ",\n"

    other_digest, appearance, max_id, last_appearance = hashlib.sha256(), {}, 0, -1
    for index, (start, end) in enumerate(spans):
        raw = values_sql[start:end]
        f = fields(raw)
        max_id = max(max_id, int(f[0]))
        if unquote(f[3]) == "appearance":
            appearance[unquote(f[1])] = raw
            if unquote(f[1]) not in COLOUR_KEYS:
                last_appearance = index
        else:
            other_digest.update(raw.encode("utf-8"))
    if last_appearance < 0:
        fail("no appearance rows found")

    # Rebuild the VALUES list keeping every original separator; only appearance tuples are replaced or dropped, and
    # new appearance tuples go right after the last existing one.
    pieces, cursor, next_id = [], 0, max_id + 1
    for index, (start, end) in enumerate(spans):
        separator_before = values_sql[cursor:start]
        cursor = end
        raw = values_sql[start:end]
        f = fields(raw)
        if unquote(f[3]) != "appearance":
            pieces.append(separator_before + raw)
            continue
        key = unquote(f[1])
        if key in COLOUR_KEYS:
            # the colour style supplies the colours; a stored colour means a custom colour
            if index == 0:
                cursor = spans[1][0] if len(spans) > 1 else end
            continue
        if key in look and unquote(f[2]) != look[key]:
            f = f[:2] + [quote(look[key])] + f[3:5] + [quote(STAMP)] + f[6:]
            raw = "(" + ",".join(f) + ")"
        pieces.append(separator_before + raw)
        if index == last_appearance:
            for new_key, value in look.items():
                if new_key not in appearance:
                    pieces.append(separator + "(%d,%s,%s,'appearance',%s,%s)" % (next_id, quote(new_key), quote(value), quote(STAMP), quote(STAMP)))
                    next_id += 1
    new_values = "".join(pieces) + values_sql[cursor:]
    updated = original[:match.start(1)] + new_values + original[match.end(1):]

    # Guard: every non-appearance row and every byte outside the settings INSERT are unchanged.
    check = re.search(r"^INSERT INTO `settings` VALUES\s*(.*?);\s*$", updated, re.M | re.S)
    digest = hashlib.sha256()
    for start, end in tuples(check.group(1)):
        raw = check.group(1)[start:end]
        if unquote(fields(raw)[3]) != "appearance":
            digest.update(raw.encode("utf-8"))
    if digest.hexdigest() != other_digest.hexdigest():
        fail("non-appearance rows would change")
    if updated[:check.start(1)] != original[:match.start(1)] or updated[check.end(1):] != original[match.end(1):]:
        fail("bytes outside the settings rows would change")

    rows = {unquote(fields(check.group(1)[s:e])[1]): unquote(fields(check.group(1)[s:e])[2])
            for s, e in tuples(check.group(1)) if unquote(fields(check.group(1)[s:e])[3]) == "appearance"}
    for key, value in look.items():
        if rows.get(key) != value:
            fail("appearance row %s did not take %r" % (key, value))

    if "--check" in sys.argv:
        print(("OK  " if updated == original else "OUT OF DATE  ") + str(SQL.relative_to(ROOT)))
        sys.exit(0 if updated == original else 1)
    if updated != original:
        with open(SQL, "w", encoding="utf-8", newline="") as handle:
            handle.write(updated)
    print("example.sql appearance rows: %d (were %d); other rows unchanged (sha256 %s)" % (
        len(rows), len(appearance), other_digest.hexdigest()[:16]))


if __name__ == "__main__":
    main()
