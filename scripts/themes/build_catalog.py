"""Generate app/Support/ThemeCatalogData.php (theme catalogue v2) from the redesign theme spec.

Re-runnable and deterministic: the same theme_spec.json always produces the same PHP file.

usage:
  python scripts/themes/build_catalog.py [--spec PATH] [--check]
  python scripts/themes/build_catalog.py --frozen [--factory-sources PATH] [--spec PATH] [--check]

  --spec              theme_spec.json (default: env AVD_THEME_SPEC, else the redesign spec in %LOCALAPPDATA%)
  --check             do not write; exit 1 when a generated file is out of date
  --frozen            (re)write the frozen data blocks of the upgrade migration
                      database/migrations/2026_10_20_000002_migrate_appearance_to_theme_catalog_v2.php
                      (factory sources = the old code's values, master upgrade fallback = the spec's 8 master rows)
  --factory-sources   factory_sources.json (default: env AVD_FACTORY_SOURCES, else ../../data/factory_sources.json
                      next to the spec): the old catalogue order of the 120 ids, and the frozen data for --frozen.
                      Required: the generator stops when it is missing.
"""
import json
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "app" / "Support" / "ThemeCatalogData.php"
MIGRATION = ROOT / "database" / "migrations" / "2026_10_20_000002_migrate_appearance_to_theme_catalog_v2.php"
DEFAULT_SPEC = Path("D:/avd-notes") / "redesign" / "spec" / "theme" / "theme_spec.json"

HEX = re.compile(r"^#[0-9a-f]{6}$")
COLOUR_KEYS = [
    "primary_color", "brand_secondary_color", "accent_color",
    "dark_bg_color", "dark_surface_color", "light_bg_color", "light_surface_color",
]
# Theme entry keys kept at runtime; tokens/contrast/adjustments/palette_oklch/mockups/identity stay in the spec
# (ThemeTokens derives the tokens from palette + typography + shape + layout + recipe + flavor seeds).
THEME_KEYS = [
    "id", "name", "family", "family_name", "new_id", "factory_default", "tagline", "signature", "best_for",
    "default_mode", "typography", "shape", "layout", "palette", "recommended_settings", "theme_owned_recipe",
    "motion_per_tier", "masters", "light_dark_behaviour", "aliases_in", "flavors",
]
ALIAS_KEYS = ["old_id", "old_label", "old_category", "old_mode", "old_home", "old_primary", "theme", "flavor", "reused_as_flagship"]
SERIF = {"Playfair Display", "Cinzel", "Cormorant Garamond", "Bodoni Moda", "Newsreader", "Fraunces", "EB Garamond"}
MONO = {"JetBrains Mono", "Space Mono", "IBM Plex Mono", "VT323", "Silkscreen"}
# css2 specs that replace the spec's typography.font_specs (measured on real pages, not on the mockups):
# - Be Vietnam Pro is a static family (one file per weight). With the spec's 400;600;700 Ultraviolet Club loads 4 files,
#   97.6 KB with Syne, 108 KB when Google serves Syne as its larger kit file, over the 100 KB font budget. 400;700 = 3
#   files, <= 87 KB. CSS font matching then draws text set at 500 with the 400 face and at 550-650 with the 700 face
#   (real faces, no synthetic bold). Keeping 600 instead costs the variable Syne (only its static 800 fits the budget:
#   display text set at 700, e.g. the categories page's titles, then takes the much wider 800 face) or the 700 face
#   (buttons, kickers and badges drop to 600).
FONT_SPEC_OVERRIDES = {"Be Vietnam Pro": "Be+Vietnam+Pro:wght@400;700"}


def arg(name, default=None):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else default


def fail(message):
    print("build_catalog: " + message, file=sys.stderr)
    sys.exit(2)


def stack(family):
    if family in MONO:
        tail = "ui-monospace, 'Cascadia Code', Consolas, monospace"
    elif family in SERIF:
        tail = "Georgia, 'Times New Roman', serif"
    else:
        tail = "system-ui, 'Segoe UI', Roboto, sans-serif"
    return "'" + family + "', " + tail


def hex_or(value, fallback):
    value = (value or "").strip().lower() if isinstance(value, str) else ""
    return value if HEX.match(value) else fallback


def flavor_colours(theme, flavor):
    """The 7 colour settings a flavor supplies (what the site renders when the owner stored no custom colour)."""
    rec = theme["recommended_settings"]
    seeds = flavor.get("seeds") or {}
    dark = flavor.get("palette_dark") or {}
    light = flavor.get("palette_light") or {}
    return {
        "primary_color": hex_or(seeds.get("brand") or dark.get("brand"), rec["primary_color"].lower()),
        "brand_secondary_color": hex_or(seeds.get("brand2") or dark.get("brand2"), rec["brand_secondary_color"].lower()),
        "accent_color": hex_or(seeds.get("accent") or dark.get("accent"), rec["accent_color"].lower()),
        "dark_bg_color": hex_or(dark.get("bg"), rec["dark_bg_color"].lower()),
        "dark_surface_color": hex_or(dark.get("s1"), rec["dark_surface_color"].lower()),
        "light_bg_color": hex_or(light.get("bg"), rec["light_bg_color"].lower()),
        "light_surface_color": hex_or(light.get("s1"), rec["light_surface_color"].lower()),
    }


def build(spec, v1_order=None):
    families = {}
    for fam in spec["families"]:
        families[fam["id"]] = {"id": fam["id"], "name": fam["name"], "promise": fam["promise"], "themes": list(fam["themes"])}
    family_of = {tid: fid for fid, fam in families.items() for tid in fam["themes"]}

    themes = {}
    for t in spec["themes"]:
        tid = t["id"]
        if family_of.get(tid) != t["family"]:
            fail("theme %s: family %r is not listed by families[]" % (tid, t["family"]))
        flavors = {}
        for f in t["flavors"]:
            entry = {"id": f["id"], "name": f["name"], "seeds": f.get("seeds") or {}}
            if f.get("palette_dark"):
                entry["palette_dark"] = f["palette_dark"]
            if f.get("palette_light"):
                entry["palette_light"] = f["palette_light"]
            entry["formerly"] = list(f.get("formerly") or [])
            entry["colours"] = flavor_colours(t, f)
            flavors[f["id"]] = entry
        if next(iter(flavors)) != "signature":
            fail("theme %s: the first flavor must be 'signature'" % tid)
        rec = dict(t["recommended_settings"])
        sig = flavors["signature"]["colours"]
        for key in COLOUR_KEYS:
            rec[key] = rec[key].lower()
            if rec[key] != sig[key]:
                fail("theme %s: recommended %s %s != signature colour %s" % (tid, key, rec[key], sig[key]))
        if rec.get("theme_preset") != tid or rec.get("theme_flavor") != "signature":
            fail("theme %s: recommended_settings must name the theme and the signature flavor" % tid)
        src = dict(t)
        src["family_name"] = families[t["family"]]["name"]
        src["recommended_settings"] = rec
        src["flavors"] = flavors
        themes[tid] = {k: src[k] for k in THEME_KEYS if k in src}

    aliases = {}
    rows = spec["alias_map"]["rows"]
    if v1_order:
        # keep the old catalogue order (ThemeCatalog::all() lists the old ids first, as the v1 page and the legacy
        # endpoint's Rule::in() list did)
        position = {pid: i for i, pid in enumerate(v1_order)}
        if set(position) != {r["old_id"] for r in rows}:
            fail("factory_sources preset ids differ from alias_map old ids")
        rows = sorted(rows, key=lambda r: position[r["old_id"]])
    for row in rows:
        if row["theme"] not in themes or row["flavor"] not in themes[row["theme"]]["flavors"]:
            fail("alias %s -> %s/%s is not in the catalogue" % (row["old_id"], row["theme"], row["flavor"]))
        if row["old_id"] in themes and (row["theme"], row["flavor"]) != (row["old_id"], "signature"):
            fail("alias %s collides with the v2 theme id" % row["old_id"])
        aliases[row["old_id"]] = {k: row[k] for k in ALIAS_KEYS}

    typo = spec["typography"]
    font_specs = dict(typo["font_specs"])
    for family, css2 in FONT_SPEC_OVERRIDES.items():
        if family not in font_specs:
            fail("font spec override %s: the spec has no such family" % family)
        font_specs[family] = css2
    pairings = {}
    for pid, p in typo["pairings"].items():
        entry = {"label": p["label"], "display": p["display"], "body": p["body"]}
        for key in ("label_font", "vi_display"):
            if p.get(key):
                entry[key] = p[key]
        entry["legacy"] = bool(p.get("legacy"))
        entry["existing"] = bool(p.get("existing"))
        entry["heading_font"] = stack(p["display"])
        entry["body_font"] = stack(p["body"])
        entry["label_font_stack"] = stack(p.get("label_font") or p["body"])
        for fam in {p["display"], p["body"], p.get("label_font"), p.get("vi_display")} - {None}:
            if fam not in font_specs:
                fail("pairing %s: font %s has no css2 spec" % (pid, fam))
        pairings[pid] = entry
    for tid, t in themes.items():
        if t["typography"]["pairing"] not in pairings:
            fail("theme %s: unknown pairing %s" % (tid, t["typography"]["pairing"]))

    counts = spec["meta"]["counts"]
    if (len(families), len(themes), sum(len(t["flavors"]) for t in themes.values()), len(aliases)) != (
        counts["families"], counts["themes"], counts["flavors"], counts["aliases"]):
        fail("counts differ from spec meta.counts %r" % counts)

    meta = {
        "version": spec["meta"]["version"],
        "date": spec["meta"]["date"],
        "counts": {"families": len(families), "themes": len(themes), "flavors": counts["flavors"], "aliases": len(aliases), "pairings": len(pairings)},
        "default_theme": next(tid for tid, t in themes.items() if t.get("factory_default")),
    }
    return meta, families, themes, aliases, pairings, font_specs


# ---------------------------------------------------------------- PHP literal writer

def php_str(s):
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def php_scalar(v):
    if v is None:
        return "null"
    if v is True:
        return "true"
    if v is False:
        return "false"
    if isinstance(v, (int, float)):
        return repr(v)
    return php_str(str(v))


def is_flat(v):
    if isinstance(v, dict):
        return all(not isinstance(x, (dict, list)) for x in v.values())
    if isinstance(v, list):
        return all(not isinstance(x, (dict, list)) for x in v)
    return True


def php(v, indent):
    if not isinstance(v, (dict, list)):
        return php_scalar(v)
    pad = " " * indent
    inner = " " * (indent + 4)
    if isinstance(v, list):
        items = [php(x, indent + 4) for x in v]
    else:
        items = [php_str(str(k)) + " => " + php(x, indent + 4) for k, x in v.items()]
    if not items:
        return "[]"
    one_line = "[" + ", ".join(items) + "]"
    # flat arrays, and everything below a theme's own keys (a flavor, a palette mode), stay on one line
    if is_flat(v) or indent >= 20:
        return one_line
    return "[\n" + "".join(inner + item + ",\n" for item in items) + pad + "]"


def method(name, doc, value):
    return (
        "    /**\n     * " + doc + "\n     */\n"
        "    public static function " + name + "(): array\n    {\n"
        "        return " + php(value, 8) + ";\n    }\n"
    )


def render(spec_path, meta, families, themes, aliases, pairings, font_specs):
    head = (
        "<?php\n\n"
        "namespace App\\Support;\n\n"
        "/**\n"
        " * Theme catalogue v2 data: 32 themes in 13 families, 174 colour styles (flavors), 120 old preset ids,\n"
        " * 34 typography pairings.\n"
        " *\n"
        " * GENERATED by scripts/themes/build_catalog.py from the redesign theme spec (" + meta["version"] + ", " + meta["date"] + ").\n"
        " * Do not edit by hand: change the spec and run the generator again.\n"
        " */\n"
        "final class ThemeCatalogData\n{\n"
    )
    body = [
        method("meta", "Spec version and counts.", meta),
        method("families", "Families in display order: id => [id, name, promise, themes].", families),
        method("themes", "Themes in spec order (flavors keyed by id, signature first).", themes),
        method("aliases", "The 120 old preset ids: old_id => alias row (v2 theme + flavor, old label, old mode).", aliases),
        method("pairings", "Typography pairings (7 existing + 27 new) with ready font stacks.", pairings),
        method("fontSpecs", "Google Fonts css2 family specs (trimmed to the weights the themes use).", font_specs),
    ]
    return head + "\n".join(body) + "}\n"


# ---------------------------------------------------------------- frozen migration blocks

def frozen_block(name, data):
    payload = json.dumps(data, ensure_ascii=False, separators=(",", ":"), sort_keys=False)
    if "\nJSON" in payload:
        fail("frozen payload contains the nowdoc terminator")
    return "    // <frozen:" + name + ">\n    private const " + name + " = <<<'JSON'\n" + payload + "\nJSON;\n    // </frozen:" + name + ">\n"


def factory_sources_path(spec_path):
    path = Path(arg("--factory-sources", os.environ.get("AVD_FACTORY_SOURCES") or str(spec_path.parents[1] / "data" / "factory_sources.json")))
    if not path.is_file():
        # Without it the 120 old ids would silently change order (ThemeCatalog::all(), the v1 preset list and the
        # legacy endpoint's Rule::in list follow it), and --frozen has nothing to freeze.
        fail("factory sources not found: %s (pass --factory-sources or set AVD_FACTORY_SOURCES)" % path)
    return path


def write_frozen(spec, spec_path, check):
    sources = factory_sources_path(spec_path)
    factory = json.loads(sources.read_text(encoding="utf-8"))
    factory = {k: v for k, v in factory.items() if k != "about"}
    masters = {m["id"]: m for m in spec["masters"]["items"]}
    policy = {}
    for row in spec["masters"]["upgrade_policy"]:
        mid = row["master"]
        mode = "colours_only" if row["untouched_upgrade"].lower().startswith("colours only") else "reapply"
        policy[mid] = {
            "master": mid, "old_theme_preset": row["old_theme_preset"], "alias": row["alias"], "v2_theme": row["v2_theme"],
            "mode": mode, "apply_settings": masters[mid]["apply_settings"],
        }
    if not MIGRATION.exists():
        fail("missing " + str(MIGRATION))
    text = MIGRATION.read_text(encoding="utf-8")
    for name, data in (("FACTORY_SOURCES", factory), ("MASTER_UPGRADE_FALLBACK", policy)):
        pattern = re.compile(r"    // <frozen:" + name + r">\n.*?    // </frozen:" + name + r">\n", re.S)
        if not pattern.search(text):
            fail("marker <frozen:%s> not found in the migration" % name)
        text = pattern.sub(lambda _m: frozen_block(name, data), text)
    current = MIGRATION.read_text(encoding="utf-8")
    if check:
        print(("OK  " if current == text else "OUT OF DATE  ") + str(MIGRATION.relative_to(ROOT)))
        return current == text
    if current != text:
        MIGRATION.write_text(text, encoding="utf-8", newline="\n")
    print("wrote " + str(MIGRATION.relative_to(ROOT)) + " (frozen blocks)")
    return True


def main():
    spec_path = Path(arg("--spec", os.environ.get("AVD_THEME_SPEC") or str(DEFAULT_SPEC)))
    if not spec_path.is_file():
        fail("spec not found: %s (pass --spec)" % spec_path)
    spec = json.loads(spec_path.read_text(encoding="utf-8"))
    check = "--check" in sys.argv
    if "--frozen" in sys.argv:
        sys.exit(0 if write_frozen(spec, spec_path, check) else 1)
    sources = factory_sources_path(spec_path)
    v1_order = list(json.loads(sources.read_text(encoding="utf-8"))["preset_values"].keys())
    meta, families, themes, aliases, pairings, font_specs = build(spec, v1_order)
    text = render(spec_path, meta, families, themes, aliases, pairings, font_specs)
    current = OUT.read_text(encoding="utf-8") if OUT.exists() else ""
    if check:
        print(("OK  " if current == text else "OUT OF DATE  ") + str(OUT.relative_to(ROOT)))
        sys.exit(0 if current == text else 1)
    if current != text:
        OUT.write_text(text, encoding="utf-8", newline="\n")
    print("wrote %s: %d families, %d themes, %d flavors, %d aliases, %d pairings, %d font specs (%d bytes)" % (
        OUT.relative_to(ROOT), len(families), len(themes), meta["counts"]["flavors"], len(aliases), len(pairings), len(font_specs), len(text.encode("utf-8"))))


if __name__ == "__main__":
    main()
