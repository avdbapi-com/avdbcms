"""The site's Font Awesome CSS and fonts keep only the icons the site's code names (1.2.3; perf-7, perf-21, R1-071).

Font Awesome 6.7.2's all.css carries 1,895 icon rules (58 KB of the render-blocking main CSS) for every page, and its
fonts are 158 KB (solid), 25 KB (regular) and 116 KB (brands); the layout preloads the solid and regular ones on every
page. The site's views, scripts and PHP code name a few hundred icons; the rest can only come from data an owner types:
a category's icon (App\\Support\\FontAwesomeIconRegistry, any solid icon) and the menu links' icons (Appearance >
Navigation, any name). Those are printed per page by the layout (App\\Support\\IconSubset::extraCss(), from
IconSubsetData::GLYPHS), together with the full solid font then; anything else that still finds no rule loads the full
icon set once (resources/js/layout/icons.ts, the safety net: resources/sass/fa-full.scss).

Writes (all generated, re-run after the views, scripts or stylesheets start using another icon;
tests/Unit/FontAwesomeSubsetTest fails until then):
  resources/sass/_fontawesome.scss   the package's base and utilities, the used icons (+ their aliases) and the font
                                     faces: each style's full font, then its cut font with the cut's unicode-range
                                     (declared last, so it serves the glyphs it has; the full font only loads for a
                                     glyph outside it)
  resources/fonts/fontawesome/       fa-solid-900.woff2, fa-regular-400.woff2, fa-brands-400.woff2: the fonts cut to
                                     the used icons, the code points the site's stylesheets print themselves
                                     (content: "\\f107") and the space
  app/Support/IconSubsetData.php     the used names (SUBSET), every free icon's code point (GLYPHS, for extraCss) and
                                     the code points of each cut font (FONT_SOLID, FONT_REGULAR, FONT_BRANDS)
Each text file keeps the line endings it already has (CRLF in a Windows checkout that converts them, LF otherwise), and
a font whose tables did not change is left as it is (another fontTools version may compress the same font
differently), so a run that finds nothing new changes no file.

The scan cannot follow a variable: an icon a view prints from one (fa-{{ $icon }}) is found only where the source also
names it, as fa-<name> (users/videos/partials/form.blade.php names its visibility icons in a comment for this) or on a
line that has the word icon. tests/Unit/FontAwesomeSubsetTest reads the sources with the same rules.

Needs the npm packages (npm install: node_modules/@fortawesome/fontawesome-free) and, to cut the fonts, fontTools with
brotli (python -m pip install fonttools brotli: WOFF 2). Run it from the repository root:
  python scripts/fontawesome/gen_site_fa_subset.py            write the files
  python scripts/fontawesome/gen_site_fa_subset.py --check    print what the scan finds, write nothing (no fontTools)
  options: [<root>] another checkout to read and write (default: this repository); --report <file.json> also writes
  which source names each icon and the font sizes
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

parser = argparse.ArgumentParser(description='Generate the Font Awesome subset of the main CSS and its fonts.')
parser.add_argument('root', nargs='?', default=REPO, help='the checkout to read and write (default: this repository)')
parser.add_argument('--check', action='store_true', help='print what the scan finds, write nothing')
parser.add_argument('--report', help='also write a JSON file: the icons, the sources that name them, the font sizes')
args = parser.parse_args()

ROOT = args.root
CHECK = args.check
FA = os.path.join(ROOT, 'node_modules', '@fortawesome', 'fontawesome-free')
if not os.path.isdir(FA):
    sys.exit('node_modules/@fortawesome/fontawesome-free not found under ' + ROOT + ': run npm install first')
FONT_DIR = os.path.join(ROOT, 'resources', 'fonts', 'fontawesome')


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


families = json.load(open(os.path.join(FA, 'metadata', 'icon-families.json'), encoding='utf-8'))
by_name = {}
for name, icon in families.items():
    styles = {s['style'] for s in icon.get('familyStylesByLicense', {}).get('free', []) if s.get('family') == 'classic'}
    if not styles:
        continue
    entry = {'name': name, 'unicode': icon['unicode'], 'styles': styles}
    by_name[name] = entry
    for alias in (icon.get('aliases') or {}).get('names', []):
        by_name.setdefault(alias, dict(entry, alias_of=name))

# the package's SCSS maps (name -> $fa-var-name), to emit the subset with the package's own values
variables = open(os.path.join(FA, 'scss', '_variables.scss'), encoding='utf-8').read()


def scss_map(var):
    start = variables.index(var + ': (')
    end = variables.index('\n);', start)
    return dict(re.findall(r'"([^"]+)":\s*(\$fa-var-[a-z0-9-]+)', variables[start:end]))


solid_map = scss_map('$fa-icons')
brand_map = scss_map('$fa-brand-icons')

UTILITIES = {'fa', 'fas', 'far', 'fab', 'fa-solid', 'fa-regular', 'fa-brands', 'fa-classic', 'fa-sharp', 'fa-fw', 'fa-spin',
             'fa-pulse', 'fa-spin-pulse', 'fa-spin-reverse', 'fa-lg', 'fa-sm', 'fa-xs', 'fa-xl', 'fa-2xs', 'fa-2xl', 'fa-1x',
             'fa-2x', 'fa-3x', 'fa-4x', 'fa-5x', 'fa-6x', 'fa-7x', 'fa-8x', 'fa-9x', 'fa-10x', 'fa-beat', 'fa-fade',
             'fa-beat-fade', 'fa-bounce', 'fa-flip', 'fa-shake', 'fa-border', 'fa-pull-left', 'fa-pull-right', 'fa-pull-start',
             'fa-pull-end', 'fa-li', 'fa-ul', 'fa-stack', 'fa-stack-1x', 'fa-stack-2x', 'fa-inverse', 'fa-rotate-90',
             'fa-rotate-180', 'fa-rotate-270', 'fa-rotate-by', 'fa-flip-horizontal', 'fa-flip-vertical', 'fa-flip-both',
             'fa-sr-only', 'fa-sr-only-focusable', 'fa-width-auto', 'fa-layers', 'fa-swap-opacity'}

# what the public site renders: its views (not the admin's own pages: the redesigned admin has its own subset,
# resources/admin/lib/_fa.scss, and the old admin layout links the full set), its scripts, its PHP code
SCAN = [
    ('resources/views', ('.blade.php',), ['resources/views/admin', 'resources/views/components/admin']),
    ('resources/js', ('.ts', '.tsx', '.js'), []),
    ('app', ('.php',), ['app/Http/Controllers/Admin']),
]
EXTRA_FILES = ['resources/views/admin/appearance/partials/draft-banner.blade.php']

files = []
for base, exts, skip in SCAN:
    for dirpath, _dirs, names in os.walk(os.path.join(ROOT, base)):
        rel = os.path.relpath(dirpath, ROOT).replace('\\', '/')
        if any(rel == s or rel.startswith(s + '/') for s in skip):
            continue
        files += [os.path.join(dirpath, n) for n in names if n.endswith(exts)]
files += [os.path.join(ROOT, f) for f in EXTRA_FILES if os.path.exists(os.path.join(ROOT, f))]
# the generated data class itself is not a source
files = [f for f in files if not f.replace('\\', '/').endswith('app/Support/IconSubsetData.php')]

found = {}
quoted = re.compile(r"""['"`]([a-z0-9][a-z0-9-]*)['"`]""")
for f in files:
    src = open(f, encoding='utf-8', errors='replace').read()
    rel = os.path.relpath(f, ROOT).replace('\\', '/')
    names = set()
    # 1. every fa-<name> (classes in markup, strings in scripts and PHP)
    names.update(re.findall(r'(?<![\w-])fa-([a-z0-9](?:[a-z0-9-]*[a-z0-9])?)(?![\w-])', src))
    # 2. fa-{{ ... }} / fa-' . $x . ' / `fa-${...}`: the quoted names inside the expression
    for expr in re.findall(r'fa-\{\{(.*?)\}\}', src) + re.findall(r'fa-\$\{(.*?)\}', src):
        names.update(quoted.findall(expr))
    # 3. a line that names an icon: icon keys, icon props and variables, icon() methods, match arms
    for line in src.splitlines():
        if re.search(r'icon', line, re.I):
            names.update(quoted.findall(line))
    for body in re.findall(r'function icon\(\)[^{]*\{(.*?)\n\s*\}', src, re.S):
        names.update(quoted.findall(body))
    for n in names:
        if ('fa-' + n) in UTILITIES or n in ('solid', 'regular', 'brands'):
            continue
        if n in by_name:
            found.setdefault(n, set()).add(rel)

# the public menus of config/menu.php (front, and the studio's user menu; not its admin menu)
menu_src = open(os.path.join(ROOT, 'config', 'menu.php'), encoding='utf-8').read()
menu_public = menu_src[menu_src.index("'front' => ["):menu_src.index("'admin' => [")]
for n in re.findall(r"""'icon'\s*=>\s*'(?:fa-)?([a-z0-9-]+)'""", menu_public):
    if n in by_name:
        found.setdefault(n, set()).add('config/menu.php')
# the fallbacks code substitutes for an unknown or missing icon (FontAwesomeIconRegistry::FALLBACK, menu links)
for n in ('folder', 'circle'):
    found.setdefault(n, set()).add('fallback')

used_solid = sorted(n for n in found if n in solid_map)
used_brands = sorted(n for n in found if n in brand_map and n not in solid_map)
unknown = sorted(n for n in found if n not in solid_map and n not in brand_map)

# aliases of a used name are emitted too (the package emits each alias as its own map entry)
canon = {}
for n in list(used_solid):
    target = by_name[n].get('alias_of', n)
    canon.setdefault(target, set()).add(n)

# The glyphs the site's own stylesheets print with Font Awesome's font (content: "\f107" in a ::before or ::after):
# they need no icon rule, but the cut fonts must have them. The main and member stylesheets (not the theme token files,
# not this generator's own output or the safety net's full set).
CSS_SCAN = [('resources/sass', ('.scss',)), ('resources/css', ('.css',))]
CSS_SKIP = ('resources/sass/_fontawesome.scss', 'resources/sass/fa-full.scss')
css_codes = {}
for base, exts in CSS_SCAN:
    for dirpath, _dirs, names in os.walk(os.path.join(ROOT, base)):
        rel_dir = os.path.relpath(dirpath, ROOT).replace('\\', '/')
        if rel_dir.startswith('resources/css/themes'):
            continue
        for n in names:
            rel = rel_dir + '/' + n
            if not n.endswith(exts) or rel in CSS_SKIP:
                continue
            for code in re.findall(r"""content\s*:\s*["']\\([ef][0-9a-fA-F]{3})["']""", open(os.path.join(dirpath, n), encoding='utf-8', errors='replace').read()):
                css_codes.setdefault(code.lower(), set()).add(rel)

# The code points each cut font is asked for: the used names whose style has the glyph, and the stylesheets' own
# glyphs that the style has.
wanted = {'solid': set(), 'regular': set(), 'brands': set()}
for n in used_solid:
    for style in ('solid', 'regular'):
        if style in by_name[n]['styles']:
            wanted[style].add(int(by_name[n]['unicode'], 16))
for n in used_brands:
    wanted['brands'].add(int(by_name[n]['unicode'], 16))
by_code_styles = {}
for e in by_name.values():
    by_code_styles.setdefault(e['unicode'], set()).update(e['styles'])
unknown_css_codes = []
for code in sorted(css_codes):
    styles = by_code_styles.get(code, set()) & {'solid', 'regular'}
    if not styles:
        unknown_css_codes.append(code)
    for style in styles:
        wanted[style].add(int(code, 16))

# Characters text may hold that no Font Awesome font draws: white space and invisible format characters. The full
# fonts' unicode-range leaves them out, so a space inside an icon element never downloads a full font; the cut fonts'
# range has U+20 (with no glyph for it, like the full fonts), which keeps them the family's first available font: the
# icons' line metrics stay the font's own, as with the single full font before.
NOT_ICONS = {0x9, 0xA, 0xB, 0xC, 0xD, 0x20, 0x85, 0xA0, 0xAD, 0x1680, 0x2028, 0x2029, 0x202F, 0x205F, 0x2060, 0x3000,
             0xFEFF} | set(range(0x2000, 0x200E))


def as_ranges(points, bridge=False):
    """Code points as [first, last] pairs: consecutive ones joined, or (bridge) any gap without a NOT_ICONS character."""
    parts = []
    for p in sorted(set(points)):
        if parts and (p == parts[-1][1] + 1 or (bridge and not any(parts[-1][1] < c < p for c in NOT_ICONS))):
            parts[-1][1] = p
        else:
            parts.append([p, p])
    return parts


def css_range(parts):
    return ', '.join(f'U+{a:X}' if a == b else f'U+{a:X}-{b:X}' for a, b in parts)


def hex_list(points):
    return '|'.join(f'{p:x}' for p in sorted(points))


print(f'{len(files)} files; icons used: solid/regular {len(used_solid)}, brands {len(used_brands)}; not in the maps: {unknown}')
print(f'stylesheet glyphs: {", ".join(sorted(css_codes))}; not in the fonts: {unknown_css_codes}')
print('cut fonts asked for:', ', '.join(f'{s} {len(c)} glyphs' for s, c in wanted.items()))
if CHECK:
    for n in used_solid + used_brands:
        print(' ', n, '<-', ', '.join(sorted(found[n]))[:150])
    sys.exit(0)

if importlib.util.find_spec('fontTools') is None or importlib.util.find_spec('brotli') is None:
    sys.exit('fontTools and brotli are needed to cut the fonts (WOFF 2): python -m pip install fonttools brotli (nothing was written)')

from fontTools import subset as ft_subset  # noqa: E402 (only needed past --check)
from fontTools.ttLib import TTFont  # noqa: E402

FONTS = [
    # style, the package's font it is cut from, the cut font (resources/fonts/fontawesome), the cut font's own name
    ('solid', 'fa-solid-900.ttf', 'fa-solid-900.woff2', 'AVD Icon Subset Solid', 'AVDIconSubset-Solid'),
    ('regular', 'fa-regular-400.ttf', 'fa-regular-400.woff2', 'AVD Icon Subset Regular', 'AVDIconSubset-Regular'),
    ('brands', 'fa-brands-400.ttf', 'fa-brands-400.woff2', 'AVD Brand Icon Subset', 'AVDBrandIconSubset-Regular'),
]
OFL_NOTICE = ('This Font Software is licensed under the SIL Open Font License, Version 1.1. '
              'This license is available with a FAQ at: https://openfontlicense.org')


def cut_font(source, points, out, family, postscript):
    """The package's font cut to the given code points, as WOFF 2. A cut font is a Modified Version under the SIL OFL
    1.1, which reserves the name "Font Awesome": its own name records get another name (the stylesheet's font-family
    stays 'Font Awesome 6 Free' / 'Font Awesome 6 Brands', the name the site's CSS asks for), and they carry the
    copyright notice, the license notice and where the font comes from."""
    options = ft_subset.Options()
    options.flavor = 'woff2'
    options.layout_features = ['*']
    options.hinting = False
    options.desubroutinize = True
    font = ft_subset.load_font(source, options)
    try:
        subsetter = ft_subset.Subsetter(options)
        subsetter.populate(unicodes=sorted(points))
        subsetter.subset(font)
        table = font['name']
        style = table.getDebugName(2) or 'Regular'
        platforms = sorted({(r.platformID, r.platEncID, r.langID) for r in table.names if r.nameID == 1}) or [(3, 1, 0x409)]
        for name_id in (1, 3, 4, 6, 10, 13, 14, 16, 17):
            table.removeNames(nameID=name_id)
        records = {
            1: family, 2: style, 3: f'{family}-{FA_VERSION}', 4: family, 6: postscript,
            10: (f'Font Awesome Free {FA_VERSION} by Fonticons, Inc. (https://fontawesome.com), cut to the icons '
                 'this site uses by scripts/fontawesome/gen_site_fa_subset.py.'),
            13: OFL_NOTICE, 14: 'https://openfontlicense.org',
        }
        for platform_id, encoding_id, language_id in platforms:
            for name_id, text in records.items():
                table.setName(text, name_id, platform_id, encoding_id, language_id)
        ft_subset.save_font(font, out, options)
    finally:
        font.close()


def same_font(a, b):
    """True when two font files hold the same tables (only the WOFF 2 wrapper's compression may differ)."""
    fa, fb = TTFont(a, lazy=True), TTFont(b, lazy=True)
    try:
        tags = sorted(fa.reader.keys())
        return tags == sorted(fb.reader.keys()) and all(fa.reader[t] == fb.reader[t] for t in tags)
    finally:
        fa.close()
        fb.close()


def cmap_of(path):
    font = TTFont(path, lazy=True)
    try:
        return set(font.getBestCmap() or {})
    finally:
        font.close()


FA_VERSION = json.load(open(os.path.join(FA, 'package.json'), encoding='utf-8'))['version']
os.makedirs(FONT_DIR, exist_ok=True)
sizes = {}
cut_codes = {}
full_ranges = {}
written = []
with tempfile.TemporaryDirectory(prefix='avd-site-fa-') as tmp:
    for style, source, target, family, postscript in FONTS:
        cut = os.path.join(tmp, target)
        cut_font(os.path.join(FA, 'webfonts', source), wanted[style], cut, family, postscript)
        dest = os.path.join(FONT_DIR, target)
        if not (os.path.exists(dest) and (open(dest, 'rb').read() == open(cut, 'rb').read() or same_font(dest, cut))):
            shutil.copyfile(cut, dest)
            written.append('resources/fonts/fontawesome/' + target)
        sizes[style] = os.path.getsize(dest)
        # the glyphs the cut font really has (its unicode-range below says exactly these, and the space)
        cut_codes[style] = cmap_of(dest)
        missing = sorted(wanted[style] - cut_codes[style])
        if missing:
            print(f'warning: the {style} font has no glyph for', ', '.join(f'U+{p:X}' for p in missing),
                  '(the full font serves them)')
        full_ranges[style] = as_ranges(cmap_of(os.path.join(FA, 'webfonts', source)), bridge=True)

scss = '''// Generated by scripts/fontawesome/gen_site_fa_subset.py (1.2.3; perf-7, perf-21, R1-071). Do not edit: re-run it
// from the repository root (python scripts/fontawesome/gen_site_fa_subset.py) after a view, script, PHP class or
// stylesheet starts naming another icon (tests/Unit/FontAwesomeSubsetTest fails until then). Font Awesome Free 6.7.2
// from the npm package's SCSS, as before, with only the icons the site's code names: all of Font Awesome's 1,895 icon
// rules were 58 KB of the render-blocking main CSS on every page. The rest can only come from data an owner types (a
// category's icon, a menu link's icon): the layout prints those rules per page (App\\Support\\IconSubset::extraCss()),
// and anything that still finds no rule loads the full set once (resources/js/layout/icons.ts ->
// resources/sass/fa-full.scss). Font Awesome Free: icons CC BY 4.0, fonts SIL OFL 1.1, code MIT
// (https://fontawesome.com/license/free).
//
// perf-21: font-display swap (not block), so the menu, search, upload and bottom-bar icons are not blank for 1-3 s on
// slow phones. No v4/v5 compatibility font faces: nothing names those families.
//
// R1-071: the fonts are cut to these icons too (resources/fonts/fontawesome, generated with this file): the full solid
// (158 KB) and regular (25 KB) fonts were preloaded on every page and competed with the render-blocking CSS on slow
// phones. Each style declares its full font first and its cut font last: the cut font serves the glyphs it has (its
// unicode-range), the full font any other icon glyph (an icon an owner typed: the layout preloads the full solid font on
// those pages, components/layout.blade.php). The full fonts' range leaves out white space, so a space inside an icon
// element downloads nothing; the cut fonts' range has U+20, which keeps the icons' line metrics the font's own.
$fa-font-display: swap;
// Relative to app.scss, the entry the compiled urls are resolved from (Vite does not rebase the package's interpolated
// "#{$fa-font-path}" urls): the same node_modules files, and manifest keys, as before; the cut fonts beside them.
$fa-font-path: "../../node_modules/@fortawesome/fontawesome-free/webfonts";
$avd-fa-cut-path: "../fonts/fontawesome";

@import "@fortawesome/fontawesome-free/scss/functions";
@import "@fortawesome/fontawesome-free/scss/variables";

// The icons the site's code names (solid and regular share these rules; the style class picks the font).
$fa-icons: (
''' + ''.join(f'  "{n}": {solid_map[n]},\n' for n in used_solid) + ''');

@import "@fortawesome/fontawesome-free/scss/mixins";
@import "@fortawesome/fontawesome-free/scss/core";
@import "@fortawesome/fontawesome-free/scss/sizing";
@import "@fortawesome/fontawesome-free/scss/fixed-width";
@import "@fortawesome/fontawesome-free/scss/list";
@import "@fortawesome/fontawesome-free/scss/bordered-pulled";
@import "@fortawesome/fontawesome-free/scss/animated";
@import "@fortawesome/fontawesome-free/scss/rotated-flipped";
@import "@fortawesome/fontawesome-free/scss/stacked";
@import "@fortawesome/fontawesome-free/scss/icons";
@import "@fortawesome/fontawesome-free/scss/screen-reader";

// Solid and regular: the custom properties and style rules of the package's solid.scss and regular.scss; each style's
// full font, then its cut font.
:root, :host {
  --#{$fa-css-prefix}-style-family-classic: '#{ $fa-style-family }';
  --#{$fa-css-prefix}-font-solid: normal 900 1em/1 '#{ $fa-style-family }';
  --#{$fa-css-prefix}-font-regular: normal 400 1em/1 '#{ $fa-style-family }';
}

@font-face {
  font-family: 'Font Awesome 6 Free';
  font-style: normal;
  font-weight: 900;
  font-display: $fa-font-display;
  src: url('#{$fa-font-path}/fa-solid-900.woff2') format('woff2'),
    url('#{$fa-font-path}/fa-solid-900.ttf') format('truetype');
  unicode-range: ''' + css_range(full_ranges['solid']) + ''';
}

@font-face {
  font-family: 'Font Awesome 6 Free';
  font-style: normal;
  font-weight: 900;
  font-display: $fa-font-display;
  src: url('#{$avd-fa-cut-path}/fa-solid-900.woff2') format('woff2');
  unicode-range: ''' + css_range(as_ranges(cut_codes['solid'] | {0x20})) + ''';
}

.fas,
.#{$fa-css-prefix}-solid {
  font-weight: 900;
}

@font-face {
  font-family: 'Font Awesome 6 Free';
  font-style: normal;
  font-weight: 400;
  font-display: $fa-font-display;
  src: url('#{$fa-font-path}/fa-regular-400.woff2') format('woff2'),
    url('#{$fa-font-path}/fa-regular-400.ttf') format('truetype');
  unicode-range: ''' + css_range(full_ranges['regular']) + ''';
}

@font-face {
  font-family: 'Font Awesome 6 Free';
  font-style: normal;
  font-weight: 400;
  font-display: $fa-font-display;
  src: url('#{$avd-fa-cut-path}/fa-regular-400.woff2') format('woff2');
  unicode-range: ''' + css_range(as_ranges(cut_codes['regular'] | {0x20})) + ''';
}

.far,
.#{$fa-css-prefix}-regular {
  font-weight: 400;
}

// Brands: the custom properties, font faces and style rule of the package's brands.scss, with only the brand icons the
// site's code names (the package file prints all of them).
:root, :host {
  --#{$fa-css-prefix}-style-family-brands: 'Font Awesome 6 Brands';
  --#{$fa-css-prefix}-font-brands: normal 400 1em/1 'Font Awesome 6 Brands';
}

@font-face {
  font-family: 'Font Awesome 6 Brands';
  font-style: normal;
  font-weight: 400;
  font-display: $fa-font-display;
  src: url('#{$fa-font-path}/fa-brands-400.woff2') format('woff2'),
    url('#{$fa-font-path}/fa-brands-400.ttf') format('truetype');
  unicode-range: ''' + css_range(full_ranges['brands']) + ''';
}

@font-face {
  font-family: 'Font Awesome 6 Brands';
  font-style: normal;
  font-weight: 400;
  font-display: $fa-font-display;
  src: url('#{$avd-fa-cut-path}/fa-brands-400.woff2') format('woff2');
  unicode-range: ''' + css_range(as_ranges(cut_codes['brands'] | {0x20})) + ''';
}

.fab,
.#{$fa-css-prefix}-brands {
  font-weight: 400;
}

$avd-fa-brand-icons: (
''' + ''.join(f'  "{n}": {brand_map[n]},\n' for n in used_brands) + ''');

@each $name, $icon in $avd-fa-brand-icons {
  .#{$fa-css-prefix}-#{$name} { #{$fa-icon-property}: unquote("\\"#{ $icon }\\""); }
}
'''

# every free icon's code point (for names an owner types), and the subset's names
glyphs = {}
for n, e in by_name.items():
    if n in solid_map or n in brand_map:
        glyphs[n] = e['unicode']
glyph_text = '|'.join(f'{n}={glyphs[n]}' for n in sorted(glyphs))
subset_names = sorted(set(used_solid) | set(used_brands))


def php_chunks(text, width=110):
    parts = [text[i:i + width] for i in range(0, len(text), width)]
    return "\n        .".join("'" + p.replace('\\', '\\\\').replace("'", "\\'") + "'" for p in parts)


php = f'''<?php

namespace App\\Support;

/**
 * Generated by scripts/fontawesome/gen_site_fa_subset.py together with resources/sass/_fontawesome.scss and the cut fonts
 * of resources/fonts/fontawesome. Do not edit; re-run the generator from the repository root (python
 * scripts/fontawesome/gen_site_fa_subset.py). Font Awesome Free 6.7.2 (icons CC BY 4.0, fonts SIL OFL 1.1).
 *
 * SUBSET: the icon names the main CSS carries (the site's code names them). GLYPHS: every free icon name (aliases
 * included) with its code point, for the names an owner types (App\\Support\\IconSubset::extraCss()). FONT_SOLID,
 * FONT_REGULAR, FONT_BRANDS: the code points each cut font has a glyph for (its unicode-range in the stylesheet is these
 * and U+20); any other icon glyph comes from the full font.
 */
final class IconSubsetData
{{
    public const string VERSION = '6.7.2';

    public const string SUBSET =
        {php_chunks('|'.join(subset_names))};

    public const string GLYPHS =
        {php_chunks(glyph_text)};

    public const string FONT_SOLID =
        {php_chunks(hex_list(cut_codes['solid']))};

    public const string FONT_REGULAR =
        {php_chunks(hex_list(cut_codes['regular']))};

    public const string FONT_BRANDS =
        {php_chunks(hex_list(cut_codes['brands']))};
}}
'''

write_text(os.path.join(ROOT, 'resources', 'sass', '_fontawesome.scss'), scss)
write_text(os.path.join(ROOT, 'app', 'Support', 'IconSubsetData.php'), php)
written += ['resources/sass/_fontawesome.scss', 'app/Support/IconSubsetData.php']
if args.report:
    with open(args.report, 'w', encoding='utf-8') as fh:
        json.dump({'solid_regular': used_solid, 'brands': used_brands, 'unknown': unknown,
                   'stylesheet_glyphs': {c: sorted(s) for c, s in css_codes.items()},
                   'font_codes': {s: hex_list(c).split('|') for s, c in cut_codes.items()}, 'font_bytes': sizes,
                   'full_font_ranges': {s: css_range(r) for s, r in full_ranges.items()},
                   'sources': {n: sorted(found[n]) for n in used_solid + used_brands}}, fh, indent=1)
print('written:', ', '.join(written) + ';', len(glyphs), 'glyphs; cut font bytes', sizes)
