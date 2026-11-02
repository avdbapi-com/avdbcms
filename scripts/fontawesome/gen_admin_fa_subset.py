"""The redesigned admin's Font Awesome subset: only the icons the legacy admin views still use (1.2.3).

The redesigned admin draws its own icons from an SVG sprite (resources/admin/icons.svg). The legacy admin views it
still renders keep Font Awesome until they are migrated, as a subset of only the icons they use: this scans the admin
views and the admin PHP code for icon names (static fa-* classes, fa-{{ $x ? 'a' : 'b' }}, 'icon' => 'name' arrays,
enum icon() methods), keeps the names Font Awesome 6 knows, and writes (all generated; re-run it after a legacy admin
view starts using another icon, tests/Feature/AdminShellTest fails until then):
  resources/admin/lib/_fa.scss                           the font faces, the base rules and the used icon classes
  resources/admin/lib/fa-solid.woff, fa-regular.woff,    the Font Awesome 6.7.2 fonts cut to those icons (WOFF 1:
  fa-brands.woff                                         fontTools writes WOFF 2 only with the brotli module)
_fa.scss keeps the line endings it already has (CRLF in a Windows checkout that converts them, LF otherwise), and a font
whose tables did not change is left as it is (another fontTools version may compress the same font differently), so a
run that finds nothing new changes no file.

1.2.3 (round 1b, R1R-04): a cut font is a Modified Version under the SIL OFL 1.1, which reserves the name "Font
Awesome" for the original. Like the site's cut fonts (scripts/fontawesome/gen_site_fa_subset.py), each admin font gets
its own name in its name table (AVD Admin Icon Subset Solid / Regular, AVD Admin Brand Icon Subset), the copyright
notice is kept, and the license notice and where the font comes from are added (name records 10, 13, 14). The
stylesheet's font-family stays 'Font Awesome 6 Free' / 'Font Awesome 6 Brands' (what the legacy views' classes ask
for); the glyphs are the same as before (tests/Unit/AdminFontAwesomeSubsetTest checks the names and the glyphs).

Needs the npm packages (npm install: node_modules/@fortawesome/fontawesome-free) and, to write the fonts, fontTools
(python -m pip install fonttools: its subsetter cuts and renames the fonts). Run it from the repository root:
  python scripts/fontawesome/gen_admin_fa_subset.py            write the four files
  python scripts/fontawesome/gen_admin_fa_subset.py --check    print what the scan finds and what a run would change,
                                                               write nothing (fontTools not needed)
  options: [<root>] another checkout to read and write (default: this repository); --report <file.json> also writes
  the icons found and the font sizes
"""
import argparse
import importlib.util
import json
import os
import re
import shutil
import sys
import tempfile

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

parser = argparse.ArgumentParser(description="Generate the admin's Font Awesome subset (CSS and fonts).")
parser.add_argument('root', nargs='?', default=REPO, help='the checkout to read and write (default: this repository)')
parser.add_argument('--check', action='store_true', help='print what the scan finds and what would change, write nothing')
parser.add_argument('--report', help='also write a JSON file: the icons found and the font sizes')
args = parser.parse_args()

ROOT = args.root
FA = os.path.join(ROOT, 'node_modules', '@fortawesome', 'fontawesome-free')
OUT = os.path.join(ROOT, 'resources', 'admin', 'lib')
if not os.path.isdir(FA):
    sys.exit('node_modules/@fortawesome/fontawesome-free not found under ' + ROOT + ': run npm install first')
if not args.check and importlib.util.find_spec('fontTools') is None:
    sys.exit('fontTools is needed to cut the fonts: python -m pip install fonttools (nothing was written)')


def write_text(path, text):
    """Write text with the line endings the file already has (CRLF or LF; a new file gets LF)."""
    newline = '\n'
    try:
        with open(path, 'rb') as fh:
            if b'\r\n' in fh.read():
                newline = '\r\n'
    except OSError:
        pass
    with open(path, 'w', encoding='utf-8', newline=newline) as fh:
        fh.write(text)


def read_text(path):
    try:
        with open(path, encoding='utf-8', newline='') as fh:
            return fh.read().replace('\r\n', '\n')
    except OSError:
        return None


families = json.load(open(os.path.join(FA, 'metadata', 'icon-families.json'), encoding='utf-8'))
by_name = {}
for name, icon in families.items():
    styles = {s['style'] for s in icon.get('familyStylesByLicense', {}).get('free', []) if s.get('family') == 'classic'}
    entry = {'name': name, 'unicode': icon['unicode'], 'styles': styles}
    by_name[name] = entry
    for alias in (icon.get('aliases') or {}).get('names', []):
        by_name.setdefault(alias, dict(entry, alias_of=name))

# what the legacy admin views render: the admin views and components, the admin controllers, the enums (icon()
# methods) and the few shared files whose icons the old admin prints
SCAN_DIRS = ['resources/views/admin', 'resources/views/components/admin', 'app/Http/Controllers/Admin', 'app/Enums']
SCAN_FILES = ['resources/views/components/expand-item.blade.php', 'resources/views/layouts/admin.blade.php', 'app/Models/Report.php',
              'app/Support/MasterExperienceCatalog.php', 'app/Support/AppearanceSettings.php']
NON_ICONS = {'fa', 'fas', 'far', 'fab', 'fa-solid', 'fa-regular', 'fa-brands', 'fa-fw', 'fa-spin', 'fa-pulse', 'fa-lg', 'fa-sm', 'fa-xs',
             'fa-2x', 'fa-3x', 'fa-4x', 'fa-5x', 'fa-1x', 'fa-2xs', 'fa-xl', 'fa-2xl', 'fa-beat', 'fa-fade', 'fa-bounce', 'fa-flip',
             'fa-shake', 'fa-border', 'fa-pull-left', 'fa-pull-right', 'fa-li', 'fa-ul', 'fa-stack', 'fa-inverse', 'fa-rotate-90',
             'fa-rotate-180', 'fa-rotate-270', 'fa-flip-horizontal', 'fa-flip-vertical', 'fa-flip-both', 'fa-sr-only'}

files = []
for d in SCAN_DIRS:
    for dirpath, _dirs, names in os.walk(os.path.join(ROOT, d)):
        files += [os.path.join(dirpath, n) for n in names if n.endswith('.php')]
files += [os.path.join(ROOT, f) for f in SCAN_FILES if os.path.exists(os.path.join(ROOT, f))]

solid, regular, brands = set(), set(), set()
for f in files:
    src = open(f, encoding='utf-8').read()
    # explicit style + icon pairs
    for style, name in re.findall(r'\bfa-(solid|regular|brands)\s+fa-([a-z0-9-]+)', src):
        (regular if style == 'regular' else brands if style == 'brands' else solid).add(name)
    for name in re.findall(r'\bfa-([a-z0-9][a-z0-9-]*[a-z0-9])\b', src):
        solid.add(name)
    # fa-{{ $x ? 'a' : 'b' }}
    for expr in re.findall(r'fa-\{\{(.*?)\}\}', src):
        solid.update(re.findall(r"""['"]([a-z0-9-]+)['"]""", expr))
    # 'icon' => 'name' / "icon" => "fa-name" / return 'name'; inside icon() methods
    solid.update(n.removeprefix('fa-') for n in re.findall(r"""['"]icon['"]\s*=>\s*['"](?:fa-)?([a-z0-9-]+)['"]""", src))
    for body in re.findall(r'function icon\(\)[^{]*\{(.*?)\n    \}', src, re.S):
        solid.update(re.findall(r"""['"]([a-z0-9-]+)['"]""", body))
    # icon lists in arrays: [key, label, description, icon] rows
    for arr in re.findall(r"""\[\s*['"][a-z0-9-]+['"]\s*,\s*['"][^'"]*['"]\s*,\s*['"][^'"]*['"]\s*,\s*['"]([a-z0-9-]+)['"]""", src):
        solid.add(arr)

solid = {n for n in solid if f'fa-{n}' not in NON_ICONS}
brands = {n for n in solid | brands if n in by_name and by_name[n]['styles'] == {'brands'}}
regular = {n for n in regular if n in by_name and 'regular' in by_name[n]['styles']}
solid = {n for n in solid if n in by_name and 'solid' in by_name[n]['styles']}

rules = {}
for n in sorted(solid | regular | brands):
    rules[n] = by_name[n]['unicode']

FONTS = [
    # style, the package's font it is cut from, the icons, the cut font (resources/admin/lib), the cut font's own name
    ('solid', 'fa-solid-900.ttf', solid, 'fa-solid.woff', 'AVD Admin Icon Subset Solid', 'AVDAdminIconSubset-Solid'),
    ('regular', 'fa-regular-400.ttf', regular, 'fa-regular.woff', 'AVD Admin Icon Subset Regular', 'AVDAdminIconSubset-Regular'),
    ('brands', 'fa-brands-400.ttf', brands, 'fa-brands.woff', 'AVD Admin Brand Icon Subset', 'AVDAdminBrandIconSubset-Regular'),
]
OFL_NOTICE = ('This Font Software is licensed under the SIL Open Font License, Version 1.1. '
              'This license is available with a FAQ at: https://openfontlicense.org')


def css_text(has_regular, has_brands):
    scss = ["// Generated by scripts/fontawesome/gen_admin_fa_subset.py: the Font Awesome 6.7.2 icons the legacy admin views use.",
            "// Re-run it from the repository root (python scripts/fontawesome/gen_admin_fa_subset.py; it needs fontTools) after a",
            "// legacy view starts using another fa-* icon (tests/Feature/AdminShellTest checks this).",
            "// Font Awesome Free: icons CC BY 4.0, fonts SIL OFL 1.1, code MIT (https://fontawesome.com/license/free).",
            "",
            "@font-face { font-family: 'Font Awesome 6 Free'; font-style: normal; font-weight: 900; font-display: block; src: url('./fa-solid.woff') format('woff'); }"]
    if has_regular:
        scss.append("@font-face { font-family: 'Font Awesome 6 Free'; font-style: normal; font-weight: 400; font-display: block; src: url('./fa-regular.woff') format('woff'); }")
    if has_brands:
        scss.append("@font-face { font-family: 'Font Awesome 6 Brands'; font-style: normal; font-weight: 400; font-display: block; src: url('./fa-brands.woff') format('woff'); }")
    scss += [
        ".fa, .fas, .far, .fab, .fa-solid, .fa-regular, .fa-brands { -moz-osx-font-smoothing: grayscale; -webkit-font-smoothing: antialiased; display: var(--fa-display, inline-block); font-style: normal; font-variant: normal; line-height: 1; text-rendering: auto; }",
        ".fa::before, .fas::before, .far::before, .fab::before, .fa-solid::before, .fa-regular::before, .fa-brands::before { content: var(--fa); }",
        ".fa, .fas, .fa-solid { font-family: 'Font Awesome 6 Free'; font-weight: 900; }",
        ".far, .fa-regular { font-family: 'Font Awesome 6 Free'; font-weight: 400; }",
        ".fab, .fa-brands { font-family: 'Font Awesome 6 Brands'; font-weight: 400; }",
        ".fa-xs { font-size: .75em; line-height: .0833em; vertical-align: .125em; } .fa-sm { font-size: .875em; line-height: .0714em; vertical-align: .0536em; }",
        ".fa-lg { font-size: 1.25em; line-height: .05em; vertical-align: -.075em; } .fa-2x { font-size: 2em; } .fa-3x { font-size: 3em; }",
        ".fa-fw { text-align: center; width: 1.25em; }",
        "@media (prefers-reduced-motion: no-preference) { .fa-spin { animation: ad-fa-spin 2s linear infinite; } }",
        "@keyframes ad-fa-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }",
    ]
    # one rule per codepoint, with every used name (aliases included) as a selector
    by_code = {}
    for n, code in rules.items():
        by_code.setdefault(code, []).append(n)
    for code, names in sorted(by_code.items(), key=lambda kv: kv[1][0]):
        scss.append(', '.join('.fa-' + n for n in sorted(names)) + ' { --fa: "\\' + code + '"; }')
    return '\n'.join(scss) + '\n'


css_path = os.path.join(OUT, '_fa.scss')
css = css_text(bool(regular), bool(brands))
print(f'{len(files)} files; icons used: solid {len(solid)}, regular {len(regular)}, brands {len(brands)}')

if args.check:
    committed = read_text(css_path) or ''
    had = set(re.findall(r'\.fa-([a-z0-9-]+)(?=[ ,][^\n]*--fa: ")', committed))
    now = set(rules)
    print('  would add:', ', '.join(sorted(now - had)) or 'nothing')
    print('  would remove:', ', '.join(sorted(had - now)) or 'nothing')
    print('  resources/admin/lib/_fa.scss:', 'up to date' if committed == css else 'would change')
    sys.exit(0)


def same_font(a, b):
    """True when two font files hold the same tables (only the WOFF wrapper's compression may differ)."""
    from fontTools.ttLib import TTFont
    fa, fb = TTFont(a, lazy=True), TTFont(b, lazy=True)
    try:
        tags = sorted(fa.reader.keys())
        return tags == sorted(fb.reader.keys()) and all(fa.reader[t] == fb.reader[t] for t in tags)
    finally:
        fa.close()
        fb.close()


FA_VERSION = json.load(open(os.path.join(FA, 'package.json'), encoding='utf-8'))['version']


def cut_font(source, codes, out, family, postscript):
    """The package's font cut to the given code points, as WOFF (the subsetter's options of the earlier pyftsubset run:
    every layout feature, no hinting, desubroutinized). A cut font is a Modified Version under the SIL OFL 1.1, which
    reserves the name "Font Awesome": its own name records get another name (the stylesheet's font-family stays the
    one the legacy views ask for), and they carry the copyright notice (kept), the license notice and where the font
    comes from, exactly as the site's cut fonts do (gen_site_fa_subset.py)."""
    from fontTools import subset as ft_subset
    options = ft_subset.Options()
    options.flavor = 'woff'
    options.layout_features = ['*']
    options.hinting = False
    options.desubroutinize = True
    font = ft_subset.load_font(source, options)
    try:
        subsetter = ft_subset.Subsetter(options)
        subsetter.populate(unicodes=[int(c, 16) for c in codes])
        subsetter.subset(font)
        table = font['name']
        style = table.getDebugName(2) or 'Regular'
        platforms = sorted({(r.platformID, r.platEncID, r.langID) for r in table.names if r.nameID == 1}) or [(3, 1, 0x409)]
        for name_id in (1, 3, 4, 6, 10, 13, 14, 16, 17):
            table.removeNames(nameID=name_id)
        records = {
            1: family, 2: style, 3: f'{family}-{FA_VERSION}', 4: family, 6: postscript,
            10: (f'Font Awesome Free {FA_VERSION} by Fonticons, Inc. (https://fontawesome.com), cut to the icons '
                 'the legacy admin views use by scripts/fontawesome/gen_admin_fa_subset.py.'),
            13: OFL_NOTICE, 14: 'https://openfontlicense.org',
        }
        for platform_id, encoding_id, language_id in platforms:
            for name_id, text in records.items():
                table.setName(text, name_id, platform_id, encoding_id, language_id)
        ft_subset.save_font(font, out, options)
    finally:
        font.close()


os.makedirs(OUT, exist_ok=True)
sizes = {}
written = []
with tempfile.TemporaryDirectory(prefix='avd-admin-fa-') as tmp:
    for style, source, names, target, family, postscript in FONTS:
        codes = sorted({by_name[n]['unicode'] for n in names})
        if not codes:
            sizes[style] = 0
            continue
        cut = os.path.join(tmp, target)
        cut_font(os.path.join(FA, 'webfonts', source), codes, cut, family, postscript)
        dest = os.path.join(OUT, target)
        if os.path.exists(dest) and (open(dest, 'rb').read() == open(cut, 'rb').read() or same_font(dest, cut)):
            sizes[style] = os.path.getsize(dest)
            continue
        shutil.copyfile(cut, dest)
        sizes[style] = os.path.getsize(dest)
        written.append('resources/admin/lib/' + target)

if read_text(css_path) != css:
    write_text(css_path, css)
    written.append('resources/admin/lib/_fa.scss')
if args.report:
    with open(args.report, 'w', encoding='utf-8') as fh:
        json.dump({'solid': sorted(solid), 'regular': sorted(regular), 'brands': sorted(brands), 'bytes': sizes}, fh, indent=1)
print('written:', ', '.join(written) or 'nothing (everything was up to date)', '; bytes', sizes)
