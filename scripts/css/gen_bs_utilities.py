"""Bootstrap's responsive utilities, trimmed to what the site uses (1.2.3; perf-7, the main CSS shrinks by >= 60 KB).

Bootstrap's utility API prints every responsive utility at every breakpoint (.mt-xxl-5, .order-md-last, .text-sm-end,
...): ~61 KB of the render-blocking main CSS that no view or script names. This generator turns `responsive` off for the
utility groups whose breakpoint classes no source names at all; a group with even one breakpoint class in use keeps all
of them (their base classes stay in every group either way: an owner's page text may use .mt-3 or .text-center, which
the site's code also uses).

Sources: the views, scripts and PHP (app, config, database seeders) of the repository, the admin's own views and scripts
included (the old admin layout uses this CSS), and the upload and player files. A class counts as used when it appears
as a word, or a dynamic part follows its prefix (`d-{{ $bp }}-none`, `text-${x}`).

Writes resources/sass/_bootstrap-utilities.scss (imported by app.scss between Bootstrap's utilities map and its API),
with the line endings the file already has (CRLF in a Windows checkout that converts them, LF otherwise), so a run that
finds nothing new leaves it byte for byte as it was. tests/Unit/BootstrapUtilitiesTrimTest fails when a source names a
breakpoint class of a group that is off: re-run it then.

Needs the npm packages (npm install: bootstrap and sass, run through npx). Run it from the repository root:
  python scripts/css/gen_bs_utilities.py            write the file
  python scripts/css/gen_bs_utilities.py --check    print what the scan finds, write nothing
  option: [<root>] another checkout to read and write (default: this repository)
"""
import argparse
import os
import re
import subprocess
import sys
import tempfile

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

parser = argparse.ArgumentParser(description="Trim Bootstrap's responsive utility groups to the ones the site uses.")
parser.add_argument('root', nargs='?', default=REPO, help='the checkout to read and write (default: this repository)')
parser.add_argument('--check', action='store_true', help='print what the scan finds, write nothing')
args = parser.parse_args()

ROOT = args.root
COPIES = [ROOT]
CHECK = args.check
if not os.path.isdir(os.path.join(ROOT, 'node_modules', 'bootstrap', 'scss')):
    sys.exit('node_modules/bootstrap not found under ' + ROOT + ': run npm install first')


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


probe = r'''
@import "bootstrap/scss/functions";
@import "bootstrap/scss/variables";
@import "bootstrap/scss/variables-dark";
@import "bootstrap/scss/maps";
@import "bootstrap/scss/mixins";
@import "bootstrap/scss/utilities";
@each $key, $utility in $utilities {
  @if type-of($utility) == "map" and map-get($utility, responsive) {
    $values: map-get($utility, values);
    @if type-of($values) == "string" or type-of(nth($values, 1)) != "list" {
      $values: zip($values, $values);
    }
    $property: map-get($utility, property);
    $class: if(map-has-key($utility, class), map-get($utility, class), if(type-of($property) == "string", $property, nth($property, 1)));
    @each $bp, $min in $grid-breakpoints {
      @if $min != 0 {
        @each $k, $v in $values {
          $mod: if($k, if($class == "", "", "-") + $k, "");
          @debug "AVDU|#{$key}|#{$class}-#{$bp}#{$mod}";
        }
      }
    }
  }
}
'''
# the probe lives outside the repository (its imports resolve through --load-path=node_modules of the root)
tmp_dir = tempfile.mkdtemp(prefix='bs-utilities-probe-')
tmp = os.path.join(tmp_dir, 'bs_probe.scss')
open(tmp, 'w', encoding='utf-8').write(probe)
try:
    res = subprocess.run(['npx.cmd' if os.name == 'nt' else 'npx', 'sass', '--load-path=node_modules', '--no-source-map',
                          tmp], cwd=ROOT, capture_output=True, text=True, encoding='utf-8', errors='replace')
finally:
    os.remove(tmp)
    os.rmdir(tmp_dir)
groups = {}
for line in (res.stderr or '').splitlines():
    m = re.search(r'AVDU\|([^|]+)\|([^\s"]+)', line)
    if m:
        groups.setdefault(m.group(1), set()).add(m.group(2).replace('--', '-') if m.group(2).startswith('-') else m.group(2))
if not groups:
    sys.exit('the probe printed no utilities:\n' + (res.stderr or '')[:2000])

texts = []
for copy in COPIES:
    for base in ['resources/views', 'resources/js', 'resources/admin', 'app', 'config', 'database/seeders']:
        for dirpath, _d, names in os.walk(os.path.join(copy, base)):
            if os.sep + 'lang' in dirpath or '/lang' in dirpath.replace('\\', '/'):
                continue
            for n in names:
                if n.endswith(('.php', '.ts', '.tsx', '.js', '.vue', '.html')):
                    try:
                        texts.append(open(os.path.join(dirpath, n), encoding='utf-8', errors='replace').read())
                    except OSError:
                        pass
corpus = '\n'.join(texts)
words = set(re.findall(r'[A-Za-z0-9_-]+', corpus))
dynamic = set(m.group(1) for m in re.finditer(r'([A-Za-z][A-Za-z0-9_-]*-)(?:\{\{|\{!!|\$\{|[\'"]\s*[.+])', corpus))


def used(cls):
    return cls in words or any(cls.startswith(p) for p in dynamic)


keep, off = {}, {}
for key, classes in sorted(groups.items()):
    hits = sorted(c for c in classes if used(c))
    (keep if hits else off)[key] = hits or len(classes)

print(f'{len(groups)} responsive utility groups: {len(keep)} keep their breakpoint classes, {len(off)} lose them')
for key, hits in keep.items():
    print(f'  keep {key}: {", ".join(hits[:8])}{" ..." if len(hits) > 8 else ""}')
if CHECK:
    print('  off:', ', '.join(off))
    sys.exit(0)

scss = '''// Generated by scripts/css/gen_bs_utilities.py (1.2.3; perf-7). Do not edit: re-run it from the repository root
// (python scripts/css/gen_bs_utilities.py) when a view or script starts using a breakpoint class of one of the groups
// below (tests/Unit/BootstrapUtilitiesTrimTest fails until then). Bootstrap's utility API printed every responsive
// utility at every breakpoint (.mt-xxl-5, .order-md-last, ...): ~61 KB of the render-blocking main CSS that no source
// names. These groups print their base classes only (.mt-3, .text-center, .d-none stay); the groups whose breakpoint
// classes the site uses keep all of them.
$utilities: map-merge(
  $utilities,
  (
''' + ''.join(f'    "{key}": map-merge(map-get($utilities, "{key}"), (responsive: false)),\n' for key in off) + '''  )
);

// The breakpoint classes these groups no longer print (tests/Unit/BootstrapUtilitiesTrimTest reads this list):
''' + ''.join(f'// off {key}: {" ".join(sorted(groups[key]))}\n' for key in off)
write_text(os.path.join(ROOT, 'resources', 'sass', '_bootstrap-utilities.scss'), scss)
print('written resources/sass/_bootstrap-utilities.scss:', len(off), 'groups off')
