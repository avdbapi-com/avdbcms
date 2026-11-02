"""Member danger tokens: danger that is clearly NOT the brand, in every theme token block (1.2.3, member design 3.1a).

Why: in 88 of the 348 token blocks the theme's danger colour is within CIEDE2000 20 of its brand colour (0.8 in the
default look: Save and Delete were one red). The member kit therefore never reads --avd-danger* directly. It reads
four kit tokens:

    --mk-danger       danger ink   (error text, danger links and icons, invalid field borders, danger dots)
    --mk-danger-rgb   the same ink as "r, g, b" (danger tints .10-.12)
    --mk-danger-fill  danger fill  (the one filled "Delete ..." button inside a confirmation sheet)
    --mk-on-danger    label on the danger fill

Default: the theme's own tokens (var(--avd-danger) ...). For every block where the theme's danger is closer than
dE00 22 (20 + a rounding margin) to the brand, this tool picks the nearest colour to the theme's danger that
  - is >= dE00 22 from --avd-brand-ink AND --avd-brand-fill (ink and fill both),
  - the fill is >= dE00 20 from every stop of --avd-btn-bg (the primary button) and from the brand ink,
  - keeps text contrast >= 4.5:1 on --avd-bg, --avd-surface-1, --avd-surface-2, --avd-surface-solid (composited),
  - keeps the label on the fill >= 4.5:1 (white first, then the theme's on-danger, then near-black),
  - stays in the danger family: red (CIELAB hue 10-45) unless the brand itself is red/pink, then vermilion/orange
    (hue 46-72, the "Netflix error orange" family), and prefers >= dE00 10 from the warning ink.
Two strategies for the blocks within dE00 22 of their brand (owner decision OD-M11), chosen with --strategy (default:
neutral, the one the site ships):
  neutral    danger = the look's TEXT colour (ink and fill), the label on the fill = the look's background (an
             inverse button), tints and outlines in the neutral channel (--avd-glint-rgb dark / --avd-shade-rgb
             light). Written as var() references, so an owner's custom colours are followed. Danger is carried by the
             icon and the word (kit rule 7). Far from the brand AND from the warning ink in every block.
  generated  a generated colour: vermilion / orange / amber in dark mode, a dark brown (L* 28-32) in light mode.

Reads the theme token blocks of resources/css/themes (<theme>.css, dark and light, and every colour style
<theme>/<flavor>.css merged over its base block) and writes app/Support/Member/DangerMapData.php: the blocks that get
other member danger tokens than the theme's own (keyed theme|mode or theme/flavor|mode), with the line endings the
file already has (CRLF in a Windows checkout that converts them, LF otherwise), so a run after an unchanged theme
leaves it byte for byte as it was. App\\Support\\Member\\DangerMap prints the page's own look's rules next to the member
CSS entry, keyed on html[data-avd-theme][data-avd-flavor][data-bs-theme] (the attributes components/layout prints).
Until 1.2.3 round 1 the map was resources/sass/member/_danger.scss, all 348 blocks in the member CSS entry: 11.6 KB of
render-blocking CSS on every page of a signed-in visitor, of which a page needs at most four rules (R1-070). Every
block's three options (A generated, B the theme's own red, C neutral) are measured into the optional --report file.

Run it from the repository root after a theme change:
  python scripts/member/danger_tokens.py [--quiet] [--strategy neutral|generated] [--report <file.json>] [<root>]
  (<root>: another checkout to read and write; default: this repository)
"""
import argparse
import glob
import json
import math
import os
import re
import sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

MIN_DE = 22.0        # target (the spec's bar is 20; 2 of margin for rounding and compositing)
BAR = 20.0           # the bar the check enforces
WARN_PREF = 12.0     # preferred distance from the warning ink


# ---------------------------------------------------------------------------------------------------------------
# theme token blocks and WCAG contrast
# ---------------------------------------------------------------------------------------------------------------
BLOCK = re.compile(r'html\[data-bs-theme="(dark|light)"\](?:\[data-avd-flavor="([a-z0-9_]+)"\])?\s*\{([^}]*)\}')
DECL = re.compile(r'(--[a-z0-9-]+)\s*:\s*([^;]+);')
RGBA = re.compile(r'rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)\s*(?:,\s*([0-9.]+%?)\s*)?\)')


def parse_color(text):
    """Return list of (r,g,b,a) colours found in text (a gradient gives several)."""
    out = []
    for m in re.finditer(r'#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)', text):
        s = m.group(0)
        if s.startswith('#'):
            h = s[1:]
            if len(h) in (3, 4):
                h = ''.join(c * 2 for c in h)
            r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
            a = int(h[6:8], 16) / 255 if len(h) == 8 else 1.0
            out.append((r, g, b, a))
        else:
            mm = RGBA.match(s)
            if mm:
                r, g, b = float(mm.group(1)), float(mm.group(2)), float(mm.group(3))
                a = mm.group(4)
                if a is None:
                    a = 1.0
                elif a.endswith('%'):
                    a = float(a[:-1]) / 100
                else:
                    a = float(a)
                out.append((r, g, b, a))
    return out


def resolve(tokens, value, depth=0):
    """Resolve var(--x) references inside a token value."""
    if depth > 6:
        return value
    def rep(m):
        name = m.group(1)
        fallback = m.group(2)
        if name in tokens:
            return resolve(tokens, tokens[name], depth + 1)
        return fallback or ''
    return re.sub(r'var\((--[a-z0-9-]+)(?:\s*,\s*([^)]+))?\)', rep, value)


def over(fg, bg):
    """Composite fg (r,g,b,a) over opaque bg (r,g,b)."""
    r, g, b, a = fg
    return (r * a + bg[0] * (1 - a), g * a + bg[1] * (1 - a), b * a + bg[2] * (1 - a))


def lum(c):
    def ch(v):
        v = v / 255
        return v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4
    return 0.2126 * ch(c[0]) + 0.7152 * ch(c[1]) + 0.0722 * ch(c[2])


def ratio(a, b):
    la, lb = lum(a), lum(b)
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)


def colours(tokens, name):
    v = tokens.get(name)
    if v is None:
        return []
    return parse_color(resolve(tokens, v))


def opaque_surface(tokens, name):
    """A surface token composited over --avd-bg (glass surfaces are translucent)."""
    bg = colours(tokens, '--avd-bg')
    base = (bg[0][0], bg[0][1], bg[0][2]) if bg else (0, 0, 0)
    cs = colours(tokens, name)
    if not cs:
        return None
    return over(cs[0], base)


def load(themes):
    """Every token block: (theme, mode, flavor) -> {token: value}; a colour style's block is merged over its base."""
    blocks = {}
    for f in sorted(glob.glob(os.path.join(themes, '*.css'))):
        theme = os.path.basename(f)[:-4]
        txt = open(f, encoding='utf-8').read()
        for mode, flavor, body in BLOCK.findall(txt):
            blocks[(theme, mode, '')] = dict(DECL.findall(body))
        for ff in sorted(glob.glob(os.path.join(themes, theme, '*.css'))):
            flav = os.path.basename(ff)[:-4]
            t2 = open(ff, encoding='utf-8').read()
            for mode, flavor, body in BLOCK.findall(t2):
                base = dict(blocks.get((theme, mode, ''), {}))
                base.update(dict(DECL.findall(body)))
                blocks[(theme, mode, flavor or flav)] = base
    return blocks


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


# ---------------------------------------------------------------------------------------------------------------
# colour math (sRGB D65 <-> CIELAB, CIEDE2000)
# ---------------------------------------------------------------------------------------------------------------
def _lin(v):
    v = v / 255
    return v / 12.92 if v <= 0.04045 else ((v + 0.055) / 1.055) ** 2.4


def _unlin(v):
    v = 12.92 * v if v <= 0.0031308 else 1.055 * (v ** (1 / 2.4)) - 0.055
    return v * 255


def srgb_to_lab(c):
    r, g, b = (_lin(x) for x in c[:3])
    x = (0.4124 * r + 0.3576 * g + 0.1805 * b) / 0.95047
    y = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 1.0
    z = (0.0193 * r + 0.1192 * g + 0.9505 * b) / 1.08883

    def f(t):
        return t ** (1 / 3) if t > 216 / 24389 else (24389 / 27 * t + 16) / 116
    fx, fy, fz = f(x), f(y), f(z)
    return 116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)


def lab_to_srgb(L, a, b):
    fy = (L + 16) / 116
    fx = fy + a / 500
    fz = fy - b / 200

    def finv(t):
        return t ** 3 if t ** 3 > 216 / 24389 else (116 * t - 16) / (24389 / 27)
    x, y, z = finv(fx) * 0.95047, finv(fy), finv(fz) * 1.08883
    r = 3.2404542 * x - 1.5371385 * y - 0.4985314 * z
    g = -0.9692660 * x + 1.8760108 * y + 0.0415560 * z
    bb = 0.0556434 * x - 0.2040259 * y + 1.0572252 * z
    out = []
    for v in (r, g, bb):
        if v < -0.0005 or v > 1.0005:
            return None
        out.append(round(min(255, max(0, _unlin(min(1, max(0, v)))))))
    return tuple(out)


def lch(lab):
    L, a, b = lab
    return L, math.hypot(a, b), math.degrees(math.atan2(b, a)) % 360


def de2000(lab1, lab2):
    L1, a1, b1 = lab1
    L2, a2, b2 = lab2
    C1 = math.hypot(a1, b1)
    C2 = math.hypot(a2, b2)
    Cm = (C1 + C2) / 2
    G = 0.5 * (1 - math.sqrt(Cm ** 7 / (Cm ** 7 + 25 ** 7)))
    a1p, a2p = (1 + G) * a1, (1 + G) * a2
    C1p, C2p = math.hypot(a1p, b1), math.hypot(a2p, b2)
    h1p = math.degrees(math.atan2(b1, a1p)) % 360
    h2p = math.degrees(math.atan2(b2, a2p)) % 360
    dLp = L2 - L1
    dCp = C2p - C1p
    if C1p * C2p == 0:
        dhp = 0
    elif abs(h2p - h1p) <= 180:
        dhp = h2p - h1p
    else:
        dhp = h2p - h1p - 360 if h2p > h1p else h2p - h1p + 360
    dHp = 2 * math.sqrt(C1p * C2p) * math.sin(math.radians(dhp / 2))
    Lpm = (L1 + L2) / 2
    Cpm = (C1p + C2p) / 2
    if C1p * C2p == 0:
        hpm = h1p + h2p
    elif abs(h1p - h2p) <= 180:
        hpm = (h1p + h2p) / 2
    else:
        hpm = (h1p + h2p + 360) / 2 if h1p + h2p < 360 else (h1p + h2p - 360) / 2
    T = (1 - 0.17 * math.cos(math.radians(hpm - 30)) + 0.24 * math.cos(math.radians(2 * hpm))
         + 0.32 * math.cos(math.radians(3 * hpm + 6)) - 0.20 * math.cos(math.radians(4 * hpm - 63)))
    dtheta = 30 * math.exp(-(((hpm - 275) / 25) ** 2))
    Rc = 2 * math.sqrt(Cpm ** 7 / (Cpm ** 7 + 25 ** 7))
    Sl = 1 + (0.015 * (Lpm - 50) ** 2) / math.sqrt(20 + (Lpm - 50) ** 2)
    Sc = 1 + 0.045 * Cpm
    Sh = 1 + 0.015 * Cpm * T
    Rt = -math.sin(math.radians(2 * dtheta)) * Rc
    return math.sqrt((dLp / Sl) ** 2 + (dCp / Sc) ** 2 + (dHp / Sh) ** 2 + Rt * (dCp / Sc) * (dHp / Sh))


def hexs(c):
    return '#%02x%02x%02x' % tuple(int(round(x)) for x in c[:3])


# ---------------------------------------------------------------------------------------------------------------
# candidate grid (block independent): danger family hues, in gamut
# ---------------------------------------------------------------------------------------------------------------
FAMILIES = {'red': (-12, 45), 'orange': (46, 72)}


def build_grid():
    grid = {name: [] for name in FAMILIES}
    for name, (h0, h1) in FAMILIES.items():
        for h in range(h0, h1 + 1, 2):
            for C in range(30, 116, 4):
                for L in range(28, 92):
                    a = C * math.cos(math.radians(h))
                    b = C * math.sin(math.radians(h))
                    rgb = lab_to_srgb(L, a, b)
                    if rgb is None:
                        continue
                    lab = srgb_to_lab(rgb)
                    grid[name].append((rgb, lab, lum(rgb)))
    # dedupe identical rgb
    for name in grid:
        seen = {}
        for item in grid[name]:
            seen.setdefault(item[0], item)
        grid[name] = list(seen.values())
    return grid


def ratio_l(l1, l2):
    hi, lo = max(l1, l2), min(l1, l2)
    return (hi + 0.05) / (lo + 0.05)


# ---------------------------------------------------------------------------------------------------------------
# per block
# ---------------------------------------------------------------------------------------------------------------
def first(tokens, name, base):
    cs = colours(tokens, name)
    if not cs:
        return None
    return over(cs[0], base)


def all_stops(tokens, name, base):
    return [over(c, base) for c in colours(tokens, name)]


def block_inputs(tokens):
    bgc = colours(tokens, '--avd-bg')
    bg = (bgc[0][0], bgc[0][1], bgc[0][2]) if bgc else (0, 0, 0)
    surfaces = [bg]
    for s in ('--avd-surface-1', '--avd-surface-2', '--avd-surface-solid'):
        o = opaque_surface(tokens, s)
        if o is not None:
            surfaces.append(o)
    s1 = opaque_surface(tokens, '--avd-surface-1') or bg
    return {
        'bg': bg,
        'surfaces': surfaces,
        'brand_ink': first(tokens, '--avd-brand-ink', bg),
        'brand_fill': first(tokens, '--avd-brand-fill', s1),
        'btn': all_stops(tokens, '--avd-btn-bg', s1),
        'danger': first(tokens, '--avd-danger', bg),
        'danger_fill': first(tokens, '--avd-danger-fill', s1),
        'on_danger': first(tokens, '--avd-on-danger', s1),
        'warning': first(tokens, '--avd-warning', bg),
        'warning_fill': first(tokens, '--avd-warning-fill', s1),
    }


def measure(inp, ink, fill, label):
    lb_i, lb_f = srgb_to_lab(inp['brand_ink']), srgb_to_lab(inp['brand_fill'])
    li, lf = srgb_to_lab(ink), srgb_to_lab(fill)
    out = {
        'ink_vs_brand_ink': de2000(lb_i, li),
        'ink_vs_brand_fill': de2000(lb_f, li),
        'fill_vs_brand_fill': de2000(lb_f, lf),
        'fill_vs_brand_ink': de2000(lb_i, lf),
        'fill_vs_primary_button': min(de2000(srgb_to_lab(s), lf) for s in inp['btn']) if inp['btn'] else None,
        'ink_vs_warning': de2000(srgb_to_lab(inp['warning']), li) if inp['warning'] else None,
        'ink_contrast_min': min(ratio(ink, s) for s in inp['surfaces']),
        'label_on_fill': ratio(label, fill),
    }
    out['min_brand_de'] = min(out['ink_vs_brand_ink'], out['ink_vs_brand_fill'], out['fill_vs_brand_fill'], out['fill_vs_brand_ink'])
    return out


def brand_is_red(inp):
    L, C, h = lch(srgb_to_lab(inp['brand_ink']))
    L2, C2, h2 = lch(srgb_to_lab(inp['brand_fill']))
    red = lambda hh, cc: cc >= 35 and (hh >= 335 or hh <= 42)
    return red(h, C) or red(h2, C2)


FAMILY_CENTRE = {'red': None, 'orange': 60}
INK_CHROMA_MIN = {'dark': 48, 'light': 40}
FILL_CHROMA_MIN = 50


def orange_centre(inp):
    """Where the orange target sits: 30 degrees past the brand's own hue (a pink or crimson brand gets a vermilion
    danger, a pure red brand an orange one), clamped to 46-64 so it never becomes the amber of warning."""
    h = lch(srgb_to_lab(inp['brand_ink']))[2]
    h = h - 360 if h > 300 else h
    return max(46.0, min(64.0, h + 30))


def family_target(lab_orig, fam, mode='dark', centre=None):
    """The theme's danger turned into the family's hue, keeping its vividness: a vivid orange (never a muddy brown or
    a pale peach) when the red family is taken by the brand. Dark looks get a light, legible orange (L >= 66: the
    "error orange" of dark video apps); light looks a deep burnt orange (L <= 42) that holds 4.5:1 on cream and grey."""
    if FAMILY_CENTRE[fam] is None:
        return lab_orig
    L, C, h = lch(lab_orig)
    L = max(L, 66) if mode == 'dark' else min(L, 42)
    hh = math.radians(centre if centre is not None else FAMILY_CENTRE[fam])
    return (L, C * math.cos(hh), C * math.sin(hh))


def pick_ink(inp, grid, mode):
    lb_i, lb_f = srgb_to_lab(inp['brand_ink']), srgb_to_lab(inp['brand_fill'])
    ld = srgb_to_lab(inp['danger'])
    lw = srgb_to_lab(inp['warning']) if inp['warning'] else None
    order = ['orange', 'red'] if brand_is_red(inp) else ['red', 'orange']
    cmin = INK_CHROMA_MIN[mode]
    # The preferred family is exhausted (strict, then with the warning distance relaxed) before the next family is
    # tried: a red-brand look gets an orange danger, not a raspberry one.
    for fam in order:
        for relax in (False, True):
            target = family_target(ld, fam, mode, orange_centre(inp))
            best = None
            for rgb, lab, lm in grid[fam]:
                if lch(lab)[1] < (cmin if not relax else cmin - 10):
                    continue
                if min(ratio_l(lm, lum(s)) for s in inp['surfaces']) < 4.5:
                    continue
                if de2000(lb_i, lab) < MIN_DE or de2000(lb_f, lab) < MIN_DE:
                    continue
                cost = de2000(target, lab)
                if lw is not None:
                    dw = de2000(lw, lab)
                    if dw < WARN_PREF:
                        if not relax:
                            continue
                        cost += (WARN_PREF - dw) * 3
                if best is None or cost < best[0]:
                    best = (cost, rgb, lab)
            if best:
                return best[1], fam, relax
    return None, None, None


LABELS = [(255, 255, 255)]


def pick_fill(inp, grid, ink_lab, fam, dark_label_look, mode='dark'):
    lb_i, lb_f = srgb_to_lab(inp['brand_ink']), srgb_to_lab(inp['brand_fill'])
    ldf = srgb_to_lab(inp['danger_fill'])
    btn = [srgb_to_lab(s) for s in inp['btn']]
    lwf = srgb_to_lab(inp['warning_fill']) if inp['warning_fill'] else None
    white, dark = (255, 255, 255), (11, 11, 15)
    labels = [dark, white] if dark_label_look else [white, dark]
    fams = [fam] + [f for f in FAMILIES if f != fam]
    for fm in fams:
        for relax in (False, True):
            # A fill carries a label: a light fill for looks whose labels are near-black (dark-label looks), a deep
            # fill for looks with white labels, whatever the mode.
            target = family_target(ldf, fm, 'dark' if dark_label_look else 'light', orange_centre(inp))
            best = None
            for rgb, lab, lm in grid[fm]:
                if lch(lab)[1] < (FILL_CHROMA_MIN if not relax else FILL_CHROMA_MIN - 12):
                    continue
                label = None
                for lab_c in labels:
                    if ratio(lab_c, rgb) >= 4.5:
                        label = lab_c
                        break
                if label is None:
                    continue
                if de2000(lb_f, lab) < MIN_DE or de2000(lb_i, lab) < BAR:
                    continue
                if btn and min(de2000(s, lab) for s in btn) < BAR:
                    continue
                cost = de2000(target, lab) + 0.6 * abs(lch(lab)[2] - lch(ink_lab)[2])
                if label != labels[0]:
                    cost += 3  # the look's own label colour first (white, or near-black in dark-label looks)
                if lwf is not None:
                    dw = de2000(lwf, lab)
                    if dw < WARN_PREF:
                        if not relax:
                            continue
                        cost += (WARN_PREF - dw) * 3
                if best is None or cost < best[0]:
                    best = (cost, rgb, label)
            if best:
                return best[1], best[2]
    return None, None


def selector(theme, mode, flavor, quoted=True):
    q = '"' if quoted else ''
    if flavor:
        return f'html[data-avd-theme={q}{theme}{q}][data-avd-flavor={q}{flavor}{q}][data-bs-theme={q}{mode}{q}]'
    return f'html[data-avd-theme={q}{theme}{q}][data-bs-theme={q}{mode}{q}]'


# The kit tokens are set on <html> itself. They only exist where the member CSS entry is printed (DangerMap prints the
# look's rules beside it), and only kit rules read them: public pages and the look's own --avd-danger* are untouched.
def css_block(sel, ink, fill, label):
    return (f'{sel}{{--mk-danger:{hexs(ink)};--mk-danger-rgb:{int(ink[0])},{int(ink[1])},{int(ink[2])};'
            f'--mk-danger-fill:{hexs(fill)};--mk-on-danger:{hexs(label)}}}')


def css_neutral(sel, mode):
    ch = 'var(--avd-glint-rgb)' if mode == 'dark' else 'var(--avd-shade-rgb)'
    return (f'{sel}{{--mk-danger:var(--avd-text);--mk-danger-rgb:{ch};'
            f'--mk-danger-fill:var(--avd-text);--mk-on-danger:var(--avd-bg)}}')


def neutral_colours(tokens, inp):
    """The resolved colours of the neutral option: text (ink and fill) and the background (label)."""
    text = first(tokens, '--avd-text', inp['bg'])
    return text, text, inp['bg']


def rounded(m):
    return {k: (round(v, 2) if isinstance(v, float) else v) for k, v in m.items()}


def css_reset(sel):
    return (f'{sel}{{--mk-danger:var(--avd-danger);--mk-danger-rgb:var(--avd-danger-rgb);'
            f'--mk-danger-fill:var(--avd-danger-fill);--mk-on-danger:var(--avd-on-danger)}}')


def main():
    parser = argparse.ArgumentParser(description='Generate app/Support/Member/DangerMapData.php (the member danger tokens per look) from the theme token blocks.')
    parser.add_argument('root', nargs='?', default=REPO, help='the checkout to read and write (default: this repository)')
    parser.add_argument('--strategy', choices=('neutral', 'generated'), default='neutral')
    parser.add_argument('--quiet', action='store_true', help='print the summary only')
    parser.add_argument('--report', help='also write a JSON file: every block, its options and the chosen colours')
    args = parser.parse_args()
    quiet = args.quiet
    strategy = args.strategy
    themes = os.path.join(args.root, 'resources', 'css', 'themes')
    if not os.path.isdir(themes):
        sys.exit('resources/css/themes not found under ' + args.root)
    blocks = load(themes)
    grid = build_grid()
    results = {}
    for key, tokens in sorted(blocks.items()):
        theme, mode, flavor = key
        inp = block_inputs(tokens)
        if None in (inp['brand_ink'], inp['brand_fill'], inp['danger'], inp['danger_fill']):
            continue
        before = measure(inp, inp['danger'], inp['danger_fill'], inp['on_danger'] or (255, 255, 255))
        rec = {'theme': theme, 'mode': mode, 'flavor': flavor, 'before': before,
               'theme_danger': hexs(inp['danger']), 'theme_danger_fill': hexs(inp['danger_fill']),
               'brand_ink': hexs(inp['brand_ink']), 'brand_fill': hexs(inp['brand_fill'])}
        needs = (before['min_brand_de'] < MIN_DE or (before['fill_vs_primary_button'] or 99) < BAR)
        n_ink, n_fill, n_label = neutral_colours(tokens, inp)
        rec['options'] = {'B_theme': rounded(before), 'C_neutral': rounded(measure(inp, n_ink, n_fill, n_label)),
                          'neutral_colours': [hexs(n_ink), hexs(n_label)],
                          'warning': hexs(inp['warning']) if inp['warning'] else None}
        if needs:
            # option A (the generated colour) is measured for every block that needs an override, whatever the strategy
            g_ink, g_fam, g_relaxed = pick_ink(inp, grid, mode)
            g_fill = g_label = None
            if g_ink is not None:
                on_brand = first(tokens, '--avd-on-brand', inp['bg'])
                g_fill, g_label = pick_fill(inp, grid, srgb_to_lab(g_ink), g_fam, on_brand is not None and lum(on_brand) < 0.2, mode)
            if g_fill is not None:
                rec['options']['A_generated'] = rounded(measure(inp, g_ink, g_fill, g_label))
                rec['options']['A_colours'] = [hexs(g_ink), hexs(g_fill), hexs(g_label), round(srgb_to_lab(g_ink)[0], 1),
                                               round(srgb_to_lab(g_fill)[0], 1), round(lch(srgb_to_lab(g_ink))[1], 1)]
        n_meas = measure(inp, n_ink, n_fill, n_label)
        neutral_ok = n_meas['min_brand_de'] >= MIN_DE and (n_meas['fill_vs_primary_button'] or 99) >= MIN_DE
        if needs and strategy == 'neutral' and not neutral_ok and g_fill is None:
            neutral_ok = True  # no generated colour either: keep the neutral one and let the check report it
        if needs and strategy == 'neutral' and neutral_ok:
            ink, fill, label = n_ink, n_fill, n_label
            rec.update({'override': True, 'family': 'neutral', 'warning_relaxed': False, 'danger': hexs(ink),
                        'danger_fill': hexs(fill), 'on_danger': hexs(label), 'strategy': 'neutral',
                        'after': measure(inp, ink, fill, label), '_ink': ink, '_fill': fill, '_label': label})
        elif needs:
            # strategy generated, or a pastel brand too close to the look's own text colour for the neutral danger
            # (fairy_floss/sakura, obsidian_gold/rose, synthwave_sunset): the generated colour for this block
            ink, fam, relaxed = pick_ink(inp, grid, mode)
            if ink is None:
                rec['error'] = 'no ink candidate'; rec['override'] = False
                results['/'.join(k for k in key if k)] = rec
                continue
            on_brand = first(tokens, '--avd-on-brand', inp['bg'])
            dark_label_look = on_brand is not None and lum(on_brand) < 0.2
            fill, label = pick_fill(inp, grid, srgb_to_lab(ink), fam, dark_label_look, mode)
            if fill is None:
                rec['error'] = 'no fill candidate'; rec['override'] = False
                results['/'.join(k for k in key if k)] = rec
                continue
            rec.update({'override': True, 'family': fam, 'warning_relaxed': relaxed, 'danger': hexs(ink),
                        'danger_fill': hexs(fill), 'on_danger': hexs(label), 'strategy': 'generated',
                        'after': measure(inp, ink, fill, label), '_ink': ink, '_fill': fill, '_label': label})
        else:
            rec.update({'override': False, 'after': before, 'danger': hexs(inp['danger']),
                        'danger_fill': hexs(inp['danger_fill']), 'on_danger': hexs(inp['on_danger'] or (255, 255, 255))})
        results['/'.join(k for k in key if k)] = rec

    # ---- the map: base theme blocks, then flavor blocks that differ from their base ----
    # A flavor selector (html + 3 attributes) always outranks its theme selector (html + 2), and both outrank the
    # default tokens on :root (resources/sass/member/_shared.scss, in the main CSS): the order of the rules does not
    # matter. App\Support\Member\DangerMap prints a look's rules (both modes: the mode switches without a reload).
    base_override = {}
    pairs = []
    for name, rec in sorted(results.items()):
        if rec['flavor'] or rec.get('error'):
            continue
        base_override[(rec['theme'], rec['mode'])] = rec
        if rec['override']:
            body = css_neutral('', rec['mode']) if rec.get('strategy') == 'neutral' else css_block('', rec['_ink'], rec['_fill'], rec['_label'])
            pairs.append((rec['theme'] + '|' + rec['mode'], body))
    for name, rec in sorted(results.items()):
        if not rec['flavor'] or rec.get('error'):
            continue
        base = base_override.get((rec['theme'], rec['mode']))
        key = rec['theme'] + '/' + rec['flavor'] + '|' + rec['mode']
        if rec['override']:
            same = base and base['override'] and base.get('strategy') == rec.get('strategy') and (
                rec.get('strategy') == 'neutral' or (base['danger'] == rec['danger'] and base['danger_fill'] == rec['danger_fill']))
            if not same:
                body = css_neutral('', rec['mode']) if rec.get('strategy') == 'neutral' else css_block('', rec['_ink'], rec['_fill'], rec['_label'])
                pairs.append((key, body))
        elif base and base['override']:
            pairs.append((key, css_reset('')))
    bodies = []
    for _key, body in pairs:
        if body not in bodies:
            bodies.append(body)

    def php_string(text):
        return "'" + text.replace('\\', '\\\\').replace("'", "\\'") + "'"

    php = ['<?php', '', 'namespace App\\Support\\Member;', '', '/**',
           ' * GENERATED by scripts/member/danger_tokens.py (strategy ' + strategy + ') from resources/css/themes: do not edit by hand,',
           ' * run it again from the repository root after a theme change (python scripts/member/danger_tokens.py).',
           ' *',
           ' * The member danger tokens are the theme\'s own (the defaults on :root, resources/sass/member/_shared.scss) unless the',
           ' * theme\'s danger is within dE00 22 of its brand ink or fill; then ' + (
               'the NEUTRAL danger: the look\'s text colour, an\n'
               ' * inverse fill (label = the look\'s background), tints in the neutral channel; the icon and the word carry the\n'
               ' * meaning (OD-M11 option C).'
               if strategy == 'neutral' else
               'the nearest danger-family colour >= dE00 22\n'
               ' * from both, >= 4.5:1 as text, with a >= 4.5:1 label on the fill (OD-M11 option A).'),
           ' * BLOCKS: "theme|mode" (the theme\'s own block) or "theme/flavor|mode" (a colour style that differs from its theme)',
           ' * => the index of its declarations in BODIES. App\\Support\\Member\\DangerMap prints the rules of the page\'s own look',
           ' * next to the member CSS entry (R1-070: the whole map was 11.6 KB of that entry).',
           ' */',
           'final class DangerMapData',
           '{',
           '    public const string STRATEGY = ' + php_string(strategy) + ';',
           '',
           '    /** @var list<string> */',
           '    public const array BODIES = [']
    php += ['        ' + php_string(body[1:-1]) + ',' for body in bodies]
    php += ['    ];', '', '    /** @var array<string, int> */', '    public const array BLOCKS = [']
    php += ['        ' + php_string(key) + ' => ' + str(bodies.index(body)) + ',' for key, body in pairs]
    php += ['    ];', '}']
    write_text(os.path.join(args.root, 'app', 'Support', 'Member', 'DangerMapData.php'), '\n'.join(php) + '\n')

    # ---- report ----
    clean = {k: {kk: vv for kk, vv in v.items() if not kk.startswith('_')} for k, v in results.items()}
    for v in clean.values():
        for part in ('before', 'after'):
            if part in v:
                v[part] = {kk: (round(vv, 2) if isinstance(vv, float) else vv) for kk, vv in v[part].items()}
    overrides = [v for v in clean.values() if v.get('override')]
    errors = [k for k, v in clean.items() if v.get('error')]
    after_min = min(v['after']['min_brand_de'] for v in clean.values() if 'after' in v)
    for k in errors: print('ERROR', k, clean[k]['error'], clean[k]['brand_ink'], clean[k]['brand_fill'], clean[k]['theme_danger'])
    summary = {
        'blocks': len(clean), 'overridden': len(overrides), 'errors': errors,
        'before_lt20': sum(1 for v in clean.values() if v['before']['min_brand_de'] < BAR),
        'after_lt20': sum(1 for v in clean.values() if 'after' in v and v['after']['min_brand_de'] < BAR),
        'after_min_brand_de': round(after_min, 2),
        'after_min_ink_contrast': round(min(v['after']['ink_contrast_min'] for v in clean.values() if 'after' in v), 2),
        'after_min_label_on_fill': round(min(v['after']['label_on_fill'] for v in clean.values() if 'after' in v), 2),
        'after_min_fill_vs_primary_button': round(min((v['after']['fill_vs_primary_button'] or 99) for v in clean.values() if 'after' in v), 2),
    }
    if args.report:
        with open(args.report, 'w', encoding='utf-8') as fh:
            json.dump({'summary': summary, 'blocks': clean}, fh, indent=1)
    print('written app/Support/Member/DangerMapData.php; summary', json.dumps(summary))
    if not quiet:
        print(f"{'block':40} {'brand ink':9} {'danger':8} {'fill':8} | before ink/fill | after ink  fill | txt  lbl")
        for name, v in sorted(clean.items()):
            if not v.get('override') or 'after' not in v:
                continue
            a, b = v['after'], v['before']
            print(f"{name[:40]:40} {v['brand_ink']:9} {v['danger']:8} {v['danger_fill']:8} | {b['ink_vs_brand_ink']:5} {b['fill_vs_brand_fill']:5}   | "
                  f"{a['ink_vs_brand_ink']:5} {a['fill_vs_brand_fill']:5} | {a['ink_contrast_min']:4} {a['label_on_fill']:4}")


if __name__ == '__main__':
    main()
