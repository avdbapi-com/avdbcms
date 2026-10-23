"""Package an already prepared production stage and verify it before publication."""
import hashlib
import json
import argparse
import zipfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('stage', type=Path)
parser.add_argument('output', type=Path)
parser.add_argument('--refresh-from', type=Path, help='Refresh changed source files in an already compressed candidate')
parser.add_argument('--source-list', type=Path,
                    help='JSON array or {"files": [...]} of additional source paths to refresh from the workspace')
args = parser.parse_args()
stage = args.stage.resolve()
output = args.output.resolve()
if not stage.is_relative_to((root / 'build/release').resolve()):
    raise RuntimeError('Stage must be inside this workspace release directory')
report_path = output.with_suffix(output.suffix + '.json')
if output.exists() or report_path.exists():
    raise RuntimeError('Refusing to overwrite an existing artifact before validation')

def excluded(name):
    parts = name.split('/')
    top, leaf = parts[0], parts[-1]
    return (
        top.startswith('chat-export')
        or top in {'.git', '.agents', '.tools', 'scratch', 'tests', 'scripts', 'build', 'node_modules', 'ops', 'deploy'}
        or (leaf.startswith('.env') and leaf != '.env.example')
        or leaf == '.user.ini' or name == 'storage/app/installed.json'
        or (name.startswith('database/install/') and leaf.endswith('.sql'))
        or (name.startswith(('storage/', 'bootstrap/cache/')) and leaf not in {'.gitkeep', '.gitignore'})
    )


def normalize_source_path(value):
    """Return a safe archive-relative source path from a JSON value."""
    if not isinstance(value, str) or not value.strip():
        raise RuntimeError('Source list entries must be non-empty strings')
    name = value.replace('\\', '/')
    while name.startswith('./'):
        name = name[2:]
    parts = name.split('/')
    if not name or name.startswith('/') or any(part in {'', '.', '..'} for part in parts):
        raise RuntimeError('Unsafe source list path: ' + value)
    if excluded(name):
        raise RuntimeError('Source list contains an excluded/private/runtime path: ' + name)
    return name


def read_source_list(path):
    payload = json.loads(path.resolve().read_text(encoding='utf-8-sig'))
    if isinstance(payload, list):
        entries = payload
    elif isinstance(payload, dict) and isinstance(payload.get('files'), list):
        entries = payload['files']
    else:
        raise RuntimeError('Source list must be a JSON array or an object with a files array')
    return {normalize_source_path(value) for value in entries}


def workspace_build_sources():
    """All currently built frontend files, including the manifest."""
    build_root = root / 'public/build'
    if not build_root.is_dir():
        raise RuntimeError('Missing current public/build directory')
    return {
        p.relative_to(root).as_posix()
        for p in build_root.rglob('*')
        if p.is_file()
    }


def validate_current_sources(sources):
    for name in sources:
        source = root / name
        if not source.is_file():
            raise RuntimeError('Current source file is missing: ' + name)
        if excluded(name):
            raise RuntimeError('Current source file is excluded/private/runtime: ' + name)


def write_current_source(archive, name):
    archive.writestr(name, (root / name).read_bytes(),
                     compress_type=zipfile.ZIP_DEFLATED, compresslevel=1)

installed = json.loads((stage / 'vendor/composer/installed.json').read_text(encoding='utf-8-sig'))
dev_names = {p['name'] for p in json.loads((stage / 'composer.lock').read_text(encoding='utf-8-sig'))['packages-dev']}
if {p['name'] for p in installed['packages']} & dev_names:
    raise RuntimeError('Development packages remain in the stage')

required = {'artisan', 'index.php', '.htaccess', '.env.example', 'public/index.php',
            'public/.htaccess', 'public/build/manifest.json', 'vendor/autoload.php', 'vendor/composer/installed.json',
            'README.md', 'COMMUNITY_SETUP_GUIDE.md'}
current_sources = set(json.loads((root / 'chat-export-2026-09-23/operations/count-fix-files.json').read_text(encoding='utf-8-sig')))
current_sources.update({'app/Support/Installer.php', 'app/Http/Controllers/Admin/XvidImportController.php',
                        'app/Console/Commands/RunScheduledXvidImports.php',
                        'app/Models/XvidImportSchedule.php', 'README.md', 'COMMUNITY_SETUP_GUIDE.md',
                        'resources/views/install/index.blade.php', 'public/build/manifest.json'})
if args.source_list:
    current_sources.update(read_source_list(args.source_list))
current_sources.update(workspace_build_sources())
validate_current_sources(current_sources)

obsolete_build = set()
if args.refresh_from:
    print(json.dumps({'phase': 'refreshing_source_files'}), flush=True)
    with zipfile.ZipFile(args.refresh_from) as original, zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=1, allowZip64=True) as archive:
        original_names = set(original.namelist())
        current_build = {name for name in current_sources if name.startswith('public/build/')}
        obsolete_build = {name for name in original_names if name.startswith('public/build/') and name not in current_build}
        written = set()
        for info in original.infolist():
            if excluded(info.filename) or info.filename in obsolete_build:
                continue
            if info.filename in current_sources:
                write_current_source(archive, info.filename)
            else:
                archive.writestr(info, original.read(info), compress_type=zipfile.ZIP_DEFLATED, compresslevel=1)
            written.add(info.filename)
        for name in sorted(current_sources - written):
            write_current_source(archive, name)
        print(json.dumps({'refreshed_files': len(current_sources & original_names),
                          'added_files': len(current_sources - original_names),
                          'removed_obsolete_build_files': len(obsolete_build)}), flush=True)
else:
    paths = [p for p in stage.rglob('*') if p.is_file() and not excluded(p.relative_to(stage).as_posix())]
    print(json.dumps({'phase': 'compressing', 'files': len(paths)}), flush=True)
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=1, allowZip64=True) as archive:
        for i, path in enumerate(paths):
            if path.is_symlink():
                raise RuntimeError('Unexpected symbolic link in stage')
            archive.write(path, path.relative_to(stage).as_posix())
            if i and i % 4000 == 0:
                print(json.dumps({'written_files': i}), flush=True)
with zipfile.ZipFile(output) as archive:
    names = set(archive.namelist())
    if len(names) != len(archive.namelist()):
        raise RuntimeError('Duplicate archive paths')
    if not required <= names or any(excluded(n) or n.startswith(('/', '../', './')) or '\\' in n for n in names):
        raise RuntimeError('Archive content boundary failed')
    if archive.testzip() is not None:
        raise RuntimeError('Archive CRC validation failed')
    if obsolete_build & names:
        raise RuntimeError('Superseded compiled frontend asset remains: ' + sorted(obsolete_build & names)[0])
    archived_installed = json.loads(archive.read('vendor/composer/installed.json'))
    if {p['name'] for p in archived_installed['packages']} & dev_names:
        raise RuntimeError('Development packages remain in the archive')
    for name in current_sources:
        if name not in names:
            raise RuntimeError('Archive is missing current source: ' + name)
        if archive.read(name) != (root / name).read_bytes():
            raise RuntimeError('Archive source mismatch: ' + name)
    for entry in json.loads(archive.read('public/build/manifest.json')).values():
        for name in [entry['file'], *entry.get('css', []), *entry.get('assets', [])]:
            if 'public/build/' + name not in names:
                raise RuntimeError('Missing compiled frontend asset')

result = {'passed': True, 'file_count': len(names), 'bytes': output.stat().st_size,
          'sha256': hashlib.file_digest(output.open('rb'), 'sha256').hexdigest(),
          'current_source_files_verified': len(current_sources), 'bundled_database_dump': False,
          'development_packages': False, 'private_chat_exports': False}
report_path.write_text(json.dumps(result, indent=2))
print(json.dumps(result), flush=True)
